<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Copilot;

use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Copilot\Tools\NavigateTool;
use AlfaSix\AlfakitAi\Copilot\Tools\ProposeActionTool;
use AlfaSix\AlfakitAi\Copilot\Tools\ReadRecordTool;
use AlfaSix\AlfakitAi\Copilot\Tools\SearchRecordsTool;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Models\Conversation;
use AlfaSix\AlfakitAi\Models\Message;
use AlfaSix\AlfakitAi\Models\ToolCall;
use AlfaSix\AlfakitAi\Support\AiRunner;
use AlfaSix\AlfakitAi\Support\DemoResponder;
use AlfaSix\AlfakitAi\Support\PanelGateway;
use AlfaSix\AlfakitAi\Support\PromptData;
use AlfaSix\AlfakitAi\Support\ToolAudit;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Throwable;

/**
 * Conversations of the copilot: stored per user, panel and tenant; each question runs the
 * copilot agent with the user's tools; proposed actions run only on confirmation.
 */
class CopilotService
{
    public function __construct(
        protected AiRunner $runner,
    ) {}

    /**
     * The user's conversations on the current panel and tenant, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Conversation>
     */
    public function conversations(Model $user, int $limit = 30): \Illuminate\Database\Eloquent\Collection
    {
        return Conversation::query()
            ->ownedBy($this->panelId(), $user, alfakit()->getTenant())
            ->latest('updated_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function find(Model $user, int $id): ?Conversation
    {
        return Conversation::query()->ownedBy($this->panelId(), $user, alfakit()->getTenant())->whereKey($id)->first();
    }

    /**
     * Ask a question (in a new conversation without `$conversation`). Returns the
     * conversation and the messages this turn added.
     *
     * With `$onDelta` the answer's text is handed to it as it arrives (`streaming()`).
     *
     * @param  (Closure(string): void)|null  $onDelta
     * @return array{conversation: Conversation, messages: array<int, Message>}
     */
    public function ask(Model $user, ?Conversation $conversation, string $question, ?string $pageUrl = null, ?Closure $onDelta = null): array
    {
        $plugin = $this->runner->ensureCanUse(AiFeature::Copilot);
        $gateway = PanelGateway::current();

        abort_if($gateway === null, 404);

        $question = trim(mb_substr($question, 0, 4000));
        $tenant = alfakit()->getTenant();

        $conversation ??= Conversation::query()->create([
            'panel' => $this->panelId(),
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
            'tenant_type' => $tenant?->getMorphClass(),
            'tenant_id' => $tenant?->getKey(),
            'title' => Str::limit($question, 60),
        ]);

        $history = $this->history($conversation, $plugin->getCopilotHistory());
        $userMessage = $conversation->messages()->create(['role' => Message::USER, 'content' => $question]);

        $turn = new CopilotTurn($conversation, $gateway);
        $agent = (new CopilotAgent)
            ->using($plugin->getProvider(AiFeature::Copilot), $plugin->getModel(AiFeature::Copilot))
            ->withMaxSteps($plugin->getCopilotMaxSteps())
            ->withHistory($history)
            ->withToolset([
                new SearchRecordsTool($turn),
                new ReadRecordTool($turn),
                new NavigateTool($turn),
                new ProposeActionTool($turn),
            ])
            ->withInstructions($this->instructions($plugin, $gateway, $user));

        $prompt = $question;

        if ($pageUrl !== null && $gateway->isPanelUrl($pageUrl)) {
            $prompt .= "\n\n".PromptData::wrap(['current_page' => $pageUrl], 'context');
        }

        try {
            $response = $onDelta === null
                ? $this->runner->prompt(AiFeature::Copilot, $agent, $prompt, 'copilot')
                : $this->runner->stream(AiFeature::Copilot, $agent, $prompt, $onDelta, 'copilot');
        } catch (\AlfaSix\AlfakitAi\Exceptions\StreamCancelled $exception) {
            // The user stopped the answer: the question stays, without an answer.
            $conversation->touch();

            throw $exception;
        } catch (\AlfaSix\AlfakitAi\Exceptions\AiRateLimitedException $exception) {
            // Not asked at all: nothing is kept.
            $userMessage->delete();

            if ($conversation->messages()->doesntExist()) {
                $conversation->delete();
            }

            throw $exception;
        } catch (Throwable $exception) {
            // The question stays in the history; the error is shown as a message.
            $error = $conversation->messages()->create([
                'role' => Message::ERROR,
                'content' => $exception instanceof \AlfaSix\AlfakitAi\Exceptions\AiException ? $exception->getMessage() : (string) __('alfakit-ai::ai.errors.failed'),
            ]);
            $conversation->touch();

            if (! $exception instanceof \AlfaSix\AlfakitAi\Exceptions\AiException) {
                throw $exception;
            }

            return ['conversation' => $conversation, 'messages' => [$userMessage, ...$turn->messages, $error]];
        }

        $model = $response->meta->model;
        $answer = $conversation->messages()->create([
            'role' => Message::ASSISTANT,
            'content' => trim((string) $response->text) !== '' ? trim((string) $response->text) : (string) __('alfakit-ai::ai.copilot.no_answer'),
            'meta' => array_filter([
                'activity' => $turn->activity ?: null,
                'model' => $model,
                'demo' => DemoResponder::isDemo($response) ?: null,
                'cost' => $plugin->estimateCost($model, $response->usage->inputTokens, $response->usage->outputTokens),
            ], static fn (mixed $value): bool => $value !== null),
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ]);

        $conversation->touch();

        return ['conversation' => $conversation, 'messages' => [$userMessage, ...$turn->messages, $answer]];
    }

    /**
     * Run a proposed action the user confirmed.
     *
     * @return array<int, Message>
     */
    public function confirm(Message $proposal): array
    {
        $meta = $proposal->getMeta();
        $gateway = PanelGateway::current();

        abort_if($gateway === null, 404);
        abort_unless($proposal->getRole() === Message::ACTION && ($meta['status'] ?? null) === Message::PENDING, 409);

        $resource = $gateway->resource((string) ($meta['resource'] ?? ''));
        $record = $resource === null ? null : $gateway->find($resource, $meta['key'] ?? null);
        $arguments = ['resource' => $meta['resource'] ?? null, 'key' => $meta['key'] ?? null, 'action' => $meta['action'] ?? null];

        if ($resource === null || $record === null) {
            $result = ['ok' => false, 'message' => (string) __('alfakit-ai::ai.copilot.action_unavailable')];
        } else {
            $result = $gateway->runAction($resource, $record, (string) ($meta['action'] ?? ''), is_array($meta['data'] ?? null) ? $meta['data'] : []);
        }

        $status = $result['ok'] ? Message::CONFIRMED : Message::FAILED;
        $proposal->forceFill(['meta' => [...$meta, 'status' => $status, 'error' => $result['message']]])->save();

        ToolAudit::record(ToolAudit::COPILOT, 'run_action', $arguments, $result['ok'] ? ToolCall::CONFIRMED : ToolCall::FAILED, (string) json_encode($result), (int) $proposal->getAttribute('conversation_id'));

        $note = $proposal->conversation()->firstOrFail()->messages()->create([
            'role' => $result['ok'] ? Message::ASSISTANT : Message::ERROR,
            'content' => $result['ok']
                ? (string) __('alfakit-ai::ai.copilot.action_done', ['action' => $meta['label'] ?? '', 'record' => $meta['record'] ?? ''])
                : (string) $result['message'].(isset($result['errors']) ? ' '.implode(' ', array_map(static fn (array $messages): string => implode(' ', $messages), $result['errors'])) : ''),
        ]);

        return [$proposal->refresh(), $note];
    }

    /**
     * @return array<int, Message>
     */
    public function reject(Message $proposal): array
    {
        $meta = $proposal->getMeta();

        abort_unless($proposal->getRole() === Message::ACTION && ($meta['status'] ?? null) === Message::PENDING, 409);

        $proposal->forceFill(['meta' => [...$meta, 'status' => Message::REJECTED]])->save();

        ToolAudit::record(ToolAudit::COPILOT, 'run_action', ['resource' => $meta['resource'] ?? null, 'key' => $meta['key'] ?? null, 'action' => $meta['action'] ?? null], ToolCall::REJECTED, null, (int) $proposal->getAttribute('conversation_id'));

        return [$proposal->refresh()];
    }

    /**
     * Earlier messages as laravel/ai messages: questions, answers and what happened to
     * proposed actions (so the model knows what the user decided).
     *
     * @return array<int, \Laravel\Ai\Messages\Message>
     */
    protected function history(Conversation $conversation, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        $messages = $conversation->messages()->reorder()->latest('id')->limit($limit)->get()->reverse();
        $history = [];

        foreach ($messages as $message) {
            $content = (string) $message->getAttribute('content');
            $meta = $message->getMeta();

            $history[] = match ($message->getRole()) {
                Message::USER => new UserMessage($content),
                Message::ACTION => new AssistantMessage('[Proposed action "'.($meta['label'] ?? '').'" on '.($meta['record'] ?? '').': '.($meta['status'] ?? 'pending').']'),
                Message::NAVIGATION => new AssistantMessage('[Showed a link to '.$content.']'),
                default => new AssistantMessage($content),
            };
        }

        return $history;
    }

    protected function instructions(AiPlugin $plugin, PanelGateway $gateway, Model $user): string
    {
        $panel = $gateway->getPanel();
        $tenant = alfakit()->getTenant();
        $name = $user->getAttribute('name');

        $instructions = "You are the copilot of an admin panel (\"{$panel->getBrandName()}\"). You help the signed-in user find, read and understand records, open pages and run actions.\n"
            ."Rules:\n"
            ."- Use the tools for facts about records; never invent records, values or keys.\n"
            ."- You can only see what the user may see; if a tool denies something, say so plainly.\n"
            ."- To change anything, call propose_action. Actions run only after the user confirms the card; never say an action was done unless the conversation shows it was confirmed.\n"
            ."- Offer navigate links when the user wants to open something.\n"
            ."- Be brief. Use the user's language. Plain text or simple Markdown lists.\n"
            .'- Today is '.now(alfakit()->getTimezone())->toDateString().' ('.alfakit()->getTimezone().').';

        $context = array_filter([
            'user' => is_string($name) ? $name : null,
            'tenant' => $tenant === null ? null : strip_tags((string) $panel->getTenantName($tenant)),
            'locale' => app()->getLocale(),
            'resources' => $gateway->catalogue(),
        ], static fn (mixed $value): bool => $value !== null);

        $extra = $plugin->getCopilotInstructions();

        return PromptData::instructions($instructions.($extra !== null ? "\n\nApplication notes:\n{$extra}" : '')."\n\nCatalogue:\n".PromptData::wrap($context, 'panel'));
    }

    protected function panelId(): string
    {
        return (alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel())?->getId() ?? '';
    }

    /**
     * A message as the copilot component shows it.
     *
     * @return array<string, mixed>
     */
    public static function serialize(Message $message): array
    {
        $meta = $message->getMeta();
        $role = $message->getRole();

        return array_filter([
            'id' => $message->getKey(),
            'role' => $role,
            'content' => (string) $message->getAttribute('content'),
            'createdAt' => $message->getAttribute('created_at')?->toIso8601String(),
            'activity' => $meta['activity'] ?? null,
            'demo' => $role === Message::ASSISTANT && ($meta['demo'] ?? false) === true ? true : null,
            'url' => in_array($role, [Message::NAVIGATION, Message::ACTION], true) ? ($meta['url'] ?? null) : null,
            'action' => $role === Message::ACTION ? array_filter([
                'label' => $meta['label'] ?? null,
                'record' => $meta['record'] ?? null,
                'status' => $meta['status'] ?? Message::PENDING,
                'inputs' => $meta['inputs'] ?? [],
                'color' => $meta['color'] ?? null,
                'error' => $meta['error'] ?? null,
            ], static fn (mixed $value): bool => $value !== null) : null,
            'usage' => $role === Message::ASSISTANT && ((int) $message->getAttribute('input_tokens') + (int) $message->getAttribute('output_tokens')) > 0 ? array_filter([
                'inputTokens' => (int) $message->getAttribute('input_tokens'),
                'outputTokens' => (int) $message->getAttribute('output_tokens'),
                'cost' => $meta['cost'] ?? null,
            ], static fn (mixed $value): bool => $value !== null) : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeConversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->getKey(),
            'title' => (string) ($conversation->getAttribute('title') ?? ''),
            'updatedAt' => $conversation->getAttribute('updated_at')?->toIso8601String(),
        ];
    }
}
