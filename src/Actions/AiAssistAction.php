<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Actions;

use AlfaSix\Alfakit\Forms\Components\Hidden;
use AlfaSix\Alfakit\Forms\Components\MarkdownEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\AlfakitAi\Agents\AssistAgent;
use AlfaSix\AlfakitAi\Components\AiStream;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Exceptions\AiException;
use AlfaSix\AlfakitAi\Exceptions\AiNotConfiguredException;
use AlfaSix\AlfakitAi\Support\AiRunner;
use AlfaSix\AlfakitAi\Support\PromptData;
use AlfaSix\AlfakitAi\Support\SetupNotice;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The writing assistant behind `->aiAssist()` on `TextInput`, `Textarea`, `RichEditor` and
 * `MarkdownEditor`: a hint action whose modal offers generate (from instructions), shorter,
 * longer, friendlier, more formal, fix grammar, translate and continue writing. Each button
 * produces a suggestion (working on the previous suggestion, so they chain); the field only
 * changes when the user clicks "Use suggestion".
 */
class AiAssistAction extends AiAction
{
    public const TASK = 'ai_task';

    public const LAST_TASK = 'ai_last_task';

    public const INSTRUCTIONS = 'ai_instructions';

    public const LOCALE = 'ai_locale';

    public const SUGGESTION = self::RESULT;

    public const TASKS = ['generate', 'shorter', 'longer', 'friendlier', 'formal', 'grammar', 'translate', 'continue'];

    /** The first line of each task's prompt (sample answers recognise the task by it). */
    public const REQUESTS = [
        'generate' => 'Write new text for the field following the instructions.',
        'shorter' => 'Rewrite the text to be noticeably shorter while keeping the key information.',
        'longer' => 'Rewrite the text to be more detailed and complete, without inventing facts.',
        'friendlier' => 'Rewrite the text in a warmer, friendlier tone.',
        'formal' => 'Rewrite the text in a more formal, professional tone.',
        'grammar' => 'Fix spelling, grammar and punctuation only; change nothing else.',
        'translate' => 'Translate the text into',
        'continue' => 'Continue writing the text from where it ends, in the same style. Answer with the continuation only.',
    ];

    /** @var array<int, string> */
    protected array $tasks = self::TASKS;

    public static function make(string $name = 'aiAssist'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-ai::ai.assist.label'));
        $this->tooltip(__('alfakit-ai::ai.assist.label'));
        $this->hiddenLabel();
        $this->iconButton();
        $this->modalHeading(__('alfakit-ai::ai.assist.heading'));
        $this->modalSubmitActionLabel(__('alfakit-ai::ai.assist.accept'));
        $this->generateOnMount(false);
        $this->fillField();
    }

    protected function getFeature(): AiFeature
    {
        return AiFeature::Assist;
    }

    /**
     * Limit the buttons: `->aiAssist(['shorter', 'grammar', 'translate'])`.
     *
     * @param  array<int, string>  $tasks
     */
    public function tasks(array $tasks): static
    {
        $this->tasks = array_values(array_intersect(self::TASKS, $tasks)) ?: self::TASKS;
        $this->form = null;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    public function isRich(): bool
    {
        $component = $this->getFieldComponent();

        return $component instanceof RichEditor && ! $component instanceof MarkdownEditor;
    }

    public function getFormComponents(): array
    {
        $options = [];
        $icons = [
            'generate' => 'wand-sparkles', 'shorter' => 'minimize-2', 'longer' => 'maximize-2', 'friendlier' => 'smile',
            'formal' => 'briefcase', 'grammar' => 'spell-check', 'translate' => 'languages', 'continue' => 'pen-line',
        ];

        foreach ($this->tasks as $task) {
            $options[$task] = (string) __('alfakit-ai::ai.assist.tasks.'.$task);
        }

        $translate = TranslateAction::make();
        $component = $this->getFieldComponent();
        $suggestion = $this->isRich()
            ? RichEditor::make(self::SUGGESTION)
            : ($component instanceof MarkdownEditor ? MarkdownEditor::make(self::SUGGESTION) : Textarea::make(self::SUGGESTION)->rows(6)->autosize());

        return [
            ...array_filter([SetupNotice::callout(AiFeature::Assist, $this->aiProvider)]),
            Textarea::make(self::INSTRUCTIONS)
                ->label(__('alfakit-ai::ai.assist.instructions'))
                ->placeholder(__('alfakit-ai::ai.assist.instructions_placeholder'))
                ->rows(2)
                ->autosize(),
            in_array('translate', $this->tasks, true)
                ? Select::make(self::LOCALE)
                    ->label(__('alfakit-ai::ai.translate.to'))
                    ->options($translate->getLocaleOptions())
                    ->default($translate->getDefaultLocale())
                    ->native(false)
                : Hidden::make(self::LOCALE),
            // Buttons, not a choice: the selection is cleared once the suggestion is made, so
            // the same task can run again (on the new suggestion).
            ToggleButtons::make(self::TASK)
                ->hiddenLabel()
                ->options($options)
                ->icons(array_intersect_key($icons, $options))
                ->inline()
                ->live()
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                    if (is_string($state) && $state !== '') {
                        $this->runTask($state, $get, $set);
                    }
                }),
            Hidden::make(self::LAST_TASK),
            Hidden::make(self::ERROR),
            Callout::make(fn (Get $get): string => (string) $get(self::ERROR))
                ->danger()
                ->visible(fn (Get $get): bool => filled($get(self::ERROR))),
            ...($this->isStreamed() ? [AiStream::make(self::STREAM)->target(self::SUGGESTION)->errorPath(self::ERROR)] : []),
            $suggestion
                ->columnSpanFull()
                ->visible(static fn (Get $get): bool => blank($get(self::STREAM)))
                ->label(fn (Get $get): string => filled($get(self::LAST_TASK)) && in_array($get(self::LAST_TASK), self::TASKS, true)
                    ? (string) __('alfakit-ai::ai.assist.suggestion_for', ['task' => __('alfakit-ai::ai.assist.tasks.'.$get(self::LAST_TASK))])
                    : (string) __('alfakit-ai::ai.assist.suggestion'))
                ->helperText(__('alfakit-ai::ai.assist.suggestion_help'))
                ->required(),
        ];
    }

    protected function mountAi(Schema $form): void
    {
        $form->fill();
    }

    /**
     * Run one task and put the suggestion into the modal.
     */
    public function runTask(string $task, Get $get, Set $set): void
    {
        $set(self::TASK, null, isAbsolute: true);

        if (! in_array($task, $this->tasks, true)) {
            return;
        }

        $current = $get(self::SUGGESTION, isAbsolute: true);
        $source = is_string($current) && trim(strip_tags($current)) !== '' ? $current : $this->getFieldText();
        $instructions = $get(self::INSTRUCTIONS, isAbsolute: true);
        $instructions = is_string($instructions) ? trim($instructions) : '';
        $locale = $get(self::LOCALE, isAbsolute: true);

        if ($task === 'generate' && $instructions === '') {
            $set(self::ERROR, (string) __('alfakit-ai::ai.assist.needs_instructions'), isAbsolute: true);

            return;
        }

        if ($task !== 'generate' && trim(strip_tags($source)) === '') {
            $set(self::ERROR, (string) __('alfakit-ai::ai.assist.needs_text'), isAbsolute: true);

            return;
        }

        if ($this->isStreamed()) {
            try {
                [$prompt, $agent] = $this->suggestion($task, $source, $instructions, is_string($locale) ? $locale : null);
                $token = $this->issueStream('assist', $agent, $prompt, $this->getName().':'.$task, [
                    'type' => 'assist',
                    'task' => $task,
                    'source' => $source,
                    'format' => $this->getTextFormat(),
                ]);
            } catch (AiNotConfiguredException) {
                return;
            } catch (AiException|AuthorizationException $exception) {
                $set(self::ERROR, $exception->getMessage(), isAbsolute: true);

                return;
            }

            $set(self::ERROR, null, isAbsolute: true);
            $set(self::LAST_TASK, $task, isAbsolute: true);
            $set(self::STREAM, $token, isAbsolute: true);

            return;
        }

        try {
            $text = $this->suggest($task, $source, $instructions, is_string($locale) ? $locale : null);
        } catch (AiNotConfiguredException) {
            // The setup notice at the top of the modal says why.
            return;
        } catch (AiException|AuthorizationException $exception) {
            $set(self::ERROR, $exception->getMessage(), isAbsolute: true);

            return;
        }

        $set(self::ERROR, null, isAbsolute: true);
        $set(self::LAST_TASK, $task, isAbsolute: true);
        $set(self::SUGGESTION, $text, isAbsolute: true);
    }

    /**
     * Ask the model for one task: the suggestion, ready for the field (a continuation
     * includes the text it continues).
     *
     * @throws AiException|AuthorizationException
     */
    public function suggest(string $task, string $source, string $instructions = '', ?string $locale = null): string
    {
        [$prompt, $agent] = $this->suggestion($task, $source, $instructions, $locale);

        $response = app(AiRunner::class)->prompt(AiFeature::Assist, $agent, $prompt, $this->getName().':'.$task);

        return static::finishSuggestion((string) $response->text, $task, $source, $this->getTextFormat());
    }

    /**
     * The prompt and agent of one task.
     *
     * @return array{0: string, 1: AssistAgent}
     */
    protected function suggestion(string $task, string $source, string $instructions = '', ?string $locale = null): array
    {
        $component = $this->getFieldComponent();
        $label = $component?->getLabel() ?? $component?->getKey() ?? 'text';
        $format = $this->isRich()
            ? 'The text is HTML from a rich text editor: keep simple HTML (p, strong, em, a, ul, ol, li, blockquote) and answer with HTML only.'
            : ($component instanceof MarkdownEditor ? 'The text is Markdown: answer in Markdown.' : 'Answer with plain text only, without quotes or commentary.');

        $request = $task === 'translate'
            ? self::REQUESTS['translate'].' '.TranslateAction::languageName($locale ?: 'en').' (locale '.($locale ?: 'en').').'
            : self::REQUESTS[$task] ?? self::REQUESTS['continue'];

        $prompt = $request
            ."\nThe field is \"{$label}\". {$format}"
            .($instructions !== '' && $task !== 'generate' ? "\nAlso follow these instructions from the user: {$instructions}" : '')
            .($task === 'generate' ? "\n\nInstructions from the user:\n".PromptData::wrap($instructions, 'instructions') : '')
            .(trim(strip_tags($source)) !== '' ? "\n\n".PromptData::wrap($source, $task === 'generate' ? 'current_text' : 'text') : '');

        $agent = $this->configureAgent(new AssistAgent)
            ->withInstructions(PromptData::instructions('You are a writing assistant inside a business admin panel. You return only the requested text, ready to paste into the field.'
                .($task === 'translate' ? '' : "\nWrite in the language of the text (or of the instructions when there is no text).")));

        return [$prompt, $agent];
    }

    /**
     * `rich` (HTML, sanitised), `markdown` or `plain`.
     */
    public function getTextFormat(): string
    {
        return $this->isRich() ? 'rich' : ($this->getFieldComponent() instanceof MarkdownEditor ? 'markdown' : 'plain');
    }

    /**
     * Clean up a model's answer for the field (streamed or not): code fences and quotes go,
     * HTML is sanitised, and a continuation is appended to the text it continues.
     */
    public static function finishSuggestion(string $text, string $task, string $source, string $format): string
    {
        $text = trim($text);

        // Models sometimes wrap answers in code fences or quotes.
        $text = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
        $rich = $format === 'rich';

        if ($rich) {
            $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
            $text = \AlfaSix\Alfakit\Support\Html::sanitize($text, $panel?->getHtmlSanitizerConfig());
        } else {
            $text = trim($text, "\"“”\n ");
        }

        if ($task === 'continue') {
            $text = rtrim($source).($rich ? '' : ' ').ltrim($text);
        }

        return $text;
    }

    protected function getFieldText(): string
    {
        $component = $this->getFieldComponent();
        $state = $component !== null && method_exists($component, 'getState') ? $component->getState() : null;

        return is_string($state) ? $state : '';
    }

    protected function sanitizeHtml(string $html): string
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        return \AlfaSix\Alfakit\Support\Html::sanitize($html, $panel?->getHtmlSanitizerConfig());
    }

    /**
     * @return array<Component>
     */
    public function getOutputComponents(): array
    {
        return [];
    }
}
