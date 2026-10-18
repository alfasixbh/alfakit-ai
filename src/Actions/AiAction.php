<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Forms\Components\Hidden;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\AlfakitAi\Agents\AlfakitAgent;
use AlfaSix\AlfakitAi\Agents\StructuredAgent;
use AlfaSix\AlfakitAi\Agents\TextAgent;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Components\AiStream;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Exceptions\AiException;
use AlfaSix\AlfakitAi\Exceptions\AiNotConfiguredException;
use AlfaSix\AlfakitAi\Support\AiRunner;
use AlfaSix\AlfakitAi\Support\OutputSchema;
use AlfaSix\AlfakitAi\Support\PromptData;
use AlfaSix\AlfakitAi\Support\SetupNotice;
use AlfaSix\AlfakitAi\Support\StreamJob;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A one-line AI action. The answer is generated on the server when the modal opens and
 * shown for review; nothing is saved or filled unless the user submits.
 *
 * ```php
 * AiAction::make('summarize')
 *     ->prompt(fn (Ticket $record) => "Summarise this support ticket:\n\n{$record->body}")
 *
 * AiAction::make('draftReply')
 *     ->prompt(fn (Ticket $record) => "Draft a reply to: {$record->body}")
 *     ->schema([
 *         TextInput::make('subject'),
 *         Textarea::make('body'),
 *         Select::make('priority')->options(['low' => 'Low', 'high' => 'High']),
 *     ])
 *     ->action(fn (array $data, Ticket $record) => $record->replies()->create($data))
 * ```
 *
 * Without `schema()` the answer is text, shown with a copy button. With `schema()` the
 * fields are the structured output (types and options come from the fields) and the user
 * can edit them before submitting. `inputs()` adds fields above the answer that generate it
 * again when changed (a tone, a target language). As a field's hint or suffix action,
 * `fillField()` / `fillFields()` write the accepted answer into the form being edited.
 */
class AiAction extends Action
{
    /** The text answer's state path in the modal form. */
    public const RESULT = 'ai_result';

    /** An error to show inside the modal. */
    public const ERROR = 'ai_error';

    /** The stream token while an answer streams (`AiPlugin::streaming()`). */
    public const STREAM = 'ai_stream';

    protected string|Closure|null $prompt = null;

    protected string|Closure|null $aiInstructions = null;

    /** @var array<Component>|Closure */
    protected array|Closure $inputComponents = [];

    /** @var string|array<int|string, string>|null */
    protected string|array|null $aiProvider = null;

    protected ?string $aiModel = null;

    protected ?float $aiTemperature = null;

    protected ?int $aiMaxTokens = null;

    protected string|Closure|null $answerLanguage = null;

    protected ?Closure $attachmentsUsing = null;

    /** @var array<string, string>|true|null Output path => owner form path, or true for the same names. */
    protected array|bool|null $fillTargets = null;

    protected ?string $fillFieldPath = null;

    protected bool $fillsOwnField = false;

    protected bool|Closure $isGeneratedOnMount = true;

    protected bool|Closure $isEditable = false;

    protected bool|Closure|null $isStreamed = null;

    protected ?Closure $defaultHandler = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->icon('sparkles');
        $this->color('primary');
        $this->modalIcon('sparkles');
        $this->modalWidth(Width::TwoExtraLarge);
        $this->modalDescription(fn (): string => (string) __('alfakit-ai::ai.disclaimer'));
        $this->modalCancelActionLabel(fn (): string => $this->hasSubmit() ? (string) __('alfakit-ai::ai.actions.discard') : (string) __('alfakit-ai::ai.actions.close'));
        $this->modalSubmitActionLabel(fn (): string => $this->getFillTargets() !== null ? (string) __('alfakit-ai::ai.actions.accept') : (string) __('alfakit-ai::ai.actions.apply'));
        $this->modalSubmitAction(fn (): bool => $this->hasSubmit());
        $this->mountUsing(fn (Schema $form) => $this->mountAi($form));

        $this->defaultHandler = fn (array $data) => $this->fillOwner($data);
        $this->action($this->defaultHandler);
    }

    protected function getFeature(): AiFeature
    {
        return AiFeature::Actions;
    }

    // ---------------------------------------------------------------- Configuration

    /**
     * What to ask: a string or a closure receiving `$record`, `$data` (the `inputs()`),
     * `$state` / `$get` (as a field action) and typed dependencies.
     */
    public function prompt(string|Closure|null $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    /**
     * The system instructions (who the model is, the format to answer in).
     */
    public function instructions(string|Closure|null $instructions): static
    {
        $this->aiInstructions = $instructions;

        return $this;
    }

    /**
     * Fields above the answer that change it: the answer is generated again when one
     * changes (`Select::make('tone')->options([...])`). Their state is `$data` in `prompt()`.
     *
     * @param  array<Component>|Closure  $components
     */
    public function inputs(array|Closure $components): static
    {
        $this->inputComponents = $components;
        $this->form = null;

        return $this;
    }

    /**
     * @param  string|array<int|string, string>|null  $provider
     */
    public function provider(string|array|null $provider): static
    {
        $this->aiProvider = $provider;

        return $this;
    }

    /**
     * The AI model (`'gpt-4.1-mini'`). An Eloquent model class or a closure keeps
     * `Action::model()`'s meaning (the record model), so tables can still set it.
     */
    public function model(Closure|string|null $model): static
    {
        if ($model instanceof Closure || $model === null || (class_exists($model) && is_subclass_of($model, \Illuminate\Database\Eloquent\Model::class))) {
            return parent::model($model);
        }

        return $this->aiModel($model);
    }

    /**
     * The AI model, explicitly.
     */
    public function aiModel(?string $model): static
    {
        $this->aiModel = $model;

        return $this;
    }

    public function temperature(?float $temperature): static
    {
        $this->aiTemperature = $temperature;

        return $this;
    }

    public function maxTokens(?int $tokens): static
    {
        $this->aiMaxTokens = $tokens;

        return $this;
    }

    /**
     * The language of the answer (a locale such as `fr`); the panel's current locale by
     * default. `false`-y strings let the model choose.
     */
    public function language(string|Closure|null $locale): static
    {
        $this->answerLanguage = $locale;

        return $this;
    }

    /**
     * Files or images to send with the prompt (laravel/ai attachments):
     * `fn (Invoice $record) => [Document::fromStorage($record->pdf_path)]`.
     */
    public function attachments(?Closure $callback): static
    {
        $this->attachmentsUsing = $callback;

        return $this;
    }

    /**
     * Generate when the modal opens (default). Off, the user changes an input first.
     */
    public function generateOnMount(bool|Closure $condition = true): static
    {
        $this->isGeneratedOnMount = $condition;

        return $this;
    }

    /**
     * Show the text answer in an editable field instead of a read-only block.
     */
    public function editable(bool|Closure $condition = true): static
    {
        $this->isEditable = $condition;
        $this->form = null;

        return $this;
    }

    /**
     * Stream the text answer as it is written (default: `AiPlugin::streaming()`). Structured
     * answers (`schema()`) and answers with `attachments()` are never streamed.
     */
    public function stream(bool|Closure|null $condition = true): static
    {
        $this->isStreamed = $condition;
        $this->form = null;

        return $this;
    }

    /**
     * Whether the answer streams into the modal instead of being generated with it.
     */
    public function isStreamed(): bool
    {
        $plugin = AiPlugin::get();

        if ($plugin === null || $this->attachmentsUsing !== null || $this->hasStructuredOutput()) {
            return false;
        }

        return $this->isStreamed === null ? $plugin->isStreaming() : (bool) $this->evaluate($this->isStreamed);
    }

    /**
     * As a field's hint, prefix or suffix action: on "Accept", put the text answer into that
     * field, or into `$path` (relative to the field, like `$set()`).
     */
    public function fillField(?string $path = null): static
    {
        $this->fillsOwnField = $path === null;
        $this->fillFieldPath = $path;
        $this->fillTargets = [self::RESULT => $path ?? ''];
        $this->form = null;

        return $this;
    }

    /**
     * As a field (or `Actions` component) action with `schema()`: on "Accept", write the
     * output fields into the form being edited, by the same names or by a map
     * (`['summary' => 'excerpt']`). Paths are relative, like `$set()`.
     *
     * @param  array<string, string>|null  $map
     */
    public function fillFields(?array $map = null): static
    {
        $this->fillTargets = $map ?? true;
        $this->fillsOwnField = false;

        return $this;
    }

    /**
     * @return array<string, string>|true|null
     */
    public function getFillTargets(): array|bool|null
    {
        return $this->fillTargets;
    }

    protected function hasSubmit(): bool
    {
        return $this->fillTargets !== null || $this->action !== $this->defaultHandler;
    }

    // ---------------------------------------------------------------- Visibility

    public function isHidden(): bool
    {
        if (parent::isHidden()) {
            return true;
        }

        return ! (AiPlugin::get()?->canUse($this->getFeature()) ?? false);
    }

    // ---------------------------------------------------------------- Form

    public function hasStructuredOutput(): bool
    {
        return parent::getFormComponents() !== [];
    }

    /**
     * @return array<Component>
     */
    public function getInputComponents(): array
    {
        $components = $this->evaluate($this->inputComponents) ?? [];

        foreach (OutputSchema::fields($components) as $field) {
            $this->makeRegenerating($field);
        }

        return $components;
    }

    /**
     * The fields the answer fills: `schema()`, or the text answer.
     *
     * @return array<Component>
     */
    public function getOutputComponents(): array
    {
        if ($this->hasStructuredOutput()) {
            return parent::getFormComponents();
        }

        // While an answer streams, the stream shows it; the fields come back with it.
        $settled = static fn (Get $get): bool => blank($get(self::STREAM));

        if ($this->fillTargets !== null || (bool) $this->evaluate($this->isEditable)) {
            return [
                Textarea::make(self::RESULT)
                    ->label(__('alfakit-ai::ai.result'))
                    ->rows(8)
                    ->autosize()
                    ->columnSpanFull()
                    ->visible($settled)
                    ->required(fn (): bool => $this->fillTargets !== null),
            ];
        }

        return [
            Hidden::make(self::RESULT),
            TextEntry::make('ai_preview')
                ->label(__('alfakit-ai::ai.result'))
                ->getStateUsing(fn (Get $get): mixed => $get(self::RESULT))
                ->placeholder(__('alfakit-ai::ai.empty_result'))
                ->markdown()
                ->prose()
                ->copyable()
                ->copyMessage(__('alfakit-ai::ai.copied'))
                ->columnSpanFull()
                ->visible($settled),
        ];
    }

    public function getFormComponents(): array
    {
        return [
            ...array_filter([SetupNotice::callout($this->getFeature(), $this->aiProvider)]),
            ...$this->getInputComponents(),
            Hidden::make(self::ERROR),
            Callout::make(fn (Get $get): string => (string) $get(self::ERROR))
                ->danger()
                ->visible(fn (Get $get): bool => filled($get(self::ERROR))),
            ...($this->isStreamed() ? [AiStream::make(self::STREAM)->target(self::RESULT)->errorPath(self::ERROR)] : []),
            ...$this->getOutputComponents(),
        ];
    }

    /**
     * Inputs generate the answer again when they change (text inputs when they lose focus).
     */
    protected function makeRegenerating(Field $field): void
    {
        $isText = $field instanceof Textarea || $field instanceof \AlfaSix\Alfakit\Forms\Components\TextInput;

        $field->live(onBlur: $isText)->afterStateUpdated(function (Get $get, Set $set): void {
            $this->regenerate($get, $set);
        });
    }

    /**
     * The modal opened: fill the inputs' defaults and generate the first answer.
     */
    protected function mountAi(Schema $form): void
    {
        $form->fill();

        if (! (bool) $this->evaluate($this->isGeneratedOnMount)) {
            return;
        }

        try {
            $form->fill([...$form->getRawState(), ...($this->isStreamed() ? $this->startStream($form->getRawState()) : $this->generate($form->getRawState()))]);
        } catch (AiNotConfiguredException) {
            // The modal opens with the setup notice instead of an answer.
            return;
        } catch (AiException|AuthorizationException $exception) {
            Notification::make()->danger()->title(__('alfakit-ai::ai.errors.title'))->body($exception->getMessage())->send();

            throw new Halt;
        }
    }

    /**
     * An input changed in the open modal.
     */
    protected function regenerate(Get $get, Set $set): void
    {
        $inputs = [];

        foreach (array_keys(OutputSchema::fields($this->getInputComponents())) as $path) {
            $inputs[$path] = $get($path, isAbsolute: true);
        }

        try {
            $values = $this->isStreamed() ? [self::RESULT => null, ...$this->startStream($inputs)] : $this->generate($inputs);
            $set(self::ERROR, null, isAbsolute: true);
        } catch (AiNotConfiguredException) {
            return;
        } catch (AiException|AuthorizationException $exception) {
            $set(self::ERROR, $exception->getMessage(), isAbsolute: true);

            return;
        }

        foreach ($values as $path => $value) {
            $set($path, $value, isAbsolute: true);
        }
    }

    // ---------------------------------------------------------------- Generation

    /**
     * Ask the model and return the modal state to fill (the text answer, or the output
     * fields coerced to what each field accepts).
     *
     * @param  array<string, mixed>  $inputs
     *
     * @throws AiException|AuthorizationException
     *
     * @return array<string, mixed>
     */
    public function generate(array $inputs = []): array
    {
        $prompt = $this->resolvePrompt($inputs);

        if ($prompt === '') {
            return [];
        }

        $runner = app(AiRunner::class);
        $attachments = $this->attachmentsUsing === null ? [] : (array) ($this->evaluate($this->attachmentsUsing, ['data' => $inputs, 'inputs' => $inputs]) ?? []);

        if (! $this->hasStructuredOutput()) {
            $agent = $this->configureAgent(new TextAgent);
            $response = $runner->prompt($this->getFeature(), $agent, $prompt, $this->getName(), array_values($attachments));

            return [self::RESULT => trim((string) $response->text)];
        }

        $fields = OutputSchema::fields($this->getOutputComponents());
        $agent = $this->configureAgent(new StructuredAgent)
            ->withSchema(static fn (JsonSchema $schema): array => OutputSchema::build($schema, $fields));

        $response = $runner->prompt($this->getFeature(), $agent, $prompt, $this->getName(), array_values($attachments));
        $output = $response instanceof \Laravel\Ai\Responses\StructuredAgentResponse ? $response->toArray() : (array) json_decode((string) $response->text, true);

        return OutputSchema::coerce($fields, $output);
    }

    /**
     * Streamed answers: the modal state that starts streaming (a one-time token for the
     * prompt resolved now, with the record and inputs), or nothing without a prompt.
     *
     * @param  array<string, mixed>  $inputs
     *
     * @throws AiException|AuthorizationException
     *
     * @return array<string, mixed>
     */
    public function startStream(array $inputs = []): array
    {
        $prompt = $this->resolvePrompt($inputs);

        if ($prompt === '') {
            return [];
        }

        return [self::STREAM => $this->issueStream('text', $this->configureAgent(new TextAgent), $prompt, $this->getName())];
    }

    /**
     * A one-time stream token for `$prompt`, answered by `$agent`'s settings.
     *
     * @param  array<string, mixed>  $finish  how the server finishes the text
     *
     * @throws AiException|AuthorizationException
     */
    protected function issueStream(string $agentType, AlfakitAgent $agent, string $prompt, string $context, array $finish = []): string
    {
        $runner = app(AiRunner::class);
        $plugin = $runner->ensureCanUse($this->getFeature());

        if (! $runner->canAnswer($this->getFeature(), $agent, $plugin)) {
            throw AiNotConfiguredException::for($plugin);
        }

        return StreamJob::issue([
            'feature' => $this->getFeature()->value,
            'agent' => $agentType,
            'prompt' => $prompt,
            'context' => $context,
            'provider' => $agent->provider(),
            'model' => $agent->model(),
            'temperature' => $agent->temperature(),
            'maxTokens' => $agent->maxTokens(),
            'instructions' => $agent->instructions(),
            'finish' => $finish,
        ]);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    protected function resolvePrompt(array $inputs): string
    {
        $prompt = $this->evaluate($this->prompt, ['data' => $inputs, 'inputs' => $inputs]);

        return is_string($prompt) ? trim($prompt) : '';
    }

    /**
     * @template TAgent of AlfakitAgent
     *
     * @param  TAgent  $agent
     * @return TAgent
     */
    protected function configureAgent(AlfakitAgent $agent): AlfakitAgent
    {
        $plugin = AiPlugin::get();
        $provider = $this->aiProvider ?? $plugin?->getProvider($this->getFeature());
        $model = $this->aiModel ?? $plugin?->getModel($this->getFeature());

        $agent->using($provider, $model)
            ->withTemperature($this->aiTemperature)
            ->withMaxTokens($this->aiMaxTokens)
            ->withInstructions(PromptData::instructions($this->getSystemInstructions()));

        return $agent;
    }

    public function getSystemInstructions(): string
    {
        $instructions = $this->evaluate($this->aiInstructions);
        $instructions = is_string($instructions) && trim($instructions) !== '' ? trim($instructions) : $this->getDefaultInstructions();
        $language = $this->getAnswerLanguage();

        if ($language !== null) {
            $instructions .= "\n\nWrite the answer in this language (locale {$language}) unless the request says otherwise.";
        }

        if ($this->hasStructuredOutput()) {
            $instructions .= "\n\nAnswer with the requested fields only. Leave a field empty when the source does not support a value; never invent facts.";
        }

        return $instructions;
    }

    protected function getDefaultInstructions(): string
    {
        return 'You are a careful assistant inside a business admin panel. Answer concisely and accurately, using only the information provided. Plain text or simple Markdown (paragraphs, lists, bold); no headings unless asked.';
    }

    public function getAnswerLanguage(): ?string
    {
        if ($this->answerLanguage === null) {
            return app()->getLocale();
        }

        $language = $this->evaluate($this->answerLanguage);

        return is_string($language) && $language !== '' ? $language : null;
    }

    // ---------------------------------------------------------------- Accepting

    /**
     * "Accept": write the answer into the form being edited (field actions only).
     *
     * @param  array<string, mixed>  $data
     */
    protected function fillOwner(array $data): void
    {
        $component = $this->getFieldComponent();

        if ($this->fillTargets === null || $component === null) {
            return;
        }

        $set = $component->makeSetUtility();

        if ($this->fillsOwnField) {
            $path = $component->getStatePath();

            if ($path !== null) {
                $set($path, $data[self::RESULT] ?? null, isAbsolute: true);
            }

            return;
        }

        $outputs = array_keys(OutputSchema::fields($this->getOutputComponents()));
        $targets = $this->fillTargets === true ? array_combine($outputs, $outputs) : $this->fillTargets;

        foreach ($targets as $output => $target) {
            if (array_key_exists($output, $data) && $target !== '') {
                $set($target, $data[$output]);
            }
        }
    }
}
