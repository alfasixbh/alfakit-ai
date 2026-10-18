<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Theming\Slot;
use AlfaSix\AlfakitAi\Components\Copilot;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Http\Controllers\CopilotController;
use AlfaSix\AlfakitAi\Http\Controllers\StreamController;
use AlfaSix\AlfakitAi\Resources\AiUsageResource;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use InvalidArgumentException;

/**
 * AI for one panel, through laravel/ai. Nothing runs unless the plugin is registered on the
 * panel and the feature is on: `AiAction`, `->aiAssist()` and `Table::aiFilters()` are on
 * with the plugin (you still add them where you want them); the copilot and the MCP server
 * are off until `->copilot()` / `->mcp()`.
 *
 * ```php
 * $panel->plugin(
 *     AiPlugin::make()
 *         ->provider('openai', 'gpt-4.1-mini')
 *         ->provider('anthropic', 'claude-sonnet-4-5', feature: AiFeature::Copilot)
 *         ->copilot()
 *         ->mcp()
 *         ->redact(['email', 'phone', Customer::class.'.notes'])
 *         ->rateLimit(30)
 *         ->pricing(['gpt-4.1-mini' => ['input' => 0.4, 'output' => 1.6]]),
 * );
 * ```
 *
 * Providers and models default to `config('ai.default')` and the provider's default model,
 * which `php artisan alfakit:ai-setup` writes. Without a provider key every AI feature says
 * so (`isConfigured()`, `setupHintVisible()`), or answers with labelled sample answers made
 * locally with `->demoResponses()`.
 */
class AiPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'ai';

    /** The copilot's registry key in the renderers. */
    public const COPILOT_COMPONENT = 'ai:copilot';

    /** The registry key of the component that streams an answer into a modal form. */
    public const STREAM_COMPONENT = 'ai:stream';

    /** Sensitive attribute names that never reach a prompt (see `withoutDefaultRedactions()`). */
    public const DEFAULT_REDACTIONS = [
        'password', 'password_*', '*_password', 'remember_token', 'token', '*_token', '*secret*',
        'api_key', '*_api_key', 'two_factor_*', 'ssn', 'social_security_number', 'card_number', 'cvv', 'cvc', 'iban',
    ];

    protected bool|Closure $isEnabled = true;

    protected ?Closure $authorizeUsing = null;

    /** @var array<string, array{provider: string|array<int|string, string>|null, model: ?string}> */
    protected array $providers = [];

    /** @var array<string, bool> */
    protected array $disabledFeatures = [];

    protected bool|Closure $hasCopilot = false;

    protected bool|Closure $hasMcp = false;

    /** @var list<string>|null */
    protected ?array $mcpTokenSources = null;

    protected bool|Closure $isStreaming = false;

    protected int $demoStreamDelay = 30;

    protected string $mcpPath = 'mcp';

    /** @var array<int, class-string<resource>>|Closure|null */
    protected array|Closure|null $exposedResources = null;

    protected ?Closure $copilotActionsUsing = null;

    protected string|Closure|null $copilotInstructions = null;

    /** @var array<int, string> */
    protected array $copilotKeyBindings = ['mod+j'];

    /** @var array<int, string> */
    protected array $redactions = [];

    protected bool $hasDefaultRedactions = true;

    protected ?Closure $redactUsing = null;

    /** @var array<string, int> Requests per minute per user: `*` for all features together. */
    protected array $rateLimits = ['*' => 30, 'copilot' => 10];

    /** @var array<string, array{input: float, output: float}> USD per million tokens, by model. */
    protected array $pricing = [];

    protected string $currency = 'USD';

    protected int $timeout = 60;

    protected int $copilotMaxSteps = 8;

    protected int $copilotHistory = 20;

    /** @var class-string<AiUsageResource>|null */
    protected ?string $usageResource = AiUsageResource::class;

    protected ?Closure $canViewAllUsageUsing = null;

    protected string|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    /** @var class-string<Model>|null */
    protected ?string $mcpTokenModel = null;

    protected bool|Closure $hasDemoResponses = false;

    protected bool|Closure|null $isSetupHintVisible = null;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin of `$panel` (or the current panel), or null when it isn't registered there.
     */
    public static function get(?Panel $panel = null): ?static
    {
        $panel ??= alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        if ($panel === null || ! $panel->hasPlugin(self::ID)) {
            return null;
        }

        $plugin = $panel->getPlugin(self::ID);

        return $plugin instanceof static ? $plugin : null;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->frontendTranslations(['ai' => 'alfakit-ai::ai.ui']);

        if ($this->usageResource !== null) {
            $panel->resources([$this->usageResource]);
        }

        // `streaming()`: answers of AiAction and aiAssist() stream through this endpoint (it
        // answers 404 while streaming is off, so the switch can be a closure).
        $panel->authenticatedRoutes(static function (Router $router): void {
            $router->post('_ai/stream', StreamController::class)->name('ai.stream');
        });

        // The copilot's endpoints and button exist only on panels that turned it on.
        if ($this->hasCopilot !== false) {
            $panel->authenticatedRoutes(static function (Router $router): void {
                $router->prefix('_ai/copilot')->name('ai.copilot.')->group(static function (Router $router): void {
                    $router->get('/conversations', [CopilotController::class, 'conversations'])->name('conversations');
                    $router->get('/conversations/{conversation}', [CopilotController::class, 'show'])->name('show')->whereNumber('conversation');
                    $router->delete('/conversations/{conversation}', [CopilotController::class, 'destroy'])->name('destroy')->whereNumber('conversation');
                    $router->post('/messages', [CopilotController::class, 'send'])->name('send');
                    $router->post('/actions/{message}/confirm', [CopilotController::class, 'confirm'])->name('confirm')->whereNumber('message');
                    $router->post('/actions/{message}/reject', [CopilotController::class, 'reject'])->name('reject')->whereNumber('message');
                });
            });

            $panel->authenticatedRoutes(static function (Router $router): void {
                $router->post('_ai/copilot/messages/stream', [CopilotController::class, 'stream'])->name('ai.copilot.stream');
            });

            // Tenancy pages without a current tenant (profile, tenant registration) have no
            // copilot: its endpoints live under the tenant's URL.
            $panel->slot(Slot::TOPBAR_END, fn (): ?Copilot => Copilot::isAvailable() && $this->canUse(AiFeature::Copilot) ? Copilot::make() : null);
        }
    }

    public function boot(Panel $panel): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        return match ($parameterName) {
            'panel' => [$panel],
            'user' => [$panel?->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    // ---------------------------------------------------------------- Switches

    /**
     * The panel-wide switch: `->enabled(fn (?Model $tenant) => $tenant?->plan === 'pro')`.
     */
    public function enabled(bool|Closure $condition = true): static
    {
        $this->isEnabled = $condition;

        return $this;
    }

    /**
     * Who may use AI at all on this panel: `fn (Model $user): bool => $user->can('use-ai')`.
     * Everyone signed in by default.
     */
    public function authorize(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * Switch features off (all are on with the plugin, except the copilot and MCP).
     */
    public function disable(AiFeature ...$features): static
    {
        foreach ($features as $feature) {
            $this->disabledFeatures[$feature->value] = true;
        }

        return $this;
    }

    /**
     * The chat slide-over in the topbar (lazy-loaded), with tools to search, read, navigate
     * and propose actions the user confirms.
     */
    public function copilot(bool|Closure $condition = true): static
    {
        $this->hasCopilot = $condition;

        return $this;
    }

    /**
     * Stream answers as they are written: `AiAction` text answers, `aiAssist()` suggestions
     * and copilot replies appear word by word (with a "Stop" button) instead of all at once.
     * Answers go through `/{panel}/_ai/stream`, authorised by a one-time encrypted token
     * issued with the modal; sample answers stream too, pausing `$demoDelayMs` between
     * chunks. Providers that can't stream are asked once and their answer sent in chunks.
     */
    public function streaming(bool|Closure $condition = true, int $demoDelayMs = 30): static
    {
        $this->isStreaming = $condition;
        $this->demoStreamDelay = max(0, $demoDelayMs);

        return $this;
    }

    public function isStreaming(): bool
    {
        return (bool) $this->evaluate($this->isStreaming);
    }

    public function getDemoStreamDelay(): int
    {
        return $this->demoStreamDelay;
    }

    /**
     * An MCP server for this panel at `/{panel}/{path}` (`/{panel}/{path}/{tenant}` on
     * tenancy panels). Needs laravel/mcp; clients authenticate with a bearer token.
     */
    public function mcp(bool|Closure $condition = true, string $path = 'mcp'): static
    {
        $this->hasMcp = $condition;
        $this->mcpPath = trim($path, '/') ?: 'mcp';

        return $this;
    }

    public function isEnabled(?AiFeature $feature = null): bool
    {
        if (! $this->evaluate($this->isEnabled)) {
            return false;
        }

        if ($feature === null) {
            return true;
        }

        if (isset($this->disabledFeatures[$feature->value])) {
            return false;
        }

        return match ($feature) {
            AiFeature::Copilot => (bool) $this->evaluate($this->hasCopilot),
            AiFeature::Mcp => (bool) $this->evaluate($this->hasMcp) && class_exists('Laravel\\Mcp\\Server'),
            default => true,
        };
    }

    /**
     * Whether the MCP routes are registered (the switch is checked again per request).
     */
    public function hasMcpRoutes(): bool
    {
        return $this->hasMcp !== false && class_exists('Laravel\\Mcp\\Server');
    }

    public function getMcpPath(): string
    {
        return $this->mcpPath;
    }

    /**
     * Whether `$user` (default: the signed-in user) may use `$feature` now.
     */
    public function canUse(AiFeature $feature, ?Authenticatable $user = null): bool
    {
        if (! $this->isEnabled($feature)) {
            return false;
        }

        $user ??= (alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel())?->auth()->user();

        if (! $user instanceof Model) {
            return false;
        }

        if ($this->authorizeUsing === null) {
            return true;
        }

        return (bool) $this->evaluate($this->authorizeUsing, ['user' => $user, 'feature' => $feature], [Model::class => $user, $user::class => $user, AiFeature::class => $feature]);
    }

    // ---------------------------------------------------------------- Providers

    /**
     * The provider (and model) for every feature, or for one: `->provider('anthropic',
     * 'claude-sonnet-4-5', feature: AiFeature::Copilot)`. A list fails over in order.
     *
     * @param  string|array<int|string, string>|null  $provider
     */
    public function provider(string|array|null $provider, ?string $model = null, ?AiFeature $feature = null): static
    {
        $this->providers[$feature->value ?? '*'] = ['provider' => $provider, 'model' => $model];

        return $this;
    }

    /**
     * Only the model, for every feature or for one.
     */
    public function model(?string $model, ?AiFeature $feature = null): static
    {
        $key = $feature->value ?? '*';
        $this->providers[$key] = ['provider' => $this->providers[$key]['provider'] ?? null, 'model' => $model];

        return $this;
    }

    /**
     * @return string|array<int|string, string>|null
     */
    public function getProvider(AiFeature $feature): string|array|null
    {
        return $this->providers[$feature->value]['provider'] ?? $this->providers['*']['provider'] ?? null;
    }

    public function getModel(AiFeature $feature): ?string
    {
        if (isset($this->providers[$feature->value])) {
            return $this->providers[$feature->value]['model'] ?? null;
        }

        return $this->providers['*']['model'] ?? null;
    }

    /**
     * Seconds before a provider request gives up (60 by default).
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    // ---------------------------------------------------------------- Setup and demo answers

    /**
     * Whether the provider of `$feature` (or `$provider`) has what it needs in
     * `config('ai.providers')`: a key (a URL for Ollama and OpenAI-compatible servers). Only
     * the configuration is read; no request is made. With a failover list, one usable
     * provider is enough.
     *
     * @param  string|array<int|string, string>|null  $provider
     */
    public function isConfigured(?AiFeature $feature = null, string|array|null $provider = null): bool
    {
        $provider ??= $this->providers[$feature->value ?? '*']['provider'] ?? $this->providers['*']['provider'] ?? null;
        $provider ??= config('ai.default');

        $names = is_array($provider)
            ? array_map(static fn (int|string $key, string $value): string => is_string($key) ? $key : $value, array_keys($provider), array_values($provider))
            : [$provider];

        foreach ($names as $name) {
            if (is_string($name) && $name !== '' && self::isProviderConfigured($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `config('ai.providers.{$name}')` looks usable (no network call).
     */
    public static function isProviderConfigured(string $name): bool
    {
        $config = config('ai.providers.'.$name);

        if (! is_array($config)) {
            return false;
        }

        $filled = static fn (string $key): bool => is_string($config[$key] ?? null) && trim($config[$key]) !== '';

        return match ((string) ($config['driver'] ?? $name)) {
            'ollama', 'openai-compatible' => $filled('url'),
            'azure' => $filled('key') && $filled('url'),
            'bedrock' => $filled('key') || $filled('access_key_id'),
            default => $filled('key'),
        };
    }

    /**
     * Without a provider key, answer every AI feature with deterministic sample answers made
     * locally (no network): summaries from the first sentences, marked sample translations,
     * extracted emails, dates and amounts, filters from simple phrases, a copilot that runs
     * its real tools for "search …", "open …" and "run … on …". Every sample is labelled and
     * logged as provider `demo` at no cost. A configured provider always wins.
     */
    public function demoResponses(bool|Closure $condition = true): static
    {
        $this->hasDemoResponses = $condition;

        return $this;
    }

    public function hasDemoResponses(): bool
    {
        return (bool) $this->evaluate($this->hasDemoResponses);
    }

    /**
     * Whether `$feature` answers with sample answers right now (no provider, demo on).
     */
    /**
     * @param  string|array<int|string, string>|null  $provider  an action's own provider
     */
    public function isDemo(?AiFeature $feature = null, string|array|null $provider = null): bool
    {
        return ! $this->isConfigured($feature, $provider) && $this->hasDemoResponses();
    }

    /**
     * Who sees how to set AI up (`php artisan alfakit:ai-setup`) when no provider is
     * configured: `fn (User $user): bool => $user->is_admin`. Everyone else reads "AI is not
     * available right now". Only in the local environment by default.
     */
    public function setupHintVisible(bool|Closure|null $condition = true): static
    {
        $this->isSetupHintVisible = $condition;

        return $this;
    }

    public function isSetupHintVisible(?Authenticatable $user = null): bool
    {
        if ($this->isSetupHintVisible === null) {
            return app()->isLocal();
        }

        $user ??= (alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel())?->auth()->user();

        if ($this->isSetupHintVisible instanceof Closure && ! $user instanceof Model) {
            return false;
        }

        return (bool) $this->evaluate($this->isSetupHintVisible, ['user' => $user], $user instanceof Model ? [Model::class => $user, $user::class => $user] : []);
    }

    /**
     * What to tell the user when `$feature` has no provider: null when it has one. `status`
     * is `info` for sample answers and `warning` when AI can't answer at all.
     *
     * `$provider` is an action's own provider (`AiAction::provider()`), when it has one.
     *
     * @param  string|array<int|string, string>|null  $provider
     * @return array{status: string, heading: string, description: ?string, hint: bool}|null
     */
    public function getSetupNotice(?AiFeature $feature = null, string|array|null $provider = null): ?array
    {
        if ($this->isConfigured($feature, $provider)) {
            return null;
        }

        $hint = $this->isSetupHintVisible();
        $setup = (string) __('alfakit-ai::ai.setup.hint', ['command' => 'php artisan alfakit:ai-setup']);

        if ($this->hasDemoResponses()) {
            return [
                'status' => 'info',
                'heading' => (string) __('alfakit-ai::ai.demo.label'),
                'description' => (string) __('alfakit-ai::ai.demo.description').($hint ? ' '.$setup : ''),
                'hint' => $hint,
            ];
        }

        return [
            'status' => 'warning',
            'heading' => $hint ? (string) __('alfakit-ai::ai.setup.not_configured') : (string) __('alfakit-ai::ai.setup.unavailable'),
            'description' => $hint ? $setup : null,
            'hint' => $hint,
        ];
    }

    // ---------------------------------------------------------------- Copilot and MCP

    /**
     * The resources the copilot and the MCP server may search, read and act on (every
     * resource of the panel by default). Policies still apply to each record.
     *
     * @param  array<int, class-string<resource>>|Closure|null  $resources
     */
    public function resources(array|Closure|null $resources): static
    {
        $this->exposedResources = $resources;

        return $this;
    }

    /**
     * @return array<int, class-string<resource>>
     */
    public function getResources(Panel $panel): array
    {
        $configured = $this->evaluate($this->exposedResources);
        $resources = is_array($configured) ? $configured : $panel->getResources();

        return array_values(array_filter(
            $resources,
            fn (mixed $resource): bool => is_string($resource) && is_subclass_of($resource, Resource::class) && $resource !== $this->usageResource && ! is_subclass_of($resource, AiUsageResource::class),
        ));
    }

    /**
     * Which record actions of a resource's table the copilot may propose:
     * `fn (Action $action, string $resource): bool => $action->getName() !== 'delete'`.
     * Every proposed action still needs the user's confirmation and authorisation.
     */
    public function copilotActions(?Closure $filter): static
    {
        $this->copilotActionsUsing = $filter;

        return $this;
    }

    public function allowsCopilotAction(\AlfaSix\Alfakit\Actions\Action $action, string $resource): bool
    {
        if ($this->copilotActionsUsing === null) {
            return true;
        }

        return (bool) $this->evaluate($this->copilotActionsUsing, ['action' => $action, 'resource' => $resource], [\AlfaSix\Alfakit\Actions\Action::class => $action]);
    }

    /**
     * Extra instructions for the copilot (your domain, tone, what to avoid).
     */
    public function copilotInstructions(string|Closure|null $instructions): static
    {
        $this->copilotInstructions = $instructions;

        return $this;
    }

    public function getCopilotInstructions(): ?string
    {
        $instructions = $this->evaluate($this->copilotInstructions);

        return is_string($instructions) && trim($instructions) !== '' ? trim($instructions) : null;
    }

    /**
     * The shortcut that opens the copilot (`mod+j` by default; an empty list for none).
     *
     * @param  array<int, string>  $bindings
     */
    public function copilotKeyBindings(array $bindings): static
    {
        $this->copilotKeyBindings = array_values(array_filter($bindings, is_string(...)));

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getCopilotKeyBindings(): array
    {
        return $this->copilotKeyBindings;
    }

    /**
     * How many tool calls and answers one copilot turn may take (8 by default), and how
     * many earlier messages are sent with each question (20).
     */
    public function copilotLimits(int $maxSteps = 8, int $history = 20): static
    {
        $this->copilotMaxSteps = max(1, $maxSteps);
        $this->copilotHistory = max(0, $history);

        return $this;
    }

    public function getCopilotMaxSteps(): int
    {
        return $this->copilotMaxSteps;
    }

    public function getCopilotHistory(): int
    {
        return $this->copilotHistory;
    }

    /**
     * Your own MCP token model (a subclass of `McpToken`), when the plugin issues tokens.
     *
     * @param  class-string<Model>  $model
     */
    public function mcpTokenModel(string $model): static
    {
        if (! is_a($model, Models\McpToken::class, true)) {
            throw new InvalidArgumentException("The MCP token model [{$model}] must extend [".Models\McpToken::class.'].');
        }

        $this->mcpTokenModel = $model;

        return $this;
    }

    /**
     * @return class-string<Models\McpToken>
     */
    public function getMcpTokenModel(): string
    {
        /** @var class-string<Models\McpToken> */
        return $this->mcpTokenModel ?? Models\McpToken::class;
    }

    /**
     * Which token stores authenticate MCP clients, tried in this order: `'api'`
     * (alfasix/alfakit-api on the panel, with its token repository), `'sanctum'` (Laravel
     * Sanctum personal access tokens) and `'plugin'` (`alfakit-ai:mcp-token`). By default
     * every available one is tried, so installing Sanctum never invalidates plugin tokens.
     *
     * @param  list<string>|null  $sources
     */
    public function mcpTokenSources(?array $sources): static
    {
        foreach ($sources ?? [] as $source) {
            if (! in_array($source, [Mcp\McpTokens::API, Mcp\McpTokens::SANCTUM, Mcp\McpTokens::PLUGIN], true)) {
                throw new InvalidArgumentException("Unknown MCP token source [{$source}]: use 'api', 'sanctum' or 'plugin'.");
            }
        }

        $this->mcpTokenSources = $sources === null ? null : array_values(array_unique($sources));

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getMcpTokenSources(): ?array
    {
        return $this->mcpTokenSources;
    }

    // ---------------------------------------------------------------- Privacy

    /**
     * Attributes that never reach a prompt, on top of the model's `$hidden` and the defaults
     * (passwords, tokens, secrets…): `'email'`, `'*_phone'`, `Customer::class.'.notes'` or
     * `'customers.notes'` (model table).
     *
     * @param  array<int, string>  $attributes
     */
    public function redact(array $attributes): static
    {
        $this->redactions = [...$this->redactions, ...array_values(array_filter($attributes, is_string(...)))];

        return $this;
    }

    /**
     * Decide per value: `fn (string $attribute, mixed $value, Model $record): bool` (true redacts).
     */
    public function redactUsing(?Closure $callback): static
    {
        $this->redactUsing = $callback;

        return $this;
    }

    /**
     * Only redact what `redact()` lists (and `$hidden`), not the built-in sensitive names.
     */
    public function withoutDefaultRedactions(): static
    {
        $this->hasDefaultRedactions = false;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getRedactions(): array
    {
        return $this->hasDefaultRedactions ? [...self::DEFAULT_REDACTIONS, ...$this->redactions] : $this->redactions;
    }

    public function getRedactUsing(): ?Closure
    {
        return $this->redactUsing;
    }

    // ---------------------------------------------------------------- Limits and cost

    /**
     * Requests per minute per user, for all features together or for one (30 overall and
     * 10 copilot questions by default). Null removes the limit.
     */
    public function rateLimit(?int $perMinute, ?AiFeature $feature = null): static
    {
        $key = $feature->value ?? '*';

        if ($perMinute === null) {
            unset($this->rateLimits[$key]);
        } else {
            $this->rateLimits[$key] = max(1, $perMinute);
        }

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function getRateLimits(AiFeature $feature): array
    {
        return array_filter([
            '*' => $this->rateLimits['*'] ?? null,
            $feature->value => $this->rateLimits[$feature->value] ?? null,
        ], static fn (?int $limit): bool => $limit !== null);
    }

    /**
     * Prices per million tokens, by model name (or `*` for any model), to estimate costs:
     * `['gpt-4.1-mini' => ['input' => 0.4, 'output' => 1.6]]`.
     *
     * @param  array<string, array{input?: float|int, output?: float|int}>  $prices
     */
    public function pricing(array $prices, string $currency = 'USD'): static
    {
        foreach ($prices as $model => $price) {
            $this->pricing[(string) $model] = ['input' => (float) ($price['input'] ?? 0), 'output' => (float) ($price['output'] ?? 0)];
        }

        $this->currency = strtoupper($currency);

        return $this;
    }

    public function estimateCost(?string $model, int $inputTokens, int $outputTokens): ?float
    {
        $price = ($model !== null ? ($this->pricing[$model] ?? null) : null) ?? $this->pricing['*'] ?? null;

        if ($price === null) {
            return null;
        }

        return round(($inputTokens * $price['input'] + $outputTokens * $price['output']) / 1_000_000, 6);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function hasPricing(): bool
    {
        return $this->pricing !== [];
    }

    // ---------------------------------------------------------------- Usage resource

    /**
     * The AI usage resource (calls, tokens, cost, duration): true for the default one, your
     * subclass, or false for none.
     *
     * @param  bool|class-string<AiUsageResource>  $resource
     */
    public function usageResource(bool|string $resource = true): static
    {
        if (is_string($resource) && ! is_a($resource, AiUsageResource::class, true)) {
            throw new InvalidArgumentException("The usage resource [{$resource}] must extend [".AiUsageResource::class.'].');
        }

        $this->usageResource = $resource === true ? AiUsageResource::class : ($resource === false ? null : $resource);

        return $this;
    }

    /**
     * @return class-string<AiUsageResource>|null
     */
    public function getUsageResource(): ?string
    {
        return $this->usageResource;
    }

    /**
     * Who sees everyone's usage (everyone else sees their own): `fn (Model $user): bool`.
     */
    public function canViewAllUsage(?Closure $callback): static
    {
        $this->canViewAllUsageUsing = $callback;

        return $this;
    }

    public function userCanViewAllUsage(): bool
    {
        if ($this->canViewAllUsageUsing === null) {
            return false;
        }

        $user = (alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel())?->auth()->user();

        if (! $user instanceof Model) {
            return false;
        }

        return (bool) $this->evaluate($this->canViewAllUsageUsing, ['user' => $user], [Model::class => $user, $user::class => $user]);
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) ? $group : null;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }
}
