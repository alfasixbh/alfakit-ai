<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Actions;

use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\AlfakitAi\Support\OutputSchema;
use AlfaSix\AlfakitAi\Support\PromptData;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

/**
 * Paste text or drop a file (PDF, image, text) and let the model fill the form being
 * edited: the extracted values are shown for review and written into the form on
 * "Accept" (the record is only saved when the user saves the form).
 *
 * ```php
 * Actions::make([
 *     ExtractIntoFieldsAction::make()->fields(['name', 'email', 'company', 'country']),
 * ]),
 * // or next to one field:
 * TextInput::make('name')->hintAction(ExtractIntoFieldsAction::make())
 * ```
 *
 * Without `fields()`, every text, number, date, select, toggle and tags field of the form
 * is offered. `schema()` sets the review fields yourself (filled by the same names).
 */
class ExtractIntoFieldsAction extends AiAction
{
    public const TEXT = 'source_text';

    public const FILE = 'source_file';

    /** Largest file sent to the model, in kilobytes. */
    public const MAX_FILE_KB = 10240;

    /** @var array<int, string>|null */
    protected ?array $targetFields = null;

    protected bool $acceptsFiles = true;

    public static function make(string $name = 'extract'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-ai::ai.prebuilt.extract'));
        $this->modalHeading(__('alfakit-ai::ai.prebuilt.extract'));
        $this->icon('scan-text');
        $this->generateOnMount(false);
        $this->fillFields();
        $this->inputs(fn (): array => array_values(array_filter([
            Textarea::make(self::TEXT)
                ->label(__('alfakit-ai::ai.extract.text'))
                ->helperText(__('alfakit-ai::ai.extract.text_help'))
                ->rows(5),
            $this->acceptsFiles ? FileUpload::make(self::FILE)
                ->label(__('alfakit-ai::ai.extract.file'))
                ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'text/plain', 'text/csv'])
                ->maxSize(self::MAX_FILE_KB)
                ->storeFiles(false) : null,
        ])));
        $this->prompt(fn (array $data): string => $this->buildPrompt($data));
        $this->attachments(fn (array $data): array => $this->fileAttachments($data));
    }

    /**
     * The form fields (state paths, relative like `$set()`) to fill.
     *
     * @param  array<int, string>|null  $fields
     */
    public function fields(?array $fields): static
    {
        $this->targetFields = $fields === null ? null : array_values($fields);
        $this->form = null;

        return $this;
    }

    public function files(bool $condition = true): static
    {
        $this->acceptsFiles = $condition;
        $this->form = null;

        return $this;
    }

    public function hasStructuredOutput(): bool
    {
        return true;
    }

    /**
     * Review fields mirroring the form's fields (labels, options and types).
     *
     * @return array<Component>
     */
    public function getOutputComponents(): array
    {
        $explicit = parent::hasStructuredOutput() ? parent::getOutputComponents() : null;

        if ($explicit !== null) {
            return $explicit;
        }

        $components = [];

        foreach ($this->getTargetFields() as $path => $field) {
            $mirror = self::mirror($field, $path);

            if ($mirror !== null) {
                $components[] = $mirror;
            }
        }

        return $components;
    }

    /**
     * The owner form's fields this action fills, by relative path.
     *
     * @return array<string, Field>
     */
    public function getTargetFields(): array
    {
        $component = $this->getFieldComponent();

        if ($component === null) {
            return [];
        }

        $fields = [];

        foreach ($component->getContainer()->getRootSchema()->getFlatFields() as $path => $field) {
            $path = (string) $path;

            if ($field === $component || $field->isDisabled() || str_contains($path, '.')) {
                continue;
            }

            if ($this->targetFields !== null && ! in_array($path, $this->targetFields, true)) {
                continue;
            }

            if (self::mirror($field, $path) !== null) {
                $fields[$path] = $field;
            }
        }

        return $fields;
    }

    public static function mirror(Field $field, string $path): ?Field
    {
        $label = $field->getLabel() ?? $path;
        $options = OutputSchema::options($field);

        $mirror = match (true) {
            $field instanceof Toggle, $field instanceof Checkbox => Toggle::make($path),
            $field instanceof DateTimePicker => DateTimePicker::make($path),
            $field instanceof DatePicker => DatePicker::make($path),
            $field instanceof TagsInput => TagsInput::make($path),
            $field instanceof CheckboxList => CheckboxList::make($path)->options($options ?? []),
            $field instanceof Select => Select::make($path)->options($options ?? [])->multiple($field->isMultiple()),
            $field instanceof RichEditor, $field instanceof Textarea => Textarea::make($path)->rows(3)->autosize(),
            $field instanceof TextInput => TextInput::make($path)->numeric($field->getInputType() === 'number'),
            default => null,
        };

        return $mirror?->label($label);
    }

    protected function fillOwner(array $data): void
    {
        $this->fillTargets = array_combine(array_keys($this->getTargetFieldsOrOutputs()), array_keys($this->getTargetFieldsOrOutputs()));

        parent::fillOwner($data);
    }

    /**
     * @return array<string, Field>
     */
    protected function getTargetFieldsOrOutputs(): array
    {
        return OutputSchema::fields($this->getOutputComponents());
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    protected function buildPrompt(array $inputs): string
    {
        $text = is_string($inputs[self::TEXT] ?? null) ? trim($inputs[self::TEXT]) : '';
        $hasFile = $this->resolveUpload($inputs) !== null;

        if ($text === '' && ! $hasFile) {
            return '';
        }

        $fields = OutputSchema::describeAll($this->getTargetFieldsOrOutputs());

        return "Extract values for these form fields from the source (pasted text and/or the attached file). Use the field options exactly when a field has options. Leave a field empty when the source does not state it.\n\n"
            .PromptData::wrap($fields, 'fields')."\n\n"
            .($text === '' ? '' : PromptData::wrap($text, 'source'));
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<int, mixed>
     */
    protected function fileAttachments(array $inputs): array
    {
        $upload = $this->resolveUpload($inputs);

        if ($upload === null || $upload->size() > self::MAX_FILE_KB * 1024) {
            return [];
        }

        $contents = (string) TemporaryUpload::disk()->get($upload->path());
        $mime = $upload->mimeType();

        return match (true) {
            str_starts_with($mime, 'image/') => [Image::fromBase64(base64_encode($contents), $mime)],
            default => [Document::fromString($contents, $mime)],
        };
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    protected function resolveUpload(array $inputs): ?TemporaryUpload
    {
        if (! $this->acceptsFiles) {
            return null;
        }

        $state = $inputs[self::FILE] ?? null;
        $keys = is_array($state) ? array_values($state) : [$state];

        foreach ($keys as $key) {
            if (is_string($key) && TemporaryUpload::isKey($key)) {
                return TemporaryUpload::find($key);
            }
        }

        return null;
    }
}
