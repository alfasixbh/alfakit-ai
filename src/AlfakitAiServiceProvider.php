<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi;

use AlfaSix\Alfakit\Http\Middleware\SetUpPanel;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitAi\Actions\AiAssistAction;
use AlfaSix\AlfakitAi\Actions\AiFilterAction;
use AlfaSix\AlfakitAi\Commands\McpTokenCommand;
use AlfaSix\AlfakitAi\Copilot\CopilotService;
use AlfaSix\AlfakitAi\Http\Middleware\AuthenticateMcpToken;
use AlfaSix\AlfakitAi\Mcp\McpServer;
use AlfaSix\AlfakitAi\Support\AiRunner;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Translations, migrations, the `->aiAssist()` and `Table::aiFilters()` macros (inert until
 * the plugin is on the current panel) and the MCP routes of panels with `->mcp()`.
 */
class AlfakitAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiRunner::class);
        $this->app->bind(CopilotService::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alfakit-ai');

        $this->registerMacros();

        // After AlfaKit has registered the panels (its own `booted` callback).
        $this->app->booted(function (): void {
            if (! $this->app->routesAreCached()) {
                $this->registerMcpRoutes();
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([McpTokenCommand::class]);

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'alfakit-ai-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/alfakit-ai'),
            ], 'alfakit-ai-translations');
        }
    }

    protected function registerMacros(): void
    {
        /*
         * `TextInput::make('title')->aiAssist()`, or `->aiAssist(['shorter', 'grammar'])`.
         * Adds the writing assistant as a hint action; hidden unless the plugin is on.
         */
        Component::macro('aiAssist', function (array|bool $tasks = true): Component {
            /** @var Component $this */
            if ($tasks === false) {
                return $this;
            }

            if (! method_exists($this, 'hintAction')) {
                throw new InvalidArgumentException('aiAssist() works on fields with hint actions (TextInput, Textarea, RichEditor, MarkdownEditor).');
            }

            $action = AiAssistAction::make();

            if (is_array($tasks)) {
                $action->tasks($tasks);
            }

            return $this->hintAction($action);
        });

        /*
         * `$table->headerActions([...])->aiFilters()`: an "Ask AI" header action that turns a
         * sentence into the table's filters. Call it after `headerActions()`.
         */
        Table::macro('aiFilters', function (bool $condition = true): Table {
            /** @var Table $this */
            if (! $condition) {
                return $this;
            }

            $existing = $this->headerActions;

            $this->headerActions = function () use ($existing): array {
                /** @var Table $this */
                $actions = $this->evaluate($existing) ?? [];

                return [...array_values(is_array($actions) ? $actions : []), AiFilterAction::make()];
            };

            return $this;
        });
    }

    protected function registerMcpRoutes(): void
    {
        if (! class_exists('Laravel\\Mcp\\Facades\\Mcp')) {
            return;
        }

        foreach (alfakit()->getPanels() as $panel) {
            $plugin = AiPlugin::get($panel);

            if ($plugin === null || ! $plugin->hasMcpRoutes()) {
                continue;
            }

            $path = trim(trim($panel->getPath(), '/').'/'.$plugin->getMcpPath(), '/').($panel->hasTenancy() ? '/{tenant}' : '');

            \Laravel\Mcp\Facades\Mcp::web($path, McpServer::class)
                ->middleware([SetUpPanel::class.':'.$panel->getId(), AuthenticateMcpToken::class.':'.$panel->getId()])
                ->name('alfakit-ai.'.$panel->getId().'.mcp');
        }
    }
}
