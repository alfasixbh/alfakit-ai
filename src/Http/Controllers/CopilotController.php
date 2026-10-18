<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Http\Controllers;

use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Copilot\CopilotService;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Exceptions\AiRateLimitedException;
use AlfaSix\AlfakitAi\Models\Conversation;
use AlfaSix\AlfakitAi\Models\Message;
use AlfaSix\AlfakitAi\Support\EventStream;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The copilot's JSON endpoints (session-authenticated panel routes, CSRF-protected, under
 * the tenant prefix on tenancy panels). Every request checks the panel switch, the
 * feature and `AiPlugin::authorize()`, and only reaches the user's own conversations.
 */
class CopilotController
{
    public function __construct(protected CopilotService $copilot) {}

    public function conversations(Request $request): JsonResponse
    {
        $user = $this->user();

        return new JsonResponse([
            'conversations' => $this->copilot->conversations($user)->map(CopilotService::serializeConversation(...))->values()->all(),
        ]);
    }

    public function show(Request $request, int|string $conversation): JsonResponse
    {
        $found = $this->conversation($this->user(), $request);

        return new JsonResponse([
            'conversation' => CopilotService::serializeConversation($found),
            'messages' => $found->messages()->get()->map(CopilotService::serialize(...))->values()->all(),
        ]);
    }

    public function destroy(Request $request, int|string $conversation): JsonResponse
    {
        $this->conversation($this->user(), $request)->delete();

        return new JsonResponse(['deleted' => true]);
    }

    public function send(Request $request): JsonResponse
    {
        $user = $this->user();
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation' => ['nullable', 'integer'],
            'page' => ['nullable', 'string', 'max:2048'],
        ]);

        $conversation = isset($data['conversation']) ? $this->copilot->find($user, (int) $data['conversation']) : null;

        abort_if(isset($data['conversation']) && $conversation === null, 404);

        try {
            $result = $this->copilot->ask($user, $conversation, (string) $data['message'], isset($data['page']) ? (string) $data['page'] : null);
        } catch (AiRateLimitedException $exception) {
            return new JsonResponse(['message' => $exception->getMessage(), 'retryAfter' => $exception->retryAfter], 429, ['Retry-After' => (string) $exception->retryAfter]);
        }

        return new JsonResponse([
            'conversation' => CopilotService::serializeConversation($result['conversation']),
            'messages' => array_map(CopilotService::serialize(...), $result['messages']),
        ]);
    }

    /**
     * `streaming()`: like `send()`, as server-sent events. `delta` ({text}) while the answer
     * is written, then `done` with the same payload as `send()`, or `error` ({message,
     * retryAfter?}).
     */
    public function stream(Request $request): StreamedResponse
    {
        $user = $this->user();

        abort_unless(AiPlugin::get()?->isStreaming() ?? false, 404);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation' => ['nullable', 'integer'],
            'page' => ['nullable', 'string', 'max:2048'],
        ]);

        $conversation = isset($data['conversation']) ? $this->copilot->find($user, (int) $data['conversation']) : null;

        abort_if(isset($data['conversation']) && $conversation === null, 404);

        return EventStream::response(function (Closure $emit) use ($user, $conversation, $data): void {
            try {
                $result = $this->copilot->ask(
                    $user,
                    $conversation,
                    (string) $data['message'],
                    isset($data['page']) ? (string) $data['page'] : null,
                    static fn (string $delta) => $emit('delta', ['text' => $delta]),
                );
            } catch (AiRateLimitedException $exception) {
                $emit('error', ['message' => $exception->getMessage(), 'retryAfter' => $exception->retryAfter]);

                return;
            }

            $emit('done', [
                'conversation' => CopilotService::serializeConversation($result['conversation']),
                'messages' => array_map(CopilotService::serialize(...), $result['messages']),
            ]);
        });
    }

    public function confirm(Request $request, int|string $message): JsonResponse
    {
        return new JsonResponse(['messages' => array_map(CopilotService::serialize(...), $this->copilot->confirm($this->proposal($request)))]);
    }

    public function reject(Request $request, int|string $message): JsonResponse
    {
        return new JsonResponse(['messages' => array_map(CopilotService::serialize(...), $this->copilot->reject($this->proposal($request)))]);
    }

    protected function user(): Model
    {
        $plugin = AiPlugin::get();

        abort_if($plugin === null || ! $plugin->isEnabled(AiFeature::Copilot), 404);
        abort_unless($plugin->canUse(AiFeature::Copilot), 403);

        $user = (alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel())?->auth()->user();

        abort_unless($user instanceof Model, 403);

        return $user;
    }

    protected function conversation(Model $user, Request $request): Conversation
    {
        $conversation = $this->copilot->find($user, (int) $request->route('conversation'));

        abort_if($conversation === null, 404);

        return $conversation;
    }

    protected function proposal(Request $request): Message
    {
        $user = $this->user();
        $message = Message::query()->whereKey((int) $request->route('message'))->first();
        $conversation = $message === null ? null : $this->copilot->find($user, (int) $message->getAttribute('conversation_id'));

        abort_if($message === null || $conversation === null, 404);

        return $message;
    }
}
