<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Http\Controllers;

use AlfaSix\AlfakitAi\Actions\AiAssistAction;
use AlfaSix\AlfakitAi\Agents\AlfakitAgent;
use AlfaSix\AlfakitAi\Agents\AssistAgent;
use AlfaSix\AlfakitAi\Agents\TextAgent;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Exceptions\AiException;
use AlfaSix\AlfakitAi\Exceptions\StreamCancelled;
use AlfaSix\AlfakitAi\Support\AiRunner;
use AlfaSix\AlfakitAi\Support\EventStream;
use AlfaSix\AlfakitAi\Support\StreamJob;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * POST {token}: stream the answer of an `AiAction` or `aiAssist()` task the server prepared
 * when the modal opened (`StreamJob`). Server-sent events: `delta` ({text}) as the answer
 * arrives, then `done` ({text}: the finished answer, cleaned up on the server) or `error`
 * ({message}). The feature, the user's permission and the rate limits are checked again.
 */
class StreamController
{
    public function __invoke(Request $request, AiRunner $runner): Response
    {
        $plugin = AiPlugin::get();

        abort_if($plugin === null || ! $plugin->isStreaming(), 404);

        $job = StreamJob::open($request->input('token'));

        abort_if($job === null, 403);

        $feature = AiFeature::tryFrom((string) ($job['feature'] ?? ''));

        abort_if($feature === null, 400);

        $agent = $this->agent($job);

        return EventStream::response(function (Closure $emit) use ($runner, $feature, $agent, $job): void {
            try {
                $response = $runner->stream(
                    $feature,
                    $agent,
                    (string) ($job['prompt'] ?? ''),
                    static fn (string $delta) => $emit('delta', ['text' => $delta]),
                    is_string($job['context'] ?? null) ? $job['context'] : null,
                );
            } catch (StreamCancelled $cancelled) {
                throw $cancelled;
            } catch (AiException|AuthorizationException $exception) {
                $emit('error', ['message' => $exception->getMessage()]);

                return;
            } catch (Throwable $exception) {
                report($exception);
                $emit('error', ['message' => (string) __('alfakit-ai::ai.errors.failed')]);

                return;
            }

            $emit('done', ['text' => $this->finish((string) $response->text, $job)]);
        });
    }

    /**
     * @param  array<string, mixed>  $job
     */
    protected function agent(array $job): AlfakitAgent
    {
        $agent = ($job['agent'] ?? null) === 'assist' ? new AssistAgent : new TextAgent;
        $provider = $job['provider'] ?? null;

        return $agent
            ->using(is_string($provider) || is_array($provider) ? $provider : null, is_string($job['model'] ?? null) ? $job['model'] : null)
            ->withTemperature(is_numeric($job['temperature'] ?? null) ? (float) $job['temperature'] : null)
            ->withMaxTokens(is_numeric($job['maxTokens'] ?? null) ? (int) $job['maxTokens'] : null)
            ->withInstructions((string) ($job['instructions'] ?? ''));
    }

    /**
     * The finished answer, cleaned up like a non-streamed one.
     *
     * @param  array<string, mixed>  $job
     */
    protected function finish(string $text, array $job): string
    {
        $finish = is_array($job['finish'] ?? null) ? $job['finish'] : [];

        if (($finish['type'] ?? null) === 'assist') {
            return AiAssistAction::finishSuggestion(
                $text,
                (string) ($finish['task'] ?? ''),
                (string) ($finish['source'] ?? ''),
                (string) ($finish['format'] ?? 'plain'),
            );
        }

        return trim($text);
    }
}
