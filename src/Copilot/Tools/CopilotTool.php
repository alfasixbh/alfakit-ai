<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Copilot\Tools;

use AlfaSix\AlfakitAi\Copilot\CopilotTurn;
use AlfaSix\AlfakitAi\Models\ToolCall;
use AlfaSix\AlfakitAi\Support\PromptData;
use AlfaSix\AlfakitAi\Support\ToolAudit;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * A copilot tool: runs with the signed-in user's permissions, logs every call to the audit
 * log and answers with JSON data (which the instructions say to treat as data only).
 */
abstract class CopilotTool implements Tool
{
    public function __construct(protected CopilotTurn $turn) {}

    abstract public function name(): string;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: mixed} [status, result data]
     */
    abstract protected function run(array $arguments): array;

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->all();

        try {
            [$status, $result] = $this->run($arguments);
        } catch (Throwable $exception) {
            report($exception);
            [$status, $result] = [ToolCall::FAILED, ['error' => 'The tool failed.']];
        }

        $json = PromptData::json($result);

        ToolAudit::record(ToolAudit::COPILOT, $this->name(), $arguments, $status, $json, $this->turn->conversation->getKey());

        return $json;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    protected function denied(string $reason): array
    {
        return [ToolCall::DENIED, ['error' => $reason]];
    }

    protected static function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
