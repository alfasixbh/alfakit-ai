<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Mcp;

use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Models\McpToken;
use DateTimeInterface;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use SensitiveParameter;

/**
 * Where MCP bearer tokens come from. Every available store is tried, in this order, and
 * the first that recognises the token wins (each compares hashes, so trying several is
 * safe); `AiPlugin::mcpTokenSources()` narrows the list:
 *
 * 1. alfasix/alfakit-api, when the panel has the API plugin: its personal access tokens
 *    from its token repository (database or Sanctum), with their `{resource}:{operation}`
 *    abilities and tenant;
 * 2. laravel/sanctum, when installed: Sanctum personal access tokens (abilities checked
 *    with `$token->can('{resource}:{operation}')`);
 * 3. the plugin's own `alfakit_ai_mcp_tokens` table (`php artisan alfakit-ai:mcp-token`),
 *    which grants what the user may do.
 *
 * The token's user must be a user of the panel's guard (its provider's model).
 */
final class McpTokens
{
    public const API = 'api';

    public const SANCTUM = 'sanctum';

    public const PLUGIN = 'plugin';

    /** The panel marker ability of `SanctumTokenRepository` (alfasix/alfakit-api). */
    private const SANCTUM_PANEL_MARKER = 'alfakit-panel:';

    /** @var array<string, true> */
    private static array $sanctumTables = [];

    /**
     * The token stores this panel accepts, in the order they are tried.
     *
     * @return list<string>
     */
    public static function sources(Panel $panel): array
    {
        $available = [];

        if (class_exists('AlfaSix\\AlfakitApi\\ApiPlugin') && $panel->hasPlugin('api')) {
            $available[] = self::API;
        }

        if (class_exists('Laravel\\Sanctum\\PersonalAccessToken')) {
            $available[] = self::SANCTUM;
        }

        $available[] = self::PLUGIN;

        $allowed = AiPlugin::get($panel)?->getMcpTokenSources();

        return $allowed === null ? $available : array_values(array_intersect($available, $allowed));
    }

    /**
     * The first token store of the panel (what clients are told to use).
     */
    public static function source(Panel $panel): string
    {
        return self::sources($panel)[0] ?? self::PLUGIN;
    }

    public static function authenticate(Panel $panel, #[SensitiveParameter] string $plainText): ?McpAccess
    {
        foreach (self::sources($panel) as $source) {
            $access = match ($source) {
                self::API => self::fromApi($panel, $plainText),
                self::SANCTUM => self::fromSanctum($panel, $plainText),
                default => self::fromPlugin($panel, $plainText),
            };

            if ($access !== null) {
                return self::isPanelUser($panel, $access->user) ? $access : null;
            }
        }

        return null;
    }

    /**
     * A token's user must be one the panel's guard can sign in (a Sanctum token of another
     * tokenable model, such as a team, never is).
     */
    private static function isPanelUser(Panel $panel, Model $user): bool
    {
        $provider = $panel->auth()->getProvider();

        if (! $provider instanceof EloquentUserProvider) {
            return true;
        }

        $model = $provider->getModel();

        return $user instanceof $model;
    }

    private static function fromApi(Panel $panel, #[SensitiveParameter] string $plainText): ?McpAccess
    {
        $plugin = \AlfaSix\AlfakitApi\ApiPlugin::get($panel);
        $token = $plugin?->getTokenRepository()->find($plainText, $panel->getId());

        if ($token === null || $token->isExpired()) {
            return null;
        }

        $user = $token->getUser();

        if (! $user instanceof Model) {
            return null;
        }

        $plugin->getTokenRepository()->touch($token, request()->ip());

        return new McpAccess($user, $token->getTenant(), self::API, static fn (string $ability): bool => $token->can($ability));
    }

    private static function fromSanctum(Panel $panel, #[SensitiveParameter] string $plainText): ?McpAccess
    {
        $model = Sanctum::personalAccessTokenModel();

        // Sanctum may be installed without its table (an app that never ran its migration).
        if (! self::hasSanctumTable($model)) {
            return null;
        }

        try {
            $token = call_user_func([$model, 'findToken'], $plainText);
        } catch (QueryException) {
            return null;
        }

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        // Tokens alfasix/alfakit-api issued through Sanctum for another panel stay there.
        $abilities = is_array($token->getAttribute('abilities')) ? $token->getAttribute('abilities') : [];
        $panels = array_filter($abilities, static fn (mixed $ability): bool => is_string($ability) && str_starts_with($ability, self::SANCTUM_PANEL_MARKER));

        if ($panels !== [] && ! in_array(self::SANCTUM_PANEL_MARKER.$panel->getId(), $panels, true)) {
            return null;
        }

        $expiresAt = $token->getAttribute('expires_at');
        $createdAt = $token->getAttribute('created_at');
        // `sanctum.expiration` (minutes) applies to every token, as in Sanctum's guard.
        $lifetime = config('sanctum.expiration');

        if (($expiresAt instanceof DateTimeInterface && $expiresAt <= now())
            || (is_numeric($lifetime) && $createdAt instanceof DateTimeInterface && $createdAt <= now()->subMinutes((int) $lifetime))) {
            return null;
        }

        $user = $token->getAttribute('tokenable');

        if (! $user instanceof Model || ! $user instanceof Authenticatable) {
            return null;
        }

        // Like Sanctum's own guard: record the use, at most once a minute.
        $lastUsed = $token->getAttribute('last_used_at');

        if (! $lastUsed instanceof DateTimeInterface || $lastUsed < now()->subMinute()) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return new McpAccess($user, null, self::SANCTUM, static fn (string $ability): bool => $token->can($ability));
    }

    /**
     * @param  class-string  $model
     */
    private static function hasSanctumTable(string $model): bool
    {
        $instance = new $model;

        if (! $instance instanceof Model) {
            return false;
        }

        $key = $instance->getConnectionName().'.'.$instance->getTable();

        // Only a table that exists is remembered: migrating later needs no restart.
        if (isset(self::$sanctumTables[$key])) {
            return true;
        }

        if (! $instance->getConnection()->getSchemaBuilder()->hasTable($instance->getTable())) {
            return false;
        }

        return self::$sanctumTables[$key] = true;
    }

    private static function fromPlugin(Panel $panel, #[SensitiveParameter] string $plainText): ?McpAccess
    {
        if (! str_contains($plainText, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $plainText, 2);

        if ($id === '' || ! ctype_digit($id) || $secret === '') {
            return null;
        }

        $model = AiPlugin::get($panel)?->getMcpTokenModel() ?? McpToken::class;
        $token = $model::query()->whereKey((int) $id)->first();

        if (! $token instanceof McpToken || ! hash_equals((string) $token->getAttribute('token'), hash('sha256', $secret)) || $token->getAttribute('panel') !== $panel->getId()) {
            return null;
        }

        $expiresAt = $token->getAttribute('expires_at');

        if ($expiresAt instanceof DateTimeInterface && $expiresAt <= now()) {
            return null;
        }

        $user = $token->tokenable;

        if (! $user instanceof Model || ! $user instanceof Authenticatable) {
            return null;
        }

        $lastUsed = $token->getAttribute('last_used_at');

        if (! $lastUsed instanceof DateTimeInterface || $lastUsed < now()->subMinute()) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        $tenant = $token->tenant;

        return new McpAccess($user, $tenant instanceof Model ? $tenant : null, self::PLUGIN, static fn (string $ability): bool => true);
    }

    /**
     * Issue a plugin token (shown once): `{id}|{secret}`.
     */
    public static function issue(Panel $panel, Model $user, string $name, ?Model $tenant = null, ?DateTimeInterface $expiresAt = null): string
    {
        $model = AiPlugin::get($panel)?->getMcpTokenModel() ?? McpToken::class;
        $secret = Str::random(40);

        /** @var McpToken $token */
        $token = new $model;
        $token->forceFill([
            'panel' => $panel->getId(),
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'tenant_type' => $tenant?->getMorphClass(),
            'tenant_id' => $tenant?->getKey(),
            'name' => mb_substr(trim($name) ?: 'MCP', 0, 255),
            'token' => hash('sha256', $secret),
            'expires_at' => $expiresAt,
        ])->save();

        return $token->getKey().'|'.$secret;
    }
}
