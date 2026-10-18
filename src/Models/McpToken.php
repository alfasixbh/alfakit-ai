<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A personal MCP token issued by the plugin itself (when neither alfasix/alfakit-api nor
 * laravel/sanctum is installed). Only the SHA-256 hash of the secret is stored.
 *
 * @property int $id
 */
class McpToken extends Model
{
    protected $table = 'alfakit_ai_mcp_tokens';

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }
}
