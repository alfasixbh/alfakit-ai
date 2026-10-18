<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests;

use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitAi\AlfakitAiServiceProvider;
use AlfaSix\AlfakitAi\Tests\Fixtures\Models\AiUser;
use AlfaSix\AlfakitAi\Tests\Fixtures\Models\Order;
use AlfaSix\AlfakitAi\Tests\Fixtures\Panels\AiPanelProvider;
use AlfaSix\AlfakitAi\Tests\Fixtures\Panels\AiPlainPanelProvider;
use AlfaSix\AlfakitAi\Tests\Fixtures\Panels\AiTenantPanelProvider;
use AlfaSix\AlfakitAi\Tests\Fixtures\Policies\OrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use LogicException;
use Pest\TestSuite;

abstract class TestCase extends AlfakitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::policy(Order::class, OrderPolicy::class);
        OrderPolicy::$canCreate = true;
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AiServiceProvider::class,
            McpServiceProvider::class,
            AlfakitAiServiceProvider::class,
            AiPanelProvider::class,
            AiTenantPanelProvider::class,
            AiPlainPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', AiUser::class);
        // Never a real provider: every agent is faked in the tests.
        $app['config']->set('ai.default', 'openai');
        $app['config']->set('ai.providers.openai.key', 'sk-test-not-a-real-key');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }

    /**
     * The running test (Pest binds `$this` to it; this keeps static analysis typed).
     */
    public static function current(): static
    {
        $test = TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new LogicException('No test of this suite is running.');
        }

        return $test;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public function inertiaGet(string $url): TestResponse
    {
        return $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
    }

    /**
     * @return array<string, mixed>
     */
    public function inertiaProps(string $url): array
    {
        return $this->inertiaGet($url)->assertOk()->json('props');
    }
}
