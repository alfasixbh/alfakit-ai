<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message of a copilot conversation. `action` messages are panel actions the model
 * proposed; they run only once the user confirms them (`meta.status`).
 *
 * @property int $id
 */
class Message extends Model
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const ACTION = 'action';

    public const NAVIGATION = 'navigation';

    public const ERROR = 'error';

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    protected $table = 'alfakit_ai_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        $meta = $this->getAttribute('meta');

        return is_array($meta) ? $meta : [];
    }

    public function getRole(): string
    {
        return (string) $this->getAttribute('role');
    }
}
