<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Actions;

use AlfaSix\AlfakitAi\Actions\Concerns\HasSource;
use AlfaSix\AlfakitAi\Support\PromptData;
use Closure;

/**
 * Summarise a record (its visible fields) or a field's text.
 *
 * ```php
 * SummarizeAction::make()                                     // a table or page action
 * SummarizeAction::make()->source(fn (Ticket $record) => $record->body)->length('medium')->bullets()
 * ```
 */
class SummarizeAction extends AiAction
{
    use HasSource;

    protected string|Closure $length = 'short';

    protected bool|Closure $isBulleted = false;

    public static function make(string $name = 'summarize'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-ai::ai.prebuilt.summarize'));
        $this->modalHeading(__('alfakit-ai::ai.prebuilt.summarize'));
        $this->prompt(fn (array $data): string => $this->buildPrompt($data));
    }

    /**
     * `short` (two or three sentences), `medium` (a paragraph) or `long` (a few paragraphs).
     */
    public function length(string|Closure $length): static
    {
        $this->length = $length;

        return $this;
    }

    public function bullets(bool|Closure $condition = true): static
    {
        $this->isBulleted = $condition;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    protected function buildPrompt(array $inputs): string
    {
        $source = $this->getSource($inputs);

        if (self::isBlankSource($source)) {
            return '';
        }

        $length = match ($this->evaluate($this->length)) {
            'long' => 'a few paragraphs',
            'medium' => 'one paragraph',
            default => 'two or three sentences',
        };
        $format = $this->evaluate($this->isBulleted) ? 'a bullet list' : 'prose';

        return "Summarise the content below in {$length}, as {$format}. Keep names, numbers and dates exact.\n\n".PromptData::wrap($source, 'source');
    }
}
