<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The audit log of tool calls made by the copilot (`channel` = copilot) and MCP clients
 * (`channel` = mcp): who, which tool, the arguments and the outcome.
 *
 * @property int $id
 */
class ToolCall extends Model
{
    public const OK = 'ok';

    public const DENIED = 'denied';

    public const FAILED = 'failed';

    public const PROPOSED = 'proposed';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    protected $table = 'alfakit_ai_tool_calls';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['arguments' => 'array'];
    }
}
