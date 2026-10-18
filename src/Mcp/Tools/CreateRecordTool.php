<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Mcp\Tools;

use AlfaSix\AlfakitAi\Mcp\McpAccess;
use AlfaSix\AlfakitAi\Models\ToolCall;
use AlfaSix\AlfakitAi\Support\PanelGateway;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class CreateRecordTool extends McpTool
{
    protected string $name = 'create_record';

    protected string $description = 'Create a record with the resource\'s create form (same validation, defaults and hooks as the panel). "data" holds the form field values; call without data to get the fields.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->required(),
            'data' => $schema->object()->description('Form field values by field name.'),
        ];
    }

    protected function run(PanelGateway $gateway, McpAccess $access, array $arguments): array
    {
        $resource = $this->resource($gateway, $access, $arguments['resource'] ?? null, 'create');

        if ($resource === null || ! $resource::canCreate()) {
            return $this->denied();
        }

        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

        if ($data === []) {
            return [ToolCall::OK, Response::json(['fields' => $gateway->writableFields($resource)])];
        }

        $record = $gateway->create($resource, $data);

        return [ToolCall::OK, Response::json(['created' => $gateway->read($resource, $record->refresh(), withActions: false)])];
    }
}
