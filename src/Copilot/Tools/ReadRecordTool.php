<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Copilot\Tools;

use AlfaSix\AlfakitAi\Models\ToolCall;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Read one record: the fields its resource shows (redacted), its URL and the actions the
 * user may run on it. Denied unless the user may view it.
 */
class ReadRecordTool extends CopilotTool
{
    public function name(): string
    {
        return 'read_record';
    }

    public function description(): string
    {
        return 'Read one record the user can view: its visible fields, url and the actions the user may run on it (with the inputs each action takes).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('The resource slug.')->required(),
            'key' => $schema->string()->description('The record key from search results.')->required(),
        ];
    }

    protected function run(array $arguments): array
    {
        $resource = $this->turn->gateway->resource(self::string($arguments['resource'] ?? ''));

        if ($resource === null) {
            return $this->denied('Unknown resource.');
        }

        $record = $this->turn->gateway->find($resource, $arguments['key'] ?? null);

        if ($record === null) {
            return $this->denied('Not found, or the user may not view it.');
        }

        $this->turn->activity($this->name(), (string) __('alfakit-ai::ai.copilot.activity.read', ['record' => $resource::getModelLabel().' '.(($resource::hasRecordTitle() ? $resource::getRecordTitle($record) : null) ?? $record->getKey())]));

        return [ToolCall::OK, $this->turn->gateway->read($resource, $record)];
    }
}
