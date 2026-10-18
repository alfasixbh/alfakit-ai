<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitAi\Agents\FilterAgent;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Exceptions\AiException;
use AlfaSix\AlfakitAi\Exceptions\AiNotConfiguredException;
use AlfaSix\AlfakitAi\Filters\FilterInterpreter;
use AlfaSix\AlfakitAi\Support\AiRunner;
use AlfaSix\AlfakitAi\Support\PromptData;
use AlfaSix\AlfakitAi\Support\SetupNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\RedirectResponse;

/**
 * "Ask AI" in a table's header (`Table::aiFilters()`): the user describes what to see
 * ("orders over 100 from last week") and the table opens with the matching filters, search
 * and sort applied as ordinary filters, which the user can see, edit and clear.
 */
class AiFilterAction extends Action
{
    public const QUERY = 'query';

    public static function make(string $name = 'aiFilters'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-ai::ai.filters.label'));
        $this->icon('sparkles');
        $this->outlined();
        $this->modalHeading(__('alfakit-ai::ai.filters.heading'));
        $this->modalDescription(__('alfakit-ai::ai.filters.description'));
        $this->modalIcon('sparkles');
        $this->modalWidth(Width::Large);
        $this->modalSubmitActionLabel(__('alfakit-ai::ai.filters.apply'));
        $this->keyBindings(['mod+shift+f']);
        $this->schema(fn (): array => [
            ...array_filter([SetupNotice::callout(AiFeature::Filters)]),
            TextInput::make(self::QUERY)
                ->hiddenLabel()
                ->placeholder(__('alfakit-ai::ai.filters.placeholder'))
                ->required()
                ->maxLength(500)
                ->autofocus(),
        ]);
        $this->action(fn (array $data): ?RedirectResponse => $this->applyQuery((string) ($data[self::QUERY] ?? '')));
    }

    public function isHidden(): bool
    {
        if (parent::isHidden()) {
            return true;
        }

        return ! (AiPlugin::get()?->canUse(AiFeature::Filters) ?? false) || $this->getTable() === null;
    }

    public function getTable(): ?Table
    {
        if ($this->selectionTable instanceof Table) {
            return $this->selectionTable;
        }

        $context = $this->getContext();

        if ($context instanceof Table) {
            return $context;
        }

        if (is_object($context) && method_exists($context, 'getTable')) {
            $table = $context->getTable();

            return $table instanceof Table ? $table : null;
        }

        return null;
    }

    /**
     * Ask the model, validate its answer and send the user to the filtered table.
     */
    public function applyQuery(string $query): ?RedirectResponse
    {
        $table = $this->getTable();

        abort_if($table === null, 404);

        $interpreter = FilterInterpreter::for($table);
        $description = $interpreter->describe();

        $agent = (new FilterAgent)
            ->withSchema(static fn (JsonSchema $schema): array => FilterInterpreter::schema($schema))
            ->withInstructions(PromptData::instructions(
                "You translate a user's request into the filters of one data table. Use ONLY the filters, fields, query builder constraints, operators, options and sortable columns described in the table data, with their exact names and option keys. Prefer the table's filters; use query builder rules for conditions the filters cannot express. Dates are YYYY-MM-DD in the table's timezone; resolve relative dates from \"today\". If the request cannot be expressed, set unsupported to true and leave everything else empty. Never write SQL.",
            ));

        $prompt = "Table:\n".PromptData::wrap($description, 'table')."\n\nRequest:\n".PromptData::wrap(trim($query), 'request');

        try {
            $answer = app(AiRunner::class)->structured(AiFeature::Filters, $agent, $prompt, 'table:'.($table->getQueryStringIdentifier() ?? 'table'));
        } catch (AiNotConfiguredException $exception) {
            Notification::make()->warning()->title($exception->getMessage())->send();

            throw new Halt;
        } catch (AiException|AuthorizationException $exception) {
            Notification::make()->danger()->title(__('alfakit-ai::ai.errors.title'))->body($exception->getMessage())->send();

            throw new Halt;
        }

        $explanation = is_string($answer['explanation'] ?? null) ? trim($answer['explanation']) : '';

        if (($answer['unsupported'] ?? false) === true) {
            Notification::make()->warning()->title(__('alfakit-ai::ai.filters.unsupported'))->body($explanation !== '' ? mb_substr($explanation, 0, 300) : null)->send();

            throw new Halt;
        }

        $state = $interpreter->interpret($answer);

        if ($state === null) {
            Notification::make()
                ->danger()
                ->title(__('alfakit-ai::ai.filters.rejected'))
                ->body(implode("\n", $interpreter->getErrors()))
                ->send();

            throw new Halt;
        }

        Notification::make()
            ->success()
            ->title(__('alfakit-ai::ai.filters.applied'))
            ->body($explanation !== '' ? mb_substr($explanation, 0, 300) : null)
            ->send();

        return redirect()->to($this->buildUrl($table, $state));
    }

    /**
     * The page the table is on, with the new state replacing its search, sort and filters
     * (other state, such as the active tab, is kept).
     *
     * @param  array{search: ?string, sort: ?string, direction: ?string, filters: array<string, array<string, mixed>>}  $state
     */
    public function buildUrl(Table $table, array $state): string
    {
        $base = $this->getPageUrl();
        $path = strtok($base, '?') ?: $base;
        parse_str((string) parse_url($base, PHP_URL_QUERY), $query);

        $identifier = $table->getQueryStringIdentifier();
        $key = static fn (string $name): string => $identifier === null ? $name : "{$identifier}_{$name}";

        foreach (['search', 'sort', 'direction', 'filters', 'page', 'cursor'] as $name) {
            unset($query[$key($name)]);
        }

        if ($state['search'] !== null) {
            $query[$key('search')] = $state['search'];
        }

        if ($state['sort'] !== null) {
            $query[$key('sort')] = $state['sort'];
            $query[$key('direction')] = $state['direction'] ?? 'asc';
        }

        if ($state['filters'] !== []) {
            $query[$key('filters')] = $state['filters'];
        } else {
            // An explicit empty value, so default filters don't come back.
            $query[$key('filters')] = '';
        }

        $string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $string === '' ? $path : $path.'?'.$string;
    }

    /**
     * The URL of the page the action was run from (same host only), else the resource's
     * list page.
     */
    protected function getPageUrl(): string
    {
        $referer = request()->headers->get('referer');

        if (is_string($referer) && $referer !== '' && parse_url($referer, PHP_URL_HOST) === request()->getHost()) {
            return $referer;
        }

        $context = $this->getContext();

        if (is_object($context) && method_exists($context, 'getResource')) {
            $resource = $context->getResource();

            if (is_string($resource) && method_exists($resource, 'getUrl')) {
                return (string) $resource::getUrl('index');
            }
        }

        return url()->previous();
    }
}
