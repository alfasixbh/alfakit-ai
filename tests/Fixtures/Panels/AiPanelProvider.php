<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource;

class AiPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ai')
            ->path('ai')
            ->login()
            ->middleware(['web'])
            ->resources([OrderResource::class])
            ->plugin(
                AiPlugin::make()
                    ->copilot()
                    ->mcp()
                    ->redact(['email'])
                    ->pricing(['gpt-test' => ['input' => 1, 'output' => 2]]),
            );
    }
}
