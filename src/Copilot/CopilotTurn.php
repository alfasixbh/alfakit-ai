<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Copilot;

use AlfaSix\AlfakitAi\Models\Conversation;
use AlfaSix\AlfakitAi\Models\Message;
use AlfaSix\AlfakitAi\Support\PanelGateway;

/**
 * One copilot question being answered: what the tools did, to show in the chat (activity
 * chips, links, proposed actions).
 */
final class CopilotTurn
{
    /** @var array<int, array{tool: string, label: string}> */
    public array $activity = [];

    /** @var array<int, Message> */
    public array $messages = [];

    public function __construct(
        public readonly Conversation $conversation,
        public readonly PanelGateway $gateway,
    ) {}

    public function activity(string $tool, string $label): void
    {
        $this->activity[] = ['tool' => $tool, 'label' => $label];
    }

    public function message(Message $message): void
    {
        $this->messages[] = $message;
    }
}
