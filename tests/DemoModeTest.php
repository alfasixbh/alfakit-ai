<?php

declare(strict_types=1);

use AlfaSix\AlfakitAi\Agents\AssistAgent;
use AlfaSix\AlfakitAi\Agents\FilterAgent;
use AlfaSix\AlfakitAi\Agents\StructuredAgent;
use AlfaSix\AlfakitAi\Agents\TextAgent;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Models\AiUsage;
use AlfaSix\AlfakitAi\Models\Message;
use AlfaSix\AlfakitAi\Models\ToolCall as AuditedToolCall;
use AlfaSix\AlfakitAi\Tests\Fixtures\Models\AiUser;
use AlfaSix\AlfakitAi\Tests\TestCase;
use Illuminate\Testing\TestResponse;

use function AlfaSix\AlfakitAi\Tests\answer;
use function AlfaSix\AlfakitAi\Tests\component;
use function AlfaSix\AlfakitAi\Tests\modal;
use function AlfaSix\AlfakitAi\Tests\order;
use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\refresh;
use function AlfaSix\AlfakitAi\Tests\token;
use function AlfaSix\AlfakitAi\Tests\user;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    // No provider key: the state of a fresh install before `alfakit:ai-setup`.
    config()->set('ai.providers.openai.key', null);
    $this->actingAs(user());
});

/**
 * @return TestResponse<Symfony\Component\HttpFoundation\Response>
 */
function demoCopilot(string $message): TestResponse
{
    return TestCase::current()->postJson('/ai/_ai/copilot/messages', ['message' => $message]);
}

/**
 * @return TestResponse<Symfony\Component\HttpFoundation\Response>
 */
function demoFilters(string $query): TestResponse
{
    return TestCase::current()->post('/ai/_alfakit/actions', [
        'token' => token('/ai/orders'),
        'component' => 'table',
        'action' => 'aiFilters',
        'data' => ['query' => $query],
    ], ['X-Inertia' => 'true', 'X-Inertia-Version' => '', 'Referer' => 'http://localhost/ai/orders']);
}

/**
 * The serialised copilot component, wherever the topbar slot puts it.
 *
 * @param  array<int|string, mixed>  $props
 * @return array<string, mixed>|null
 */
function copilotProps(array $props): ?array
{
    if (($props['type'] ?? null) === 'ai:copilot') {
        return $props;
    }

    foreach ($props as $value) {
        if (is_array($value) && ($found = copilotProps($value)) !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function assistState(): array
{
    return ['number' => 'SO-1', 'customer' => 'Acme Corp', 'email' => '', 'total' => 120, 'status' => 'new', 'paid' => false, 'notes' => 'we needs the parcel fast ,it are urgent. please call before noon. the gate code changed', 'description' => '<p>Long text</p>'];
}

/**
 * Run one writing-assistant task on the notes field and return the suggestion.
 */
function assistTask(string $task, string $url, array $extra = []): mixed
{
    $token = token($url);
    $state = modal('ai', ['token' => $token, 'component' => 'field:form::notes', 'action' => 'aiAssist', 'state' => assistState()])->assertOk()->json('form.state');

    return refresh('ai', ['token' => $token, 'component' => 'action:field:form::notes.aiAssist', 'ownerState' => assistState(), 'state' => [...$state, ...$extra, 'ai_task' => $task], 'path' => 'ai_task'])
        ->assertOk()
        ->json('state.ai_result');
}

// ---------------------------------------------------------------- Detection

it('knows from the configuration alone whether a provider is usable', function (): void {
    expect(plugin()->isConfigured())->toBeFalse();

    config()->set('ai.providers.openai.key', 'sk-something');
    expect(plugin()->isConfigured())->toBeTrue();

    config()->set('ai.providers.openai.key', '  ');
    expect(plugin()->isConfigured())->toBeFalse();

    config()->set('ai.providers.ollama', ['driver' => 'ollama', 'key' => '', 'url' => 'http://localhost:11434']);
    expect(AiPlugin::isProviderConfigured('ollama'))->toBeTrue()
        ->and(AiPlugin::isProviderConfigured('missing'))->toBeFalse()
        ->and(plugin()->isConfigured(provider: ['openai', 'ollama']))->toBeTrue()
        ->and(plugin()->isConfigured(provider: ['openai' => 'gpt-test', 'ollama' => 'llama3']))->toBeTrue();

    plugin()->provider('ollama', feature: AiFeature::Copilot);

    expect(plugin()->isConfigured(AiFeature::Copilot))->toBeTrue()
        ->and(plugin()->isConfigured(AiFeature::Actions))->toBeFalse();
});

// ---------------------------------------------------------------- Not configured, no sample answers

it('opens AI modals with a setup hint for users who may see it, and never calls a provider', function (): void {
    plugin()->setupHintVisible(fn (AiUser $user): bool => $user->getAttribute('name') === 'Ada Admin');
    $order = order();

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertOk();
    $notice = component($response->json('form.components'), 'ai_setup_notice');

    expect($notice['heading'] ?? null)->toBe('AI isn\'t set up yet.')
        ->and((string) ($notice['description'] ?? ''))->toContain('<code>php artisan alfakit:ai-setup</code>')
        ->and($notice['status'] ?? null)->toBe('warning')
        ->and($response->json('form.state.ai_result'))->toBeNull()
        ->and(AiUsage::query()->count())->toBe(0);
});

it('tells other users that AI is not available, without setup details', function (): void {
    // Not local, no closure: nobody sees the hint.
    $order = order();

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertOk();
    $notice = component($response->json('form.components'), 'ai_setup_notice');

    expect($notice['heading'] ?? null)->toBe('AI is not available right now.')
        ->and(json_encode($response->json()))->not->toContain('ai-setup');

    plugin()->setupHintVisible(fn (AiUser $user): bool => false);
    expect(plugin()->isSetupHintVisible())->toBeFalse();

    plugin()->setupHintVisible(null);
    app()->detectEnvironment(fn (): string => 'local');
    expect(plugin()->isSetupHintVisible())->toBeTrue();
});

it('shows the notice in the writing assistant and the filter box, and answers safely when used anyway', function (): void {
    $order = order();
    $url = "/ai/orders/{$order->getKey()}/edit";

    $modal = modal('ai', ['token' => token($url), 'component' => 'field:form::notes', 'action' => 'aiAssist', 'state' => assistState()])->assertOk();
    expect(component($modal->json('form.components'), 'ai_setup_notice'))->not->toBeNull();

    $suggestion = assistTask('grammar', $url);
    expect($suggestion)->toBeNull();

    $filters = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'aiFilters'])->assertOk();
    expect(component($filters->json('form.components'), 'ai_setup_notice'))->not->toBeNull();

    $response = demoFilters('paid orders');
    expect($response->status())->toBeLessThan(500)
        ->and((string) $response->headers->get('Location'))->not->toContain('filters');

    expect(AiUsage::query()->count())->toBe(0);
});

it('answers copilot questions with a friendly message instead of an error page', function (): void {
    $props = TestCase::current()->inertiaProps('/ai/orders');
    $copilot = copilotProps($props);

    expect($copilot['notice'] ?? null)->toMatchArray(['status' => 'warning', 'heading' => 'AI is not available right now.']);

    $response = demoCopilot('What is SO-1?')->assertOk();

    expect($response->json('messages.1.role'))->toBe('error')
        ->and($response->json('messages.1.content'))->toBe('AI is not available right now.');
});

it('shows the notice above the usage table', function (): void {
    expect(json_encode(TestCase::current()->inertiaProps('/ai/ai-usage')))->toContain('ai_setup_notice');

    config()->set('ai.providers.openai.key', 'sk-test');

    expect(json_encode(TestCase::current()->inertiaProps('/ai/ai-usage')))->not->toContain('ai_setup_notice');
});

// ---------------------------------------------------------------- Sample answers

it('summarises from the first sentences or fields, labelled and logged as a free demo call', function (): void {
    plugin()->demoResponses();
    $order = order(['number' => 'SO-5', 'notes' => 'Customer wants delivery before Friday. Gift wrap it. Call first.']);

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertOk();
    $notice = component($response->json('form.components'), 'ai_setup_notice');

    expect($response->json('form.state.ai_result'))->toBe('Number: SO-5; Customer: Acme Corp; Total: 120; Status: new.')
        ->and($notice['heading'] ?? null)->toBe('Sample answer — AI isn\'t configured')
        ->and($notice['status'] ?? null)->toBe('info');

    $usage = AiUsage::query()->sole();

    expect($usage->getAttribute('provider'))->toBe('demo')
        ->and($usage->getAttribute('model'))->toBe('demo')
        ->and((float) $usage->getAttribute('cost'))->toBe(0.0)
        ->and($usage->getAttribute('input_tokens'))->toBe(0)
        ->and($usage->getAttribute('status'))->toBe(AiUsage::SUCCESS);
});

it('marks sample translations and keeps the original text', function (): void {
    plugin()->demoResponses();
    $order = order();
    $url = "/ai/orders/{$order->getKey()}/edit";

    $modal = modal('ai', ['token' => token($url), 'component' => 'field:form::notes', 'action' => 'translate', 'state' => ['notes' => 'Deliver before Friday.']])->assertOk();

    expect($modal->json('form.state.ai_result'))->toStartWith('[Sample translation · ')
        ->toEndWith('Deliver before Friday.');
});

it('rewrites with deterministic templates in the writing assistant', function (string $task, string $expected): void {
    plugin()->demoResponses();
    $order = order();

    expect(assistTask($task, "/ai/orders/{$order->getKey()}/edit", ['ai_instructions' => 'a thank-you note']))->toBe($expected);
})->with([
    'grammar' => ['grammar', 'We needs the parcel fast, it are urgent. Please call before noon. The gate code changed.'],
    'shorter' => ['shorter', 'we needs the parcel fast ,it are urgent. please call before noon.'],
    'longer' => ['longer', 'we needs the parcel fast ,it are urgent. please call before noon. the gate code changed It covers the key details, and more can be added as needed.'],
    'friendlier' => ['friendlier', 'Hi there! We needs the parcel fast, it are urgent. Please call before noon. The gate code changed. Thanks so much!'],
    'formal' => ['formal', 'Dear Sir or Madam, We needs the parcel fast, it are urgent. Please call before noon. The gate code changed. Kind regards.'],
    'continue' => ['continue', 'we needs the parcel fast ,it are urgent. please call before noon. the gate code changed More details will follow.'],
    'generate' => ['generate', 'A first draft about “a thank-you note”. Set up AI for real suggestions.'],
]);

it('extracts emails, amounts, names and options from pasted text', function (): void {
    plugin()->demoResponses();
    $order = order();
    $url = "/ai/orders/{$order->getKey()}/edit";
    $token = token($url);
    $component = 'field:form::ai-extract';

    $state = modal('ai', ['token' => $token, 'component' => $component, 'action' => 'extract', 'state' => assistState()])->assertOk()->json('form.state');
    $refreshed = refresh('ai', [
        'token' => $token,
        'component' => "action:{$component}.extract",
        'ownerState' => assistState(),
        'state' => [...$state, 'source_text' => "Hi, Jane from Globex here.\nWrite to jane@globex.test. The invoice total is $1,250.50 and it is already paid."],
        'path' => 'source_text',
    ])->assertOk();

    expect($refreshed->json('state.customer'))->toBe('Globex')
        ->and($refreshed->json('state.email'))->toBe('jane@globex.test')
        ->and($refreshed->json('state.total'))->toBe(1250.5)
        ->and($refreshed->json('state.status'))->toBe('paid');
});

it('classifies by the option named most in the text, else the first option', function (): void {
    plugin()->demoResponses();
    $shipped = order(['status' => 'shipped', 'notes' => 'Shipped by courier on Monday.']);
    $plain = order(['status' => 'unknown', 'notes' => 'Nothing to say.']);
    $token = token('/ai/orders');

    $response = modal('ai', ['token' => $token, 'component' => 'table', 'action' => 'categorize', 'record' => $shipped->getKey()])->assertOk();

    expect($response->json('form.state.value'))->toBe('shipped')
        ->and($response->json('form.state.reason'))->toBe('Sample answer: the text mentions “shipped”.');

    $response = modal('ai', ['token' => $token, 'component' => 'table', 'action' => 'categorize', 'record' => $plain->getKey()])->assertOk();

    expect($response->json('form.state.value'))->toBe('new')
        ->and($response->json('form.state.reason'))->toContain('first one');
});

it('fills structured output fields with sample values', function (): void {
    plugin()->demoResponses();
    $order = order();

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'draftReply', 'record' => $order->getKey()])->assertOk();

    expect($response->json('form.state.subject'))->toBe('Sample subject')
        ->and($response->json('form.state.body'))->toBe('Sample text for: Draft a reply to Acme Corp.')
        ->and($response->json('form.state.tone'))->toBe('warm');
});

it('turns simple phrases into the table\'s own filters', function (): void {
    plugin()->demoResponses();
    $this->travelTo(now()->setDate(2026, 10, 15));

    $location = urldecode((string) demoFilters('paid orders over 100 this month, biggest total first')->assertRedirect()->headers->get('Location'));

    expect($location)->toContain('filters[status][value]=paid')
        ->toContain('filters[queryBuilder][query][rules][0][constraint]=total')
        ->toContain('filters[queryBuilder][query][rules][0][operator]=gt')
        ->toContain('filters[queryBuilder][query][rules][0][value]=100')
        ->toContain('filters[queryBuilder][query][rules][1][constraint]=ordered_at')
        ->toContain('filters[queryBuilder][query][rules][1][operator]=is_month')
        ->toContain('filters[queryBuilder][query][rules][1][value]=2026-10')
        ->toContain('sort=total')
        ->toContain('direction=desc');

    expect(AiUsage::query()->sole()->getAttribute('provider'))->toBe('demo');
});

it('suggests phrases when a request can\'t be understood without AI', function (): void {
    plugin()->demoResponses();

    $response = demoFilters('the ones my boss likes');

    expect((string) $response->headers->get('Location'))->not->toContain('filters')
        ->and(json_encode(session()->all()))->toContain('Try e.g.');
});

it('runs the copilot\'s real tools for simple commands and labels its answers', function (): void {
    plugin()->demoResponses();
    $order = order(['number' => 'SO-77']);

    $search = demoCopilot('search SO-77')->assertOk();
    $answer = collect($search->json('messages'))->firstWhere('role', 'assistant');

    expect($answer['content'])->toBe("I found one record for “SO-77”:\n- SO-77 (Order)")
        ->and($answer['demo'] ?? null)->toBeTrue()
        ->and(collect($search->json('messages'))->firstWhere('role', 'navigation'))->not->toBeNull()
        ->and(AuditedToolCall::query()->pluck('tool')->all())->toBe(['search_records', 'navigate']);

    $open = demoCopilot('open orders')->assertOk();
    expect(collect($open->json('messages'))->firstWhere('role', 'navigation')['url'] ?? null)->toEndWith('/ai/orders');

    $run = demoCopilot('run mark paid on SO-77')->assertOk();
    $proposal = collect($run->json('messages'))->firstWhere('role', 'action');

    expect($proposal['action']['status'] ?? null)->toBe('pending')
        ->and($order->refresh()->getAttribute('paid'))->toBeFalse()
        ->and(Message::query()->where('role', Message::ACTION)->count())->toBe(1);

    $help = demoCopilot('How are sales going?')->assertOk();
    expect(collect($help->json('messages'))->firstWhere('role', 'assistant')['content'])->toContain('search orders');

    expect(AiUsage::query()->where('feature', 'copilot')->pluck('provider')->unique()->all())->toBe(['demo']);

    $copilot = copilotProps(TestCase::current()->inertiaProps('/ai/orders'));
    expect($copilot['notice']['status'] ?? null)->toBe('info');
});

// ---------------------------------------------------------------- A configured provider always wins

it('checks an action\'s own provider for the notice and the sample answers', function (): void {
    $order = order();

    // The panel's provider has a key, the action's own (`->provider('ollama')`) has none.
    config()->set('ai.providers.openai.key', 'sk-test-not-a-real-key');
    config()->set('ai.providers.ollama', ['driver' => 'ollama', 'url' => '']);
    plugin()->demoResponses();

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'explainLocally', 'record' => $order->getKey()])->assertOk();

    expect(component($response->json('form.components'), 'ai_setup_notice')['status'] ?? null)->toBe('info')
        ->and($response->json('form.state.ai_result'))->toStartWith('This is a sample answer.')
        ->and(AiUsage::query()->sole()->getAttribute('provider'))->toBe('demo')
        ->and(plugin()->isDemo(AiFeature::Actions, 'ollama'))->toBeTrue()
        ->and(plugin()->isDemo(AiFeature::Actions))->toBeFalse();

    // The other way round: no panel key, but the action's provider is configured.
    config()->set('ai.providers.openai.key', null);
    config()->set('ai.providers.ollama.url', 'http://localhost:11434');
    TextAgent::fake([answer('From Ollama.')]);

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'explainLocally', 'record' => $order->getKey()])->assertOk();

    expect(component($response->json('form.components'), 'ai_setup_notice'))->toBeNull()
        ->and($response->json('form.state.ai_result'))->toBe('From Ollama.')
        ->and(plugin()->getSetupNotice(AiFeature::Actions, 'ollama'))->toBeNull()
        ->and(plugin()->getSetupNotice(AiFeature::Actions))->not->toBeNull();
});

it('uses the real provider when one is configured, even with sample answers on', function (): void {
    config()->set('ai.providers.openai.key', 'sk-test-not-a-real-key');
    plugin()->demoResponses();
    TextAgent::fake([answer('A real summary.')]);
    $order = order();

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertOk();

    expect($response->json('form.state.ai_result'))->toBe('A real summary.')
        ->and(component($response->json('form.components'), 'ai_setup_notice'))->toBeNull()
        ->and(AiUsage::query()->sole()->getAttribute('provider'))->toBe('openai');
});

it('lets faked agents run in tests without a key', function (): void {
    StructuredAgent::fake([['subject' => 'Hi', 'body' => 'Thanks', 'tone' => 'warm']]);
    AssistAgent::fake();
    FilterAgent::fake();
    $order = order();

    modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'draftReply', 'record' => $order->getKey()])
        ->assertOk()
        ->assertJsonPath('form.state.subject', 'Hi');
});
