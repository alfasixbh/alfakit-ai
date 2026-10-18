<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $table = 'ai_orders';

    protected $guarded = [];

    protected $hidden = ['secret_code'];

    protected function casts(): array
    {
        return ['paid' => 'boolean', 'is_private' => 'boolean', 'total' => 'float', 'ordered_at' => 'date'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
