<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

/**
 * A streamed answer the server prepared: the prompt, the agent's settings and how to finish
 * the text, encrypted into a one-time token that the browser posts to `/_ai/stream`. The
 * token only works for the user, panel and tenant it was issued to, once, for ten minutes;
 * the prompt never leaves the server readable and can't be changed.
 */
final class StreamJob
{
    public const TTL_SECONDS = 600;

    /**
     * @param  array<string, mixed>  $job  feature, agent, prompt, context, provider, model,
     *                                     temperature, maxTokens, instructions, finish
     */
    public static function issue(array $job): string
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $user = $panel?->auth()->user();
        $tenant = alfakit()->getTenant();

        return Crypt::encryptString((string) json_encode([
            ...$job,
            'id' => (string) Str::ulid(),
            'panel' => $panel?->getId(),
            'user' => $user instanceof Model ? $user->getMorphClass().':'.$user->getKey() : null,
            'tenant' => $tenant === null ? null : $tenant->getMorphClass().':'.$tenant->getKey(),
            'exp' => now()->getTimestamp() + self::TTL_SECONDS,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The job of a token, or null when it is invalid, expired, already used, or was issued
     * to another user, panel or tenant.
     *
     * @return array<string, mixed>|null
     */
    public static function open(mixed $token): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $job = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($job) || (int) ($job['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $user = $panel?->auth()->user();
        $tenant = alfakit()->getTenant();

        if (($job['panel'] ?? null) !== $panel?->getId()
            || ! $user instanceof Model
            || ($job['user'] ?? null) !== $user->getMorphClass().':'.$user->getKey()
            || ($job['tenant'] ?? null) !== ($tenant === null ? null : $tenant->getMorphClass().':'.$tenant->getKey())) {
            return null;
        }

        // One answer per token: a replayed request is refused.
        if (! Cache::add('alfakit-ai:stream:'.($job['id'] ?? ''), true, self::TTL_SECONDS)) {
            return null;
        }

        /** @var array<string, mixed> $job */
        return $job;
    }
}
