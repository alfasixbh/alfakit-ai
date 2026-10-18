<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Enums;

/**
 * The parts of the plugin. Each can be switched off, and can use its own provider and model
 * (`AiPlugin::make()->provider('anthropic', feature: AiFeature::Copilot)`).
 */
enum AiFeature: string
{
    /** `AiAction` and the prebuilt actions (summarize, translate, extract, classify). */
    case Actions = 'actions';

    /** `->aiAssist()` on text fields. */
    case Assist = 'assist';

    /** `Table::aiFilters()`. */
    case Filters = 'filters';

    /** The panel copilot. */
    case Copilot = 'copilot';

    /** The MCP server (`->mcp()`). */
    case Mcp = 'mcp';

    public function getLabel(): string
    {
        return (string) __('alfakit-ai::ai.features.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
