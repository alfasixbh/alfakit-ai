<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Copilot\Tools;

use AlfaSix\AlfakitAi\Models\ToolCall;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Find records by text (the resources' searchable attributes), or the latest records of a
 * resource. Only records the user may view.
 */
class SearchRecordsTool extends CopilotTool
{
    public function name(): string
    {
        return 'search_records';
    }

    public function description(): string
    {
        return 'Search the panel\'s records the user can see. Give "query" (words to match; empty for the latest records) and optionally "resource" (a resource slug from the catalogue). Returns resource, key, title and url of each match.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Words to search for; "" for the latest records.')->required(),
            'resource' => $schema->string()->description('A resource slug, or "" to search every resource.')->required(),
        ];
    }

    protected function run(array $arguments): array
    {
        $query = mb_substr(self::string($arguments['query'] ?? ''), 0, 200);
        $slug = self::string($arguments['resource'] ?? '');

        if ($slug !== '' && $this->turn->gateway->resource($slug) === null) {
            return $this->denied('Unknown resource. Use a slug from the catalogue.');
        }

        $results = $this->turn->gateway->search($query, $slug === '' ? null : $slug);

        $this->turn->activity($this->name(), $query === '' ? (string) __('alfakit-ai::ai.copilot.activity.listed') : (string) __('alfakit-ai::ai.copilot.activity.searched', ['query' => $query]));

        return [ToolCall::OK, ['results' => $results]];
    }
}
