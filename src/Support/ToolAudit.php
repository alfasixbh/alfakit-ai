<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use AlfaSix\AlfakitAi\Models\ToolCall;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the audit log of tool calls (`alfakit_ai_tool_calls`).
 */
final class ToolAudit
{
    public const COPILOT = 'copilot';

    public const MCP = 'mcp';

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function record(string $channel, string $tool, array $arguments, string $status, ?string $result = null, ?int $conversationId = null): ?ToolCall
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $user = $panel?->auth()->user();
        $tenant = alfakit()->getTenant();

        try {
            return ToolCall::query()->create([
                'panel' => $panel?->getId() ?? '',
                'channel' => $channel,
                'conversation_id' => $conversationId,
                'user_type' => $user instanceof Model ? $user->getMorphClass() : null,
                'user_id' => $user instanceof Model ? $user->getKey() : null,
                'tenant_type' => $tenant?->getMorphClass(),
                'tenant_id' => $tenant?->getKey(),
                'tool' => Str::limit($tool, 60, ''),
                'arguments' => Redactor::make()->redact(self::limit($arguments)),
                'status' => $status,
                'result' => $result === null ? null : Str::limit($result, 10000),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function limit(array $arguments): array
    {
        $json = (string) json_encode($arguments, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return strlen($json) > 8000 ? ['truncated' => Str::limit($json, 8000)] : $arguments;
    }
}
