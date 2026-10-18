<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Actions;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\AlfakitAi\Actions\Concerns\HasSource;
use AlfaSix\AlfakitAi\Support\OutputSchema;
use AlfaSix\AlfakitAi\Support\PromptData;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pick one value from a fixed list (an enum or options) for a record or a text, with a
 * short reason. The answer can only be one of the options.
 *
 * ```php
 * ClassifyAction::make()->enum(TicketCategory::class)->source(fn (Ticket $record) => $record->body)->saveTo('category')
 * Select::make('category')->options([...])->hintAction(ClassifyAction::make()->fillField())   // the Select's options
 * ```
 */
class ClassifyAction extends AiAction
{
    use HasSource;

    public const VALUE = 'value';

    public const REASON = 'reason';

    /** @var array<string, string>|Closure|null */
    protected array|Closure|null $classOptions = null;

    protected ?string $saveAttribute = null;

    protected string|Closure|null $criteria = null;

    public static function make(string $name = 'classify'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-ai::ai.prebuilt.classify'));
        $this->modalHeading(__('alfakit-ai::ai.prebuilt.classify'));
        $this->icon('tags');
        $this->prompt(fn (array $data): string => $this->buildPrompt($data));
    }

    /**
     * @param  array<string, string>|Closure|null  $options
     */
    public function options(array|Closure|null $options): static
    {
        $this->classOptions = $options;
        $this->form = null;

        return $this;
    }

    /**
     * @param  class-string<UnitEnum>  $enum
     */
    public function enum(string $enum): static
    {
        return $this->options(static function () use ($enum): array {
            $options = [];

            foreach ($enum::cases() as $case) {
                $key = $case instanceof BackedEnum ? (string) $case->value : $case->name;
                $options[$key] = method_exists($case, 'getLabel') ? (string) $case->getLabel() : $case->name;
            }

            return $options;
        });
    }

    /**
     * How to decide (`'By the urgency the customer describes'`).
     */
    public function criteria(string|Closure|null $criteria): static
    {
        $this->criteria = $criteria;

        return $this;
    }

    /**
     * On "Apply", save the value to this attribute of the record (the user must be allowed
     * to edit it).
     */
    public function saveTo(?string $attribute): static
    {
        $this->saveAttribute = $attribute;

        if ($attribute !== null) {
            $this->action(function (array $data): void {
                $record = $this->getRecord();

                if (! $record instanceof Model) {
                    return;
                }

                $resource = (alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel())?->getModelResource($record);

                abort_unless($resource === null ? \Illuminate\Support\Facades\Gate::allows('update', $record) : $resource::canEdit($record), 403);

                $record->forceFill([(string) $this->saveAttribute => $data[self::VALUE] ?? null])->save();
            });
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getClassOptions(): array
    {
        $options = $this->evaluate($this->classOptions);

        if (! is_array($options) || $options === []) {
            $component = $this->getFieldComponent();
            $options = $component instanceof \AlfaSix\Alfakit\Forms\Components\Field ? (OutputSchema::options($component) ?? []) : [];
        }

        $normalized = [];

        foreach ($options as $key => $label) {
            $normalized[(string) $key] = is_scalar($label) ? (string) $label : (string) $key;
        }

        return $normalized;
    }

    public function hasStructuredOutput(): bool
    {
        return true;
    }

    /**
     * @return array<Component>
     */
    public function getOutputComponents(): array
    {
        return [
            Select::make(self::VALUE)
                ->label(__('alfakit-ai::ai.classify.value'))
                ->options($this->getClassOptions())
                ->required(),
            Textarea::make(self::REASON)
                ->label(__('alfakit-ai::ai.classify.reason'))
                ->rows(2)
                ->disabled()
                ->dehydrated(false),
        ];
    }

    public function fillField(?string $path = null): static
    {
        parent::fillField($path);
        $this->fillTargets = [self::VALUE => $path ?? ''];

        return $this;
    }

    protected function fillOwner(array $data): void
    {
        if ($this->fillsOwnField) {
            $data[self::RESULT] = $data[self::VALUE] ?? null;
        }

        parent::fillOwner($data);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    protected function buildPrompt(array $inputs): string
    {
        $options = $this->getClassOptions();
        $component = $this->getFieldComponent();
        $source = $this->sourceUsing !== null || $component === null ? $this->getSource($inputs) : $this->getRecordOrOwnerSource();

        if ($options === [] || self::isBlankSource($source)) {
            return '';
        }

        $criteria = $this->evaluate($this->criteria);

        return 'Classify the content below into exactly one of the options (use the option key as "value") and give a one-sentence reason.'
            .(is_string($criteria) && $criteria !== '' ? " Criteria: {$criteria}" : '')
            ."\n\n".PromptData::wrap($options, 'options')."\n\n".PromptData::wrap($source, 'source');
    }

    /**
     * As a field action the field holds the answer, so the rest of the form is the source.
     *
     * @return array<string, mixed>
     */
    protected function getRecordOrOwnerSource(): array
    {
        $component = $this->getFieldComponent();
        $state = $component?->getContainer()->getRootSchema()->getRawState() ?? [];
        $path = $component?->getStatePath();

        if ($path !== null) {
            unset($state[$path]);
        }

        return \AlfaSix\AlfakitAi\Support\Redactor::make()->redact(array_filter($state, static fn (mixed $value): bool => is_scalar($value) || is_array($value)), $this->getRecord() instanceof Model ? $this->getRecord() : null);
    }
}
