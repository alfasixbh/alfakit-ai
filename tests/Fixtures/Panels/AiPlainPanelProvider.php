<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource;

/**
 * The plugin with its defaults: no copilot, no MCP.
 */
class AiPlainPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ai-plain')
            ->path('ai-plain')
            ->login()
            ->middleware(['web'])
            ->resources([OrderResource::class])
            ->plugin(AiPlugin::make());
    }
}
