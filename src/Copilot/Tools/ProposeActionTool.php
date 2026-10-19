<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Copilot\Tools;

use AlfaSix\AlfakitAi\Models\Message;
use AlfaSix\AlfakitAi\Models\ToolCall;
use AlfaSix\AlfakitAi\Support\OutputSchema;
use AlfaSix\AlfakitAi\Support\Redactor;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Propose running a record action. It never runs here: a confirmation card is shown and
 * the action runs only when the user clicks "Confirm" (checked and authorised again then).
 */
class ProposeActionTool extends CopilotTool
{
    public function name(): string
    {
        return 'propose_action';
    }

    public function description(): string
    {
        return 'Propose running one of a record\'s actions (from read_record). The user sees a confirmation card and decides; the action has NOT run when this returns. "inputs_json" is a JSON object with the action\'s inputs, or "{}".';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->required(),
            'key' => $schema->string()->required(),
            'action' => $schema->string()->description('The action name from read_record.')->required(),
            'inputs_json' => $schema->string()->description('JSON object of the action inputs, or "{}".')->required(),
            'reason' => $schema->string()->description('One sentence for the user: why this action.')->required(),
        ];
    }

    protected function run(array $arguments): array
    {
        $gateway = $this->turn->gateway;
        $resource = $gateway->resource(self::string($arguments['resource'] ?? ''));

        if ($resource === null) {
            return $this->denied('Unknown resource.');
        }

        $record = $gateway->find($resource, $arguments['key'] ?? null);

        if ($record === null) {
            return $this->denied('Not found, or the user may not view it.');
        }

        $name = self::string($arguments['action'] ?? '');
        $action = $gateway->actions($resource, $record)[$name] ?? null;

        if ($action === null) {
            return $this->denied('The user may not run that action on this record.');
        }

        $inputs = json_decode(self::string($arguments['inputs_json'] ?? '{}') ?: '{}', true);
        $inputs = is_array($inputs) && ! array_is_list($inputs) ? $inputs : [];
        $fields = OutputSchema::fields($action->getFormComponents());
        $inputs = OutputSchema::coerce($fields, $inputs);

        $display = [];

        foreach (Redactor::make()->redact($inputs) as $path => $value) {
            $display[] = ['label' => (string) ($fields[$path]->getLabel() ?? $path), 'value' => is_scalar($value) || $value === null ? $value : json_encode($value)];
        }

        $message = $this->turn->conversation->messages()->create([
            'role' => Message::ACTION,
            'content' => mb_substr(self::string($arguments['reason'] ?? ''), 0, 500),
            'meta' => [
                'status' => Message::PENDING,
                'resource' => $resource::getSlug(),
                'key' => $gateway->routeKey($resource, $record),
                'action' => $name,
                'label' => $action->getLabel(),
                'record' => $resource::getModelLabel().' '.(($resource::hasRecordTitle() ? $resource::getRecordTitle($record) : null) ?? $record->getKey()),
                'url' => $gateway->recordUrl($resource, $record),
                'data' => $inputs,
                'inputs' => $display,
                'color' => $action->getColor(),
            ],
        ]);

        $this->turn->message($message);

        return [ToolCall::PROPOSED, [
            'status' => 'awaiting_user_confirmation',
            'note' => 'The action has not run. Tell the user to review the card and confirm or cancel it. Do not say it is done.',
            'proposal' => $message->getKey(),
        ]];
    }
}
