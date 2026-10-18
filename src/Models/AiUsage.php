<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One AI call: the feature, who made it, the provider and model, tokens, the estimated
 * cost, the duration and whether it succeeded. Prompts and answers are never stored here.
 *
 * @property int $id
 */
class AiUsage extends Model
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    protected $table = 'alfakit_ai_usage';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'cost' => 'float',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTotalTokens(): int
    {
        return (int) $this->getAttribute('input_tokens') + (int) $this->getAttribute('output_tokens');
    }
}
