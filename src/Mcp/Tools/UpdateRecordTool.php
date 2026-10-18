<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Mcp\Tools;

use AlfaSix\AlfakitAi\Mcp\McpAccess;
use AlfaSix\AlfakitAi\Models\ToolCall;
use AlfaSix\AlfakitAi\Support\PanelGateway;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateRecordTool extends McpTool
{
    protected string $name = 'update_record';

    protected string $description = 'Update a record with the resource\'s edit form (same validation and hooks as the panel); fields left out keep their values. Call without data to get the fields.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->required(),
            'key' => $schema->string()->required(),
            'data' => $schema->object()->description('Form field values to change, by field name.'),
        ];
    }

    protected function run(PanelGateway $gateway, McpAccess $access, array $arguments): array
    {
        $resource = $this->resource($gateway, $access, $arguments['resource'] ?? null, 'update');
        $record = $resource === null ? null : $gateway->find($resource, $arguments['key'] ?? null);

        if ($resource === null || $record === null || ! $resource::canEdit($record)) {
            return $this->denied();
        }

        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

        if ($data === []) {
            return [ToolCall::OK, Response::json(['fields' => $gateway->writableFields($resource, $record)])];
        }

        $updated = $gateway->update($resource, $record, $data);

        return [ToolCall::OK, Response::json(['updated' => $gateway->read($resource, $updated->refresh(), withActions: false)])];
    }
}
