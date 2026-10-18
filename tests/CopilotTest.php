<?php

declare(strict_types=1);

use AlfaSix\AlfakitAi\Copilot\CopilotAgent;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Models\AiUsage;
use AlfaSix\AlfakitAi\Models\Conversation;
use AlfaSix\AlfakitAi\Models\Message;
use AlfaSix\AlfakitAi\Models\ToolCall as AuditedToolCall;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;

use function AlfaSix\AlfakitAi\Tests\answer;
use function AlfaSix\AlfakitAi\Tests\order;
use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\team;
use function AlfaSix\AlfakitAi\Tests\user;

uses(AlfaSix\AlfakitAi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->user = user();
    $this->actingAs($this->user);
});

function ask(string $message, ?int $conversation = null, string $panel = '/ai'): Illuminate\Testing\TestResponse
{
    return AlfaSix\AlfakitAi\Tests\TestCase::current()->postJson("{$panel}/_ai/copilot/messages", array_filter(['message' => $message, 'conversation' => $conversation]));
}

it('puts the copilot button in the topbar of panels that enable it', function (): void {
    $props = json_encode($this->inertiaProps('/ai/orders'));
    $plain = json_encode($this->inertiaProps('/ai-plain/orders'));

    expect($props)->toContain('"type":"ai:copilot"')
        ->toContain('_ai\/copilot\/messages')
        ->and($plain)->not->toContain('ai:copilot');

    plugin()->authorize(fn (): bool => false);

    expect(json_encode($this->inertiaProps('/ai/orders')))->not->toContain('"type":"ai:copilot"');
});

it('leaves the copilot out of tenancy pages without a current tenant', function (): void {
    $alpha = team('Alpha');
    $this->user->teams()->attach($alpha->getKey());

    expect(json_encode($this->inertiaProps('/ai-team/alpha/orders')))->toContain('"type":"ai:copilot"')
        ->toContain('ai-team\/alpha\/_ai\/copilot\/conversations');

    // The profile page lives outside the tenant's URL, like tenant registration.
    $this->get('/ai-team/profile')->assertOk();
    expect(json_encode($this->inertiaProps('/ai-team/profile')))->not->toContain('ai:copilot');
});

it('answers with tools that search and read what the user may see, and logs everything', function (): void {
    $order = order(['number' => 'SO-77']);
    $private = order(['number' => 'SO-99', 'is_private' => true]);

    CopilotAgent::fake([
        new ToolCall('call-1', 'search_records', ['query' => 'SO', 'resource' => 'orders']),
        new ToolCall('call-2', 'read_record', ['resource' => 'orders', 'key' => (string) $order->getKey()]),
        new ToolCall('call-3', 'read_record', ['resource' => 'orders', 'key' => (string) $private->getKey()]),
        answer('SO-77 is for Acme Corp, total 120.', 300, 40),
    ]);

    $response = ask('What is SO-77?')->assertOk();

    expect($response->json('messages.0.role'))->toBe('user')
        ->and($response->json('messages.1.role'))->toBe('assistant')
        ->and($response->json('messages.1.content'))->toBe('SO-77 is for Acme Corp, total 120.')
        ->and($response->json('messages.1.usage'))->toMatchArray(['inputTokens' => 300, 'outputTokens' => 40])
        ->and(collect($response->json('messages.1.activity'))->pluck('tool')->all())->toBe(['search_records', 'read_record']);

    $calls = AuditedToolCall::query()->orderBy('id')->get();

    expect($calls->pluck('tool')->all())->toBe(['search_records', 'read_record', 'read_record'])
        ->and($calls->pluck('status')->all())->toBe(['ok', 'ok', 'denied'])
        ->and($calls[0]->getAttribute('channel'))->toBe('copilot')
        ->and($calls[0]->getAttribute('result'))->toContain('SO-77')->not->toContain('SO-99')
        ->and($calls[1]->getAttribute('result'))->toContain('Acme Corp')->toContain('markPaid')
        ->not->toContain('TOP-SECRET')->not->toContain('buyer@acme.test')->not->toContain('archive');

    CopilotAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), 'propose_action') && str_contains($prompt->agent->instructions(), '"resource": "orders"'));

    expect(AiUsage::query()->where('feature', 'copilot')->count())->toBe(1);
});

it('never runs an action before the user confirms it', function (): void {
    $order = order();

    CopilotAgent::fake([
        new ToolCall('call-1', 'propose_action', ['resource' => 'orders', 'key' => (string) $order->getKey(), 'action' => 'markPaid', 'inputs_json' => '{}', 'reason' => 'The customer paid by wire.']),
        answer('Please confirm marking it paid.'),
    ]);

    $response = ask('Mark it paid')->assertOk();
    $proposal = collect($response->json('messages'))->firstWhere('role', 'action');

    expect($proposal['action']['status'])->toBe('pending')
        ->and($proposal['action']['label'])->toBe('Mark paid')
        ->and($order->refresh()->getAttribute('paid'))->toBeFalse()
        ->and(AuditedToolCall::query()->where('tool', 'propose_action')->value('status'))->toBe('proposed');

    // Another user can't confirm it.
    $this->actingAs(user('Mallory'));
    $this->postJson("/ai/_ai/copilot/actions/{$proposal['id']}/confirm")->assertNotFound();
    expect($order->refresh()->getAttribute('paid'))->toBeFalse();

    $this->actingAs($this->user);
    $confirmed = $this->postJson("/ai/_ai/copilot/actions/{$proposal['id']}/confirm")->assertOk();

    expect($confirmed->json('messages.0.action.status'))->toBe('confirmed')
        ->and($order->refresh()->getAttribute('paid'))->toBeTrue()
        ->and(AuditedToolCall::query()->where('tool', 'run_action')->value('status'))->toBe('confirmed');

    // A decided proposal can't run again.
    $this->postJson("/ai/_ai/copilot/actions/{$proposal['id']}/confirm")->assertStatus(409);
});

it('refuses to propose actions the user may not run and lets the user cancel proposals', function (): void {
    $order = order();
    $locked = order(['status' => 'locked']);

    CopilotAgent::fake([
        new ToolCall('c1', 'propose_action', ['resource' => 'orders', 'key' => (string) $order->getKey(), 'action' => 'archive', 'inputs_json' => '{}', 'reason' => '']),
        new ToolCall('c2', 'propose_action', ['resource' => 'orders', 'key' => (string) $locked->getKey(), 'action' => 'markPaid', 'inputs_json' => '{}', 'reason' => '']),
        new ToolCall('c3', 'propose_action', ['resource' => 'orders', 'key' => (string) $order->getKey(), 'action' => 'markPaid', 'inputs_json' => '{}', 'reason' => '']),
        answer('Confirm?'),
    ]);

    $response = ask('Archive and pay')->assertOk();
    $proposals = collect($response->json('messages'))->where('role', 'action')->values();

    expect($proposals)->toHaveCount(1)
        ->and(AuditedToolCall::query()->where('tool', 'propose_action')->pluck('status')->all())->toBe(['denied', 'denied', 'proposed']);

    $this->postJson("/ai/_ai/copilot/actions/{$proposals[0]['id']}/reject")->assertOk()->assertJsonPath('messages.0.action.status', 'rejected');

    expect($order->refresh()->getAttribute('paid'))->toBeFalse();
});

it('offers links to panel pages', function (): void {
    $order = order();
    CopilotAgent::fake([
        new ToolCall('c1', 'navigate', ['resource' => 'orders', 'page' => 'view', 'key' => (string) $order->getKey()]),
        answer('Here you go.'),
    ]);

    $link = collect(ask('Open it')->assertOk()->json('messages'))->firstWhere('role', 'navigation');

    expect($link['url'])->toBe("http://localhost/ai/orders/{$order->getKey()}");
});

it('keeps conversations per user and continues them with their history', function (): void {
    CopilotAgent::fake([answer('Hello!'), answer('Still here.')]);

    $first = ask('Hi')->assertOk();
    $id = $first->json('conversation.id');

    ask('Are you there?', $id)->assertOk();

    CopilotAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Are you there?' && count($prompt->agent->messages()) === 2);

    $this->getJson('/ai/_ai/copilot/conversations')->assertOk()->assertJsonPath('conversations.0.id', $id);
    $this->getJson("/ai/_ai/copilot/conversations/{$id}")->assertOk()->assertJsonCount(4, 'messages');

    $this->actingAs(user('Eve'));
    $this->getJson("/ai/_ai/copilot/conversations/{$id}")->assertNotFound();
    $this->getJson('/ai/_ai/copilot/conversations')->assertJsonCount(0, 'conversations');
    ask('Hijack', $id)->assertNotFound();
    $this->deleteJson("/ai/_ai/copilot/conversations/{$id}")->assertNotFound();

    $this->actingAs($this->user);
    $this->deleteJson("/ai/_ai/copilot/conversations/{$id}")->assertOk();
    expect(Conversation::query()->count())->toBe(0);
});

it('scopes conversations and records to the current tenant', function (): void {
    $alpha = team('Alpha');
    $beta = team('Beta');
    $this->user->teams()->attach([$alpha->getKey(), $beta->getKey()]);
    order(['number' => 'ALPHA-1', 'team_id' => $alpha->getKey()]);
    order(['number' => 'BETA-1', 'team_id' => $beta->getKey()]);

    CopilotAgent::fake([new ToolCall('c1', 'search_records', ['query' => '', 'resource' => 'orders']), answer('Done.')]);

    ask('List orders', null, '/ai-team/alpha')->assertOk();

    $result = (string) AuditedToolCall::query()->value('result');

    expect($result)->toContain('ALPHA-1')->not->toContain('BETA-1');

    $this->getJson('/ai-team/alpha/_ai/copilot/conversations')->assertJsonCount(1, 'conversations');
    $this->getJson('/ai-team/beta/_ai/copilot/conversations')->assertJsonCount(0, 'conversations');
});

it('rate limits copilot questions per user', function (): void {
    plugin()->rateLimit(1, AiFeature::Copilot);
    CopilotAgent::fake([answer('One.'), answer('Two.')]);

    ask('First')->assertOk();
    ask('Second')->assertStatus(429)->assertJsonStructure(['message', 'retryAfter']);

    CopilotAgent::assertPromptedTimes(1);
});

it('is closed when the copilot is off or the user is not allowed', function (): void {
    CopilotAgent::fake()->preventStrayPrompts();

    $this->postJson('/ai-plain/_ai/copilot/messages', ['message' => 'Hi'])->assertNotFound();

    plugin()->authorize(fn (): bool => false);
    ask('Hi')->assertForbidden();

    plugin()->authorize(null)->disable(AiFeature::Copilot);
    ask('Hi')->assertNotFound();

    CopilotAgent::assertNeverPrompted();
    expect(Message::query()->count())->toBe(0);
});
