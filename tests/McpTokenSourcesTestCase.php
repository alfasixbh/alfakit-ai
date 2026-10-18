<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests;

use AlfaSix\AlfakitAi\Tests\Fixtures\Panels\AiApiPanelProvider;
use AlfaSix\AlfakitApi\AlfakitApiServiceProvider;

/**
 * MCP authentication with Sanctum and with alfasix/alfakit-api tokens (both dev
 * dependencies): adds the API plugin, an MCP panel that uses it, and both token tables.
 */
abstract class McpTokenSourcesTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AlfakitApiServiceProvider::class,
            AiApiPanelProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/api/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__, 4).'/vendor/laravel/sanctum/database/migrations');
    }
}
