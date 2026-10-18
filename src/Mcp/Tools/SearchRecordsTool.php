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
class SearchRecordsTool extends McpTool
{
    protected string $name = 'search_records';

    protected string $description = 'Search one resource\'s records (or list the latest with an empty query). Returns key, title and url of each record the user may view.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('The resource slug from list_resources.')->required(),
            'query' => $schema->string()->description('Words to search for; empty for the latest records.'),
            'limit' => $schema->integer()->description('At most 25.'),
        ];
    }

    protected function run(PanelGateway $gateway, McpAccess $access, array $arguments): array
    {
        $resource = $this->resource($gateway, $access, $arguments['resource'] ?? null, 'read');

        if ($resource === null) {
            return $this->denied();
        }

        $query = is_string($arguments['query'] ?? null) ? mb_substr($arguments['query'], 0, 200) : '';
        $limit = is_numeric($arguments['limit'] ?? null) ? (int) $arguments['limit'] : 10;

        return [ToolCall::OK, Response::json(['results' => $gateway->search($query, $resource::getSlug(), $limit)])];
    }
}
