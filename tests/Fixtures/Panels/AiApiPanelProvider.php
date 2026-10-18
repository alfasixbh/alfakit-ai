<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource;
use AlfaSix\AlfakitApi\ApiPlugin;

/**
 * MCP next to alfasix/alfakit-api: clients use the API plugin's tokens.
 */
class AiApiPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ai-api')
            ->path('ai-api')
            ->login()
            ->middleware(['web'])
            ->resources([OrderResource::class])
            ->plugins([
                AiPlugin::make()->mcp(),
                ApiPlugin::make()->prefix('ai-api-rest')->tokensPage(false)->documentationPage(false),
            ]);
    }
}
