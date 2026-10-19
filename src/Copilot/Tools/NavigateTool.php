<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Copilot\Tools;

use AlfaSix\AlfakitAi\Models\Message;
use AlfaSix\AlfakitAi\Models\ToolCall;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * Offer the user a link to a page of the panel (a record, a list, a create form). The
 * link is shown in the chat; nothing navigates by itself.
 */
class NavigateTool extends CopilotTool
{
    public function name(): string
    {
        return 'navigate';
    }

    public function description(): string
    {
        return 'Show the user a link to a page of this panel: a resource list ("index"), the create form ("create"), or a record ("view"/"edit" with its key). The user clicks it; it does not navigate by itself.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('The resource slug.')->required(),
            'page' => $schema->string()->enum(['index', 'create', 'view', 'edit'])->required(),
            'key' => $schema->string()->description('The record key for view/edit, else "".')->required(),
        ];
    }

    protected function run(array $arguments): array
    {
        $gateway = $this->turn->gateway;
        $resource = $gateway->resource(self::string($arguments['resource'] ?? ''));
        $page = self::string($arguments['page'] ?? 'index');

        if ($resource === null || ! in_array($page, ['index', 'create', 'view', 'edit'], true) || ! $resource::hasPage($page)) {
            return $this->denied('That page does not exist.');
        }

        $record = null;

        if (in_array($page, ['view', 'edit'], true)) {
            $record = $gateway->find($resource, $arguments['key'] ?? null);

            if ($record === null || ($page === 'edit' && ! $resource::canEdit($record))) {
                return $this->denied('Not found, or the user may not open it.');
            }
        } elseif ($page === 'create' && ! $resource::canCreate()) {
            return $this->denied('The user may not create these records.');
        }

        try {
            $url = $resource::getUrl($page, $record === null ? [] : ['record' => $record], panel: $gateway->getPanel());
        } catch (Throwable) {
            return $this->denied('That page does not exist.');
        }

        $label = match ($page) {
            'index' => $resource::getPluralModelLabel(),
            'create' => (string) __('alfakit-ai::ai.copilot.new_record', ['label' => $resource::getModelLabel()]),
            default => $resource::getModelLabel().' '.($resource::hasRecordTitle() ? $resource::getRecordTitle($record) : $record->getKey()),
        };

        $this->turn->message($this->turn->conversation->messages()->create([
            'role' => Message::NAVIGATION,
            'content' => trim($label),
            'meta' => ['url' => $url, 'resource' => $resource::getSlug(), 'page' => $page],
        ]));

        return [ToolCall::OK, ['shown' => true, 'url' => $url]];
    }
}
