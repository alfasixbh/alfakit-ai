<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Components;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use Illuminate\Support\Facades\Route;

/**
 * The copilot button and chat slide-over, rendered by the `ai:copilot` registry component
 * of each renderer (its chat code loads the first time it opens). Added to the topbar by
 * `AiPlugin::copilot()`; everything it shows comes from the copilot endpoints.
 */
class Copilot extends Component
{
    final public function __construct()
    {
        $this->type(AiPlugin::COPILOT_COMPONENT);
        $this->key('ai-copilot');
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    /**
     * Whether the copilot's endpoints can be built for the current page: on a tenancy
     * panel they need the current tenant, which pages such as the profile and tenant
     * registration don't have.
     */
    public static function isAvailable(?Panel $panel = null): bool
    {
        $panel ??= alfakit()->getCurrentOrDefaultPanel();

        if (! $panel->hasTenancy() || $panel->getTenant() !== null) {
            return true;
        }

        $route = Route::getRoutes()->getByName($panel->generateRouteName('ai.copilot.conversations'));

        return $route === null || ! in_array('tenant', $route->parameterNames(), true);
    }

    protected function getSchemaProps(): array
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $plugin = AiPlugin::get($panel);
        $placeholder = '__ID__';

        return [
            'endpoints' => [
                'conversations' => $panel->route('ai.copilot.conversations'),
                'conversation' => $panel->route('ai.copilot.show', ['conversation' => $placeholder]),
                'send' => $panel->route('ai.copilot.send'),
                // `streaming()`: answers arrive as they are written.
                'stream' => $plugin?->isStreaming() ? $panel->route('ai.copilot.stream') : null,
                'confirm' => $panel->route('ai.copilot.confirm', ['message' => $placeholder]),
                'reject' => $panel->route('ai.copilot.reject', ['message' => $placeholder]),
                'delete' => $panel->route('ai.copilot.destroy', ['conversation' => $placeholder]),
            ],
            'placeholder' => $placeholder,
            'keyBindings' => $plugin?->getCopilotKeyBindings() ?: null,
            'currency' => $plugin?->hasPricing() ? $plugin->getCurrency() : null,
            'canUse' => $plugin?->canUse(AiFeature::Copilot) ?? false,
            // No provider key: sample answers, or "AI isn't set up yet" / "not available".
            'notice' => ($notice = $plugin?->getSetupNotice(AiFeature::Copilot)) === null ? null : [
                'status' => $notice['status'],
                'heading' => $notice['heading'],
                'description' => $notice['description'],
            ],
        ];
    }
}
