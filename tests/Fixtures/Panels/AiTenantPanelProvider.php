<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource;

class AiTenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ai-team')
            ->path('ai-team')
            ->login()
            ->profile(isSimple: false)
            ->middleware(['web'])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->resources([OrderResource::class])
            ->plugin(AiPlugin::make()->copilot()->mcp());
    }
}
