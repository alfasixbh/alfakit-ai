# AlfaKit AI

<p><picture><source media="(prefers-color-scheme: dark)" srcset="../../../art/alfasix/alfa-six-micro-white.svg"><img src="../../../art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

AI for [AlfaKit](https://alfakit.dev) panels on [laravel/ai](https://github.com/laravel/ai), with the panel's own permissions: one-line AI actions (summarise, translate, extract into fields, classify), a writing assistant on text fields, natural-language table filters validated against the table's own filters, a copilot that searches, reads and proposes actions the user confirms, an MCP server for your resources, and usage logging with cost estimates. It renders in React, Vue and Svelte.

## Installation

```bash
composer require alfasix/alfakit-ai
php artisan vendor:publish --tag=alfakit-ai-migrations
php artisan migrate
php artisan alfakit:ai-setup
```

The MCP server also needs `composer require laravel/mcp`.

## Register

```php
use AlfaSix\AlfakitAi\AiPlugin;

$panel->plugin(
    AiPlugin::make()
        ->copilot()
        ->mcp()
        ->redact(['email', 'phone'])
        ->pricing(['gpt-4.1-mini' => ['input' => 0.4, 'output' => 1.6]]),
);
```

```php
use AlfaSix\AlfakitAi\Actions\SummarizeAction;

$table->recordActions([SummarizeAction::make()])->aiFilters();

Textarea::make('notes')->aiAssist();
```

Read the [documentation](https://alfakit.dev/docs/plugins/ai) for every option, the safety model and testing with laravel/ai fakes.

## License

MIT. See [LICENSE.md](LICENSE.md).
