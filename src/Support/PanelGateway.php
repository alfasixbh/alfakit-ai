<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\Exceptions\Cancel;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\ApiResource;
use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Bridge\AiCreateRecord;
use AlfaSix\AlfakitAi\Bridge\AiEditRecord;
use AlfaSix\AlfakitAi\Bridge\AiListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What the copilot's tools and the MCP server may do in a panel, with the panel's own
 * rules: only the plugin's exposed resources, their (tenant-scoped) queries, their policies
 * (`canViewAny`, `canView`, `canCreate`, `canEdit`), the fields the resource shows, the
 * table's record actions the user is authorised for, and the resource's own forms,
 * validation and page hooks for writes.
 */
class PanelGateway
{
    public function __construct(
        protected Panel $panel,
        protected AiPlugin $plugin,
    ) {}

    public static function current(): ?self
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $plugin = $panel === null ? null : AiPlugin::get($panel);

        return $panel === null || $plugin === null ? null : new self($panel, $plugin);
    }

    public function getPanel(): Panel
    {
        return $this->panel;
    }

    // ---------------------------------------------------------------- Resources

    /**
     * The resources the user may list, by slug.
     *
     * @return array<string, class-string<resource>>
     */
    public function resources(): array
    {
        $resources = [];

        foreach ($this->plugin->getResources($this->panel) as $resource) {
            // Nested resources are reached through their parent; API resources' records come
            // from a data source, not an Eloquent query, so the tools can't search or read them.
            if ($resource::getParentResource() !== null || is_subclass_of($resource, ApiResource::class)) {
                continue;
            }

            try {
                if (! $resource::canViewAny()) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $resources[$resource::getSlug()] = $resource;
        }

        return $resources;
    }

    /**
     * @return class-string<resource>|null
     */
    public function resource(string $slug): ?string
    {
        return $this->resources()[$slug] ?? null;
    }

    /**
     * The catalogue the model works from: slug, labels and the fields each resource shows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogue(): array
    {
        $catalogue = [];

        foreach ($this->resources() as $slug => $resource) {
            $catalogue[] = array_filter([
                'resource' => $slug,
                'label' => $resource::getModelLabel(),
                'plural' => $resource::getPluralModelLabel(),
                'fields' => RecordData::fieldNames($resource),
                'can_create' => $this->canCreate($resource) ?: null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []);
        }

        return $catalogue;
    }

    /**
     * @param  class-string<resource>  $resource
     */
    protected function canCreate(string $resource): bool
    {
        try {
            return $resource::hasPage('create') && $resource::canCreate();
        } catch (Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------- Reading

    /**
     * Records matching `$query` (by the resource's globally searchable attributes or title)
     * the user may view; the latest records when `$query` is empty.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, ?string $slug = null, int $limit = 10): array
    {
        $resources = $slug === null || $slug === '' ? $this->resources() : array_filter([$slug => $this->resource($slug)]);
        $limit = max(1, min(25, $limit));
        $results = [];

        foreach ($resources as $resourceSlug => $resource) {
            $builder = $resource::getGlobalSearchEloquentQuery();
            $attributes = $resource::getGloballySearchableAttributes();
            $term = trim($query);

            if ($term !== '') {
                if ($attributes === []) {
                    continue;
                }

                foreach (preg_split('/\s+/', $term) ?: [] as $word) {
                    $builder->where(static function (Builder $builder) use ($attributes, $word): void {
                        foreach ($attributes as $attribute) {
                            if (str_contains($attribute, '.')) {
                                $relation = substr($attribute, 0, (int) strrpos($attribute, '.'));
                                $column = substr($attribute, (int) strrpos($attribute, '.') + 1);
                                $builder->orWhereHas($relation, static fn (Builder $related) => $related->where($related->qualifyColumn($column), 'like', '%'.$word.'%'));
                            } else {
                                $builder->orWhere($builder->qualifyColumn($attribute), 'like', '%'.$word.'%');
                            }
                        }
                    });
                }
            } else {
                $builder->latest($builder->getModel()->getQualifiedKeyName());
            }

            foreach ($builder->limit($limit * 2)->get() as $record) {
                if (count($results) >= $limit * max(1, count($resources))) {
                    break;
                }

                if (! $resource::canView($record)) {
                    continue;
                }

                $results[] = array_filter([
                    'resource' => $resourceSlug,
                    'key' => $this->routeKey($resource, $record),
                    'title' => $resource::hasRecordTitle() ? $resource::getRecordTitle($record) : null,
                    'url' => $this->recordUrl($resource, $record),
                ], static fn (mixed $value): bool => $value !== null);
            }
        }

        return array_slice($results, 0, $limit * 3);
    }

    /**
     * A record the user may view, found through the resource's scoped query.
     *
     * @param  class-string<resource>  $resource
     */
    public function find(string $resource, mixed $key): ?Model
    {
        if (! is_scalar($key) || (string) $key === '') {
            return null;
        }

        $record = $resource::resolveRecordRouteBinding((string) $key);

        if (! $resource::canView($record)) {
            return null;
        }

        return $record;
    }

    /**
     * The record as a prompt may see it, with the actions the user may run on it.
     *
     * @param  class-string<resource>  $resource
     * @return array<string, mixed>
     */
    public function read(string $resource, Model $record, bool $withActions = true): array
    {
        $data = RecordData::for($record, $resource, Redactor::make($this->plugin));
        $data['key'] = $this->routeKey($resource, $record);
        $data['url'] = $this->recordUrl($resource, $record);

        if ($withActions) {
            $data['actions'] = array_values(array_map(static fn (Action $action): array => array_filter([
                'name' => $action->getName(),
                'label' => $action->getLabel(),
                'inputs' => self::describeActionForm($action) ?: null,
            ]), $this->actions($resource, $record)));
        }

        return $data;
    }

    /**
     * @param  class-string<resource>  $resource
     */
    public function routeKey(string $resource, Model $record): mixed
    {
        return $record->getAttribute($resource::getRecordRouteKeyName() ?? $record->getRouteKeyName());
    }

    /**
     * The best page for a record the user may open: view, else edit, else the list.
     *
     * @param  class-string<resource>  $resource
     */
    public function recordUrl(string $resource, Model $record): ?string
    {
        try {
            if ($resource::hasPage('view') && $resource::canView($record)) {
                return $resource::getUrl('view', ['record' => $record], panel: $this->panel);
            }

            if ($resource::hasPage('edit') && $resource::canEdit($record)) {
                return $resource::getUrl('edit', ['record' => $record], panel: $this->panel);
            }

            return $resource::hasPage('index') ? $resource::getUrl('index', panel: $this->panel) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether `$url` is a page of this panel (navigation only goes there).
     */
    public function isPanelUrl(string $url): bool
    {
        $panelUrl = rtrim(url($this->panel->getPath()), '/');
        $target = parse_url($url);
        $base = parse_url($panelUrl);

        if (! is_array($target) || ! is_array($base) || isset($target['user']) || ! in_array($target['scheme'] ?? 'http', ['http', 'https'], true)) {
            return false;
        }

        if (isset($target['host']) && $target['host'] !== ($base['host'] ?? null)) {
            return false;
        }

        $path = '/'.ltrim($target['path'] ?? '/', '/');
        $basePath = '/'.ltrim($base['path'] ?? '/', '/');

        return $basePath === '/' || $path === $basePath || str_starts_with($path, rtrim($basePath, '/').'/');
    }

    // ---------------------------------------------------------------- Actions

    /**
     * The record actions of the resource's table the user may run on `$record` now: not
     * links, not bulk-only, visible, authorised, without password confirmation, and allowed
     * by `AiPlugin::copilotActions()`.
     *
     * @param  class-string<resource>  $resource
     * @return array<string, Action>
     */
    public function actions(string $resource, Model $record): array
    {
        $page = $this->listPage($resource);
        $table = $page?->getTable();

        if ($table === null) {
            return [];
        }

        $actions = [];

        foreach ($table->getRecordActions() as $item) {
            foreach ($item instanceof ActionGroup ? $item->getFlatActions() : [$item] as $action) {
                if ($action instanceof BulkAction) {
                    continue;
                }

                $action->record($record)->recordKey($record->getKey());

                try {
                    if ($action->isLink() || ! $action->isVisible() || $action->isDisabled() || ! $action->isAuthorized() || $action->isPasswordConfirmationRequired()) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }

                if (! $this->plugin->allowsCopilotAction($action, $resource)) {
                    continue;
                }

                $actions[$action->getName()] = $action;
            }
        }

        return $actions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function describeActionForm(Action $action): array
    {
        try {
            return OutputSchema::describeAll(OutputSchema::fields($action->getFormComponents()));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Run a record action the user confirmed. Authorisation, visibility and the action's own
     * form validation are checked again; unspecified form fields keep the values the modal
     * would have opened with.
     *
     * @param  class-string<resource>  $resource
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, message: ?string, errors?: array<string, array<int, string>>}
     */
    public function runAction(string $resource, Model $record, string $name, array $data = []): array
    {
        $action = $this->actions($resource, $record)[$name] ?? null;

        if ($action === null) {
            return ['ok' => false, 'message' => (string) __('alfakit-ai::ai.copilot.action_unavailable')];
        }

        try {
            $form = $action->hasForm() ? $action->mountForm() : null;
            $state = $form?->getRawState() ?? [];
            unset($data[Schema::READ_ONLY_STATE_KEY]);
            $action->execute(data: [...$state, ...array_intersect_key($data, $form === null ? [] : $form->getFlatFields())]);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'message' => (string) __('alfakit-ai::ai.copilot.action_invalid'), 'errors' => $exception->errors()];
        } catch (Halt|Cancel) {
            return ['ok' => false, 'message' => (string) __('alfakit-ai::ai.copilot.action_halted')];
        }

        return ['ok' => true, 'message' => null];
    }

    // ---------------------------------------------------------------- Writing (MCP)

    /**
     * Create a record with the resource's create form, validation and hooks.
     *
     * @param  class-string<resource>  $resource
     * @param  array<string, mixed>  $input
     */
    public function create(string $resource, array $input): Model
    {
        abort_unless($resource::canCreate(), 403);

        $page = $this->createPage($resource);
        $form = $page->getForm();

        abort_if($form === null, 405);

        $form->applyClientState([...$form->getRawState(), ...$this->writable($form, $input)]);

        $call = static fn (string $method, array $arguments = []): mixed => (fn (): mixed => $this->{$method}(...$arguments))->call($page);

        $call('callHook', ['beforeValidate']);
        $data = $form->validate();
        $call('callHook', ['afterValidate']);
        $call('callHook', ['beforeCreate', [$data]]);
        $data = $call('mutateFormDataBeforeCreate', [$data]);

        $record = DB::transaction(static function () use ($call, $form, $data): Model {
            $record = $call('handleRecordCreation', [$data]);

            abort_unless($record instanceof Model, 500);

            $form->record($record)->saveRelationships();

            return $record;
        });

        (function () use ($record): void {
            $this->createdRecord = $record;
        })->call($page);

        $call('callHook', ['afterCreate']);

        return $record;
    }

    /**
     * Update a record with the resource's edit form (fields left out keep their values).
     *
     * @param  class-string<resource>  $resource
     * @param  array<string, mixed>  $input
     */
    public function update(string $resource, Model $record, array $input): Model
    {
        abort_unless($resource::canEdit($record), 403);

        $page = $this->editPage($resource, $record);
        $form = $page->getForm();

        abort_if($form === null, 405);

        $form->applyClientState([...$form->getRawState(), ...$this->writable($form, $input)]);

        $call = static fn (string $method, array $arguments = []): mixed => (fn (): mixed => $this->{$method}(...$arguments))->call($page);

        $call('callHook', ['beforeValidate']);
        $data = $form->validate();
        $call('callHook', ['afterValidate']);
        $call('callHook', ['beforeSave', [$data]]);
        $data = $call('mutateFormDataBeforeSave', [$data]);

        $updated = DB::transaction(static function () use ($call, $page, $form, $record, $data): Model {
            $updated = $call('handleRecordUpdate', [$record, $data]);
            $updated = $updated instanceof Model ? $updated : $record;

            (function () use ($updated): void {
                $this->record = $updated;
            })->call($page);

            $form->record($updated)->saveRelationships();

            return $updated;
        });

        $call('callHook', ['afterSave']);

        return $updated;
    }

    /**
     * The form fields a write may set (top-level paths of the resource's form).
     *
     * @return array<string, array<string, mixed>>
     */
    public function writableFields(string $resource, ?Model $record = null): array
    {
        try {
            $form = $record === null ? $this->createPage($resource)->getForm() : $this->editPage($resource, $record)->getForm();
        } catch (Throwable) {
            return [];
        }

        if ($form === null) {
            return [];
        }

        return OutputSchema::describeAll(array_filter(
            OutputSchema::fields($form->getComponents(true)),
            static fn (int|string $path): bool => ! str_contains((string) $path, '.'),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function writable(Schema $form, array $input): array
    {
        $writable = [];

        foreach (array_keys($form->getFlatFields()) as $path) {
            $writable[] = explode('.', (string) $path)[0];
        }

        $input = array_intersect_key($input, array_flip(array_unique($writable)));
        unset($input[Schema::READ_ONLY_STATE_KEY]);

        return $input;
    }

    // ---------------------------------------------------------------- Pages

    /**
     * @param  class-string<resource>  $resource
     */
    protected function listPage(string $resource): ?ListRecords
    {
        $class = $this->pageClass($resource, 'index', ListRecords::class);

        if ($class === null) {
            AiListRecords::usingResource($resource);
            $class = AiListRecords::class;
        }

        try {
            return $class::fromContext(new SchemaContext($this->panel->getId(), $class));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<resource>  $resource
     */
    protected function createPage(string $resource): CreateRecord
    {
        $class = $this->pageClass($resource, 'create', CreateRecord::class);

        if ($class === null) {
            AiCreateRecord::usingResource($resource);
            $class = AiCreateRecord::class;
        }

        return $class::fromContext(new SchemaContext($this->panel->getId(), $class));
    }

    /**
     * @param  class-string<resource>  $resource
     */
    protected function editPage(string $resource, Model $record): EditRecord
    {
        $class = $this->pageClass($resource, 'edit', EditRecord::class);

        if ($class === null) {
            AiEditRecord::usingResource($resource);
            $class = AiEditRecord::class;
        }

        return $class::fromContext(new SchemaContext($this->panel->getId(), $class, ['record' => $record]));
    }

    /**
     * @template TPage of object
     *
     * @param  class-string<resource>  $resource
     * @param  class-string<TPage>  $base
     * @return class-string<TPage>|null
     */
    protected function pageClass(string $resource, string $name, string $base): ?string
    {
        $registration = $resource::getPages()[$name] ?? null;

        if ($registration === null) {
            return null;
        }

        $class = $registration->page;

        return is_a($class, $base, true) ? $class : null;
    }
}
