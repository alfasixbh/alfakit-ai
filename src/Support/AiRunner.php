<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use AlfaSix\Alfakit\Commands\Support\AiKeyTester;
use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitAi\Agents\AlfakitAgent;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Exceptions\AiException;
use AlfaSix\AlfakitAi\Exceptions\AiNotConfiguredException;
use AlfaSix\AlfakitAi\Exceptions\AiRateLimitedException;
use AlfaSix\AlfakitAi\Exceptions\StreamCancelled;
use AlfaSix\AlfakitAi\Models\AiUsage;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

/**
 * The one way the plugin talks to a provider: checks the panel switch, the feature, the
 * user's permission and the rate limits, applies the configured provider and model, and
 * logs every call (feature, user, tenant, provider, model, tokens, cost, duration, status)
 * in `alfakit_ai_usage`. Provider errors are reported and replaced by a message that is safe
 * to show.
 */
class AiRunner
{
    /**
     * Throw unless the current user may use `$feature` on the current panel.
     *
     * @throws AiException|AuthorizationException
     */
    public function ensureCanUse(AiFeature $feature, ?Panel $panel = null): AiPlugin
    {
        $panel ??= alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $plugin = $panel === null ? null : AiPlugin::get($panel);

        if ($plugin === null || ! $plugin->isEnabled($feature)) {
            throw AiException::unavailable();
        }

        if (! $plugin->canUse($feature)) {
            throw new AuthorizationException((string) __('alfakit-ai::ai.errors.forbidden'));
        }

        return $plugin;
    }

    /**
     * Prompt `$agent` for `$feature`. `$context` names what asked (an action, a tool) in the
     * usage log.
     *
     * @param  array<int, mixed>  $attachments
     *
     * @throws AiException|AuthorizationException
     */
    public function prompt(AiFeature $feature, AlfakitAgent $agent, string $prompt, ?string $context = null, array $attachments = []): AgentResponse
    {
        return $this->run($feature, $agent, $prompt, $context, $attachments, null);
    }

    /**
     * Like `prompt()`, but hands the answer's text to `$onDelta` as it arrives (laravel/ai
     * streaming). Sample answers and providers that can't stream are sent in small chunks
     * of the complete answer instead. Returns the complete response, logged like `prompt()`.
     *
     * @param  Closure(string): void  $onDelta
     * @param  array<int, mixed>  $attachments
     *
     * @throws AiException|AuthorizationException
     */
    public function stream(AiFeature $feature, AlfakitAgent $agent, string $prompt, Closure $onDelta, ?string $context = null, array $attachments = []): AgentResponse
    {
        return $this->run($feature, $agent, $prompt, $context, $attachments, $onDelta);
    }

    /**
     * Whether `$agent` can answer `$feature` now: a provider key, sample answers or a faked
     * agent. Without one, features show the setup notice instead of asking.
     */
    public function canAnswer(AiFeature $feature, AlfakitAgent $agent, ?AiPlugin $plugin = null): bool
    {
        $plugin ??= AiPlugin::get();

        return $plugin !== null && ($agent::isFaked() || $plugin->isConfigured($feature, $agent->provider()) || $plugin->hasDemoResponses());
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @param  (Closure(string): void)|null  $onDelta
     */
    protected function run(AiFeature $feature, AlfakitAgent $agent, string $prompt, ?string $context, array $attachments, ?Closure $onDelta): AgentResponse
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $plugin = $this->ensureCanUse($feature, $panel);
        $user = $panel?->auth()->user();

        $this->hitRateLimits($plugin, $feature, $panel, $user instanceof Model ? $user : null);

        if ($agent->provider() === null && $agent->model() === null) {
            $agent->using($plugin->getProvider($feature), $plugin->getModel($feature));
        }

        $agent->withTimeout($plugin->getTimeout());

        $started = hrtime(true);

        // No provider key: sample answers (`demoResponses()`), or a friendly message. Faked
        // agents (tests) never need a key.
        $demo = ! $agent::isFaked() && ! $plugin->isConfigured($feature, $agent->provider());

        if ($demo && ! $plugin->hasDemoResponses()) {
            throw AiNotConfiguredException::for($plugin);
        }

        try {
            if ($onDelta === null) {
                $response = $demo
                    ? app(DemoResponder::class)->respond($feature, $agent, $prompt)
                    : $agent->prompt($prompt, $attachments);
            } elseif ($demo) {
                $response = app(DemoResponder::class)->respond($feature, $agent, $prompt);
                $this->emitInChunks((string) $response->text, $onDelta, $plugin->getDemoStreamDelay());
            } else {
                $response = $this->streamFromProvider($agent, $prompt, $attachments, $onDelta);
            }
        } catch (StreamCancelled $cancelled) {
            $this->log($plugin, $feature, $context, $panel, $user instanceof Model ? $user : null, $started, null, AiUsage::FAILED, $cancelled, $agent);

            throw $cancelled;
        } catch (Throwable $exception) {
            report($exception);

            $this->log($plugin, $feature, $context, $panel, $user instanceof Model ? $user : null, $started, null, AiUsage::FAILED, $exception, $agent);

            throw AiException::failed();
        }

        $this->log($plugin, $feature, $context, $panel, $user instanceof Model ? $user : null, $started, $response, AiUsage::SUCCESS, null, $agent);

        return $response;
    }

    /**
     * Stream from the provider. A provider that fails before sending any text (one that
     * can't stream) is asked again without streaming, and its answer sent in chunks.
     *
     * @param  array<int, mixed>  $attachments
     * @param  Closure(string): void  $onDelta
     */
    protected function streamFromProvider(AlfakitAgent $agent, string $prompt, array $attachments, Closure $onDelta): AgentResponse
    {
        $completed = null;
        $sent = false;

        try {
            $stream = $agent->stream($prompt, $attachments)->then(static function (StreamedAgentResponse $response) use (&$completed): void {
                $completed = $response;
            });

            foreach ($stream as $event) {
                if ($event instanceof TextDelta && $event->delta !== '') {
                    $sent = true;
                    $onDelta($event->delta);
                }
            }
        } catch (StreamCancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable $exception) {
            if ($sent) {
                throw $exception;
            }

            report($exception);

            $response = $agent->prompt($prompt, $attachments);
            $this->emitInChunks((string) $response->text, $onDelta, 0);

            return $response;
        }

        return $completed ?? throw AiException::failed();
    }

    /**
     * Send a complete answer a few words at a time (sample answers pause between chunks,
     * so they read like a streamed answer).
     *
     * @param  Closure(string): void  $onDelta
     */
    protected function emitInChunks(string $text, Closure $onDelta, int $delayMs): void
    {
        $chunks = preg_split('/(?<=\s)/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_chunk($chunks, 3) as $index => $words) {
            if ($index > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $onDelta(implode('', $words));
        }
    }

    /**
     * The structured answer of a structured agent (an empty array when the provider sent
     * none).
     *
     * @return array<string, mixed>
     */
    public function structured(AiFeature $feature, AlfakitAgent $agent, string $prompt, ?string $context = null): array
    {
        $response = $this->prompt($feature, $agent, $prompt, $context);

        if ($response instanceof StructuredAgentResponse) {
            return $response->toArray();
        }

        $decoded = json_decode(trim((string) $response->text), true);

        if (! is_array($decoded)) {
            throw AiException::invalidAnswer();
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Count the request against the user's limits, or throw when one is used up.
     */
    protected function hitRateLimits(AiPlugin $plugin, AiFeature $feature, ?Panel $panel, ?Model $user): void
    {
        $identity = $user === null ? 'guest:'.request()->ip() : $user->getMorphClass().':'.$user->getKey();
        $keys = [];

        foreach ($plugin->getRateLimits($feature) as $scope => $limit) {
            $key = 'alfakit-ai:'.($panel?->getId() ?? '').':'.$identity.':'.$scope;

            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw new AiRateLimitedException(RateLimiter::availableIn($key));
            }

            $keys[] = $key;
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, 60);
        }
    }

    /**
     * Provider errors can echo request details: API keys and long tokens are masked.
     */
    protected function sanitize(string $message): string
    {
        $secrets = [];

        foreach ((array) config('ai.providers', []) as $provider) {
            if (is_array($provider) && is_string($provider['key'] ?? null) && $provider['key'] !== '') {
                $secrets[] = $provider['key'];
            }
        }

        return AiKeyTester::sanitize($message, $secrets);
    }

    protected function log(AiPlugin $plugin, AiFeature $feature, ?string $context, ?Panel $panel, ?Model $user, int|float $started, ?AgentResponse $response, string $status, ?Throwable $exception, AlfakitAgent $agent): void
    {
        $inputTokens = $response?->usage->inputTokens ?? 0;
        $outputTokens = $response?->usage->outputTokens ?? 0;
        $provider = $response?->meta->provider ?? (is_string($agent->provider()) ? $agent->provider() : null);
        $model = $response?->meta->model ?? $agent->model();
        $tenant = alfakit()->getTenant();

        try {
            AiUsage::query()->create([
                'panel' => $panel?->getId() ?? '',
                'feature' => $feature->value,
                'context' => $context === null ? null : Str::limit($context, 250, ''),
                'user_type' => $user?->getMorphClass(),
                'user_id' => $user?->getKey(),
                'tenant_type' => $tenant?->getMorphClass(),
                'tenant_id' => $tenant?->getKey(),
                'provider' => $provider === null ? null : Str::limit($provider, 60, ''),
                'model' => $model,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'cost' => $provider === DemoResponder::PROVIDER ? 0.0 : $plugin->estimateCost($model, $inputTokens, $outputTokens),
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'status' => $status,
                'error' => $exception === null ? null : Str::limit(class_basename($exception).': '.$this->sanitize($exception->getMessage()), 1000),
            ]);
        } catch (Throwable $logging) {
            // A missing migration must not break the feature itself.
            report($logging);
        }
    }
}
