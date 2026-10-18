# Changelog

All notable changes to `alfasix/alfakit-ai` are documented in this file.

## Unreleased

- Works without a provider key: `AiPlugin::isConfigured()` checks the provider configuration (no network call); AI modals, the writing assistant, "Ask AI", the copilot and the usage page show "AI isn't set up yet. Run `php artisan alfakit:ai-setup`…" to users allowed by `setupHintVisible()` (default: the local environment) and "AI is not available right now" to everyone else, instead of provider errors.
- `AiPlugin::demoResponses()`: without a key, every AI feature answers with deterministic, labelled sample answers made locally (`Support\DemoResponder`), including a copilot that runs its real tools for simple commands; logged as provider `demo` at no cost. Copilot messages carry `demo: true` (a "Sample answer" badge in React, Vue and Svelte) and the copilot schema a `notice`.
- Initial release: `AiPlugin` (per-panel switch, per-feature providers and models, redaction, rate limits, pricing), `AiAction` with `SummarizeAction`, `TranslateAction`, `ExtractIntoFieldsAction` and `ClassifyAction`, `->aiAssist()` on text fields, `Table::aiFilters()` (natural-language filters validated against the table's own filters and query builder constraints), the panel copilot (search, read, navigate and confirmed actions, per-user conversations, tool-call audit log), an MCP server per panel (`->mcp()`, needs laravel/mcp), and an AI usage resource with totals.
