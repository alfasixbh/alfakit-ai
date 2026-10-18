<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Http\Middleware;

use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Mcp\McpAccess;
use AlfaSix\AlfakitAi\Mcp\McpTokens;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates MCP requests with `Authorization: Bearer {token}` (see `McpTokens`), signs
 * the token's user in on the panel's guard for this request only, resolves the tenant from
 * the URL (it must match the token's tenant), checks the panel switch and
 * `AiPlugin::authorize()`, and rate limits per user.
 */
class AuthenticateMcpToken
{
    public const DEFAULT_PER_MINUTE = 120;

    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = alfakit()->getPanel($panelId);
        $plugin = AiPlugin::get($panel);

        if ($plugin === null || ! $plugin->isEnabled(AiFeature::Mcp)) {
            return $this->error(404, 'Not found.');
        }

        $plainText = $request->bearerToken();
        $access = is_string($plainText) && $plainText !== '' ? McpTokens::authenticate($panel, $plainText) : null;

        if (! $access instanceof McpAccess) {
            return $this->error(401, 'Unauthenticated.', ['WWW-Authenticate' => 'Bearer']);
        }

        $user = $access->user;

        if ($user instanceof CanAccessPanel && ! $user->canAccessPanel($panel)) {
            return $this->error(403, 'Forbidden.');
        }

        if ($panel->hasTenancy()) {
            $key = $request->route()?->parameter('tenant');
            $tenant = is_scalar($key) ? $panel->findTenantFor((string) $key, $user) : null;

            if ($tenant === null) {
                return $this->error(404, 'Tenant not found.');
            }

            if ($access->tenant !== null && ($access->tenant->getMorphClass() !== $tenant->getMorphClass() || (string) $access->tenant->getKey() !== (string) $tenant->getKey())) {
                return $this->error(403, 'This token belongs to another tenant.');
            }

            alfakit()->setTenant($tenant);
            $request->route()->forgetParameter('tenant');
        }

        $guard = $panel->auth();
        $previous = method_exists($guard, 'hasUser') && $guard->hasUser() ? $guard->user() : null;
        Auth::shouldUse($panel->getAuthGuard());
        $guard->setUser($user);
        $request->setUserResolver(static fn () => $user);

        try {
            if (! $plugin->canUse(AiFeature::Mcp, $user)) {
                return $this->error(403, 'Forbidden.');
            }

            $limit = $plugin->getRateLimits(AiFeature::Mcp)['mcp'] ?? self::DEFAULT_PER_MINUTE;
            $key = 'alfakit-ai-mcp:'.$panel->getId().':'.$user->getMorphClass().':'.$user->getKey();

            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return $this->error(429, 'Too many requests.', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }

            RateLimiter::hit($key, 60);

            app()->instance(McpAccess::class, $access);

            return $next($request);
        } finally {
            app()->forgetInstance(McpAccess::class);

            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function error(int $status, string $message, array $headers = []): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => $message]], $status, $headers);
    }
}
