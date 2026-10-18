<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A copilot conversation of one user on one panel (and tenant, on tenancy panels).
 *
 * @property int $id
 */
class Conversation extends Model
{
    protected $table = 'alfakit_ai_conversations';

    protected $guarded = [];

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('id');
    }

    /** @return MorphTo<Model, $this> */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Conversations of `$user` on `$panel`, within `$tenant` (or tenant-less ones).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, string $panel, Model $user, ?Model $tenant): Builder
    {
        $query->where('panel', $panel)
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey());

        if ($tenant === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', $tenant->getKey());
    }
}
