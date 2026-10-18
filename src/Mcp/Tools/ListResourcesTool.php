<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Mcp\Tools;

use AlfaSix\AlfakitAi\Mcp\McpAccess;
use AlfaSix\AlfakitAi\Models\ToolCall;
use AlfaSix\AlfakitAi\Support\PanelGateway;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListResourcesTool extends McpTool
{
    protected string $name = 'list_resources';

    protected string $description = 'List the resources (record types) of the panel the user can see, with their fields and whether records can be created.';

    protected function run(PanelGateway $gateway, McpAccess $access, array $arguments): array
    {
        $resources = array_values(array_filter($gateway->catalogue(), static fn (array $entry): bool => $access->allows((string) $entry['resource'], 'read')));

        return [ToolCall::OK, Response::json(['resources' => $resources])];
    }
}
