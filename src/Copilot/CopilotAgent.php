<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Copilot;

use AlfaSix\AlfakitAi\Agents\AlfakitAgent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;

/**
 * The panel copilot: earlier messages of the conversation plus tools that act with the
 * user's permissions.
 */
class CopilotAgent extends AlfakitAgent implements Conversational, HasTools
{
    /** @var array<int, Message> */
    protected array $history = [];

    /** @var array<int, \Laravel\Ai\Contracts\Tool> */
    protected array $toolset = [];

    protected int $steps = 8;

    /**
     * @param  array<int, Message>  $history
     */
    public function withHistory(array $history): static
    {
        $this->history = $history;

        return $this;
    }

    /**
     * @param  array<int, \Laravel\Ai\Contracts\Tool>  $tools
     */
    public function withToolset(array $tools): static
    {
        $this->toolset = $tools;

        return $this;
    }

    public function withMaxSteps(int $steps): static
    {
        $this->steps = max(1, $steps);

        return $this;
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        return $this->toolset;
    }

    public function maxSteps(): int
    {
        return $this->steps;
    }
}
