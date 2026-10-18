<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Mcp\Tools;

use AlfaSix\AlfakitAi\Mcp\McpAccess;
use AlfaSix\AlfakitAi\Models\ToolCall;
use AlfaSix\AlfakitAi\Support\PanelGateway;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ShowRecordTool extends McpTool
{
    protected string $name = 'show_record';

    protected string $description = 'Show one record: the fields its resource shows (sensitive fields redacted) and its url.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->required(),
            'key' => $schema->string()->description('The record key from search_records.')->required(),
        ];
    }

    protected function run(PanelGateway $gateway, McpAccess $access, array $arguments): array
    {
        $resource = $this->resource($gateway, $access, $arguments['resource'] ?? null, 'read');
        $record = $resource === null ? null : $gateway->find($resource, $arguments['key'] ?? null);

        if ($resource === null || $record === null) {
            return $this->denied();
        }

        return [ToolCall::OK, Response::json($gateway->read($resource, $record, withActions: false))];
    }
}
