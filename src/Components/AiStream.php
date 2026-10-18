<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Components;

use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\AlfakitAi\AiPlugin;

/**
 * Streams an answer into a modal form (`ai:stream` in the renderers). Its state is the
 * one-time stream token (`StreamJob`) the action issued; while it has one, the renderer
 * posts it to `/_ai/stream`, shows the text as it arrives (with "Stop"), then writes the
 * finished answer into `target()`, clears the token and refreshes the form, so the answer
 * fields reappear rendered by the server. A failure goes into `errorPath()`.
 */
class AiStream extends Field
{
    protected string $target = 'ai_result';

    protected string $errorPath = 'ai_error';

    protected function setUp(): void
    {
        parent::setUp();

        $this->type(AiPlugin::STREAM_COMPONENT);
        $this->hiddenLabel();
        $this->columnSpanFull();
        // The token is for the browser: it never reaches the action's `$data`.
        $this->dehydrated(false);
    }

    /**
     * The field (a sibling, by name) that receives the finished answer.
     */
    public function target(string $name): static
    {
        $this->target = $name;

        return $this;
    }

    public function errorPath(string $name): static
    {
        $this->errorPath = $name;

        return $this;
    }

    /**
     * A sibling's absolute state path.
     */
    protected function siblingPath(string $name): string
    {
        $path = (string) $this->getStatePath();

        return str_contains($path, '.') ? substr($path, 0, (int) strrpos($path, '.') + 1).$name : $name;
    }

    protected function getSchemaProps(): array
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        return [
            ...parent::getSchemaProps(),
            'endpoint' => $panel->route('ai.stream'),
            'target' => $this->siblingPath($this->target),
            'errorPath' => $this->siblingPath($this->errorPath),
        ];
    }
}
