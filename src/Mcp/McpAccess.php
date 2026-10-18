<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Mcp;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * An authenticated MCP client: the token's user, the tenant it is bound to (if any), where
 * the token came from, and its abilities (`{resource}:read|create|update`, `*`). Abilities
 * only narrow access: the user's policies always apply as well.
 */
final class McpAccess
{
    /**
     * @param  Closure(string): bool  $allows
     */
    public function __construct(
        public readonly Model&Authenticatable $user,
        public readonly ?Model $tenant,
        public readonly string $source,
        private readonly Closure $allows,
    ) {}

    public function allows(string $resource, string $operation): bool
    {
        return ($this->allows)($resource.':'.$operation);
    }
}
