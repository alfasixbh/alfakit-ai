<?php

declare(strict_types=1);

use AlfaSix\AlfakitAi\Agents\AssistAgent;
use AlfaSix\AlfakitAi\Agents\TextAgent;
use AlfaSix\AlfakitAi\Copilot\CopilotAgent;
use AlfaSix\AlfakitAi\Models\AiUsage;
use AlfaSix\AlfakitAi\Models\Message;
use AlfaSix\AlfakitAi\Support\EventStream;
use AlfaSix\AlfakitAi\Support\StreamJob;

use function AlfaSix\AlfakitAi\Tests\answer;
use function AlfaSix\AlfakitAi\Tests\component;
use function AlfaSix\AlfakitAi\Tests\modal;
use function AlfaSix\AlfakitAi\Tests\order;
use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\refresh;
use function AlfaSix\AlfakitAi\Tests\token;
use function AlfaSix\AlfakitAi\Tests\user;

/*
 * `AiPlugin::streaming()`: AiAction answers, aiAssist() suggestions and copilot replies
 * streamed as server-sent events, with laravel/ai fakes (which stream word by word).
 */

uses(AlfaSix\AlfakitAi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * The events of a streamed response, in order.
 *
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function streamEvents(Illuminate\Testing\TestResponse $response): array
{
    $events = [];

    foreach (array_filter(explode("\n\n", (string) $response->streamedContent())) as $block) {
        preg_match('/^event: (.+)$/m', $block, $event);
        preg_match('/^data: (.+)$/m', $block, $data);
        $events[] = ['event' => $event[1] ?? '', 'data' => json_decode($data[1] ?? '{}', true)];
    }

    return $events;
}

/**
 * @param  list<array{event: string, data: array<string, mixed>}>  $events
 */
function streamedText(array $events): string
{
    return implode('', array_map(static fn (array $event): string => $event['data']['text'], array_filter($events, static fn (array $event): bool => $event['event'] === 'delta')));
}

beforeEach(function (): void {
    $this->actingAs(user());
    plugin()->streaming(demoDelayMs: 0);
});

it('opens an AiAction modal with a stream token and streams the answer once', function (): void {
    TextAgent::fake([answer('Acme ordered SO-1 for 120.')])->preventStrayPrompts();
    $order = order(['number' => 'SO-1']);

    $modal = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertOk();
    $stream = component($modal->json('form.components'), 'ai_stream');

    expect($modal->json('form.state.ai_result'))->toBeNull()
        ->and($modal->json('form.state.ai_stream'))->toBeString()
        ->and($stream['type'] ?? null)->toBe('ai:stream')
        ->and($stream['endpoint'] ?? null)->toEndWith('/ai/_ai/stream')
        ->and($stream['target'] ?? null)->toBe('ai_result')
        ->and($stream['errorPath'] ?? null)->toBe('ai_error')
        ->and(component($modal->json('form.components'), 'ai_preview'))->toBeNull();

    TextAgent::assertNeverPrompted();

    $response = $this->post('/ai/_ai/stream', ['token' => $modal->json('form.state.ai_stream')])->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $events = streamEvents($response);

    expect(count(array_filter($events, static fn (array $event): bool => $event['event'] === 'delta')))->toBeGreaterThan(1)
        ->and(streamedText($events))->toBe('Acme ordered SO-1 for 120.')
        ->and(end($events))->toBe(['event' => 'done', 'data' => ['text' => 'Acme ordered SO-1 for 120.']]);

    TextAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Acme Corp') && ! str_contains($prompt->prompt, 'TOP-SECRET-42'));

    expect(AiUsage::query()->sole()->getAttribute('status'))->toBe(AiUsage::SUCCESS);

    // Tokens are one-time.
    $this->post('/ai/_ai/stream', ['token' => $modal->json('form.state.ai_stream')])->assertForbidden();

    // Once the answer is in, the refreshed form shows it rendered by the server.
    $refreshed = refresh('ai', ['token' => token('/ai/orders'), 'component' => 'action:table.summarize', 'record' => $order->getKey(), 'state' => [...$modal->json('form.state'), 'ai_stream' => null, 'ai_result' => 'Acme ordered SO-1 for 120.'], 'path' => 'ai_result'])->assertOk();

    expect(component($refreshed->json('components'), 'ai_preview'))->not->toBeNull()
        ->and($refreshed->json('state.ai_stream'))->toBeNull();
});

it('refuses tokens of another user, tampered or expired tokens', function (): void {
    TextAgent::fake([answer('Answer.')]);
    $owner = auth()->user();
    $order = order();

    $modal = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertOk();
    $streamToken = (string) $modal->json('form.state.ai_stream');

    $this->post('/ai/_ai/stream', ['token' => 'eyJ'.substr($streamToken, 3, -6).'AAAAAA'])->assertForbidden();
    $this->post('/ai/_ai/stream', [])->assertForbidden();

    $this->actingAs(user('Other'));
    $this->post('/ai/_ai/stream', ['token' => $streamToken])->assertForbidden();

    // Expired (the token's own user, never used).
    $this->actingAs($owner);
    $this->travel(11)->minutes();
    $this->post('/ai/_ai/stream', ['token' => $streamToken])->assertForbidden();

    TextAgent::assertNeverPrompted();
});

it('is off without streaming(), and never streams structured answers', function (): void {
    plugin()->streaming(false);
    TextAgent::fake([answer('All at once.')]);
    $order = order();

    modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])
        ->assertOk()
        ->assertJsonPath('form.state.ai_result', 'All at once.')
        ->assertJsonMissingPath('form.state.ai_stream');
});

it('streams sample answers in chunks when no provider is configured', function (): void {
    config()->set('ai.providers.openai.key', null);
    plugin()->demoResponses();
    $order = order(['number' => 'SO-5']);

    $modal = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertOk();
    $events = streamEvents($this->post('/ai/_ai/stream', ['token' => $modal->json('form.state.ai_stream')])->assertOk());

    expect(streamedText($events))->toBe('Number: SO-5; Customer: Acme Corp; Total: 120; Status: new.')
        ->and(count($events))->toBeGreaterThan(2)
        ->and(end($events)['event'])->toBe('done')
        ->and(AiUsage::query()->sole()->getAttribute('provider'))->toBe('demo');
});

it('streams writing assistant suggestions and finishes them on the server', function (): void {
    AssistAgent::fake([answer('and it ships on Friday.')]);
    $order = order();
    $url = "/ai/orders/{$order->getKey()}/edit";
    $owner = ['number' => 'SO-1', 'customer' => 'Acme Corp', 'email' => '', 'total' => 120, 'status' => 'new', 'paid' => false, 'notes' => 'The parcel is ready', 'description' => '<p>Long text</p>'];
    $token = token($url);

    $modal = modal('ai', ['token' => $token, 'component' => 'field:form::notes', 'action' => 'aiAssist', 'state' => $owner])->assertOk();

    $refreshed = refresh('ai', ['token' => $token, 'component' => 'action:field:form::notes.aiAssist', 'ownerState' => $owner, 'state' => [...$modal->json('form.state'), 'ai_task' => 'continue'], 'path' => 'ai_task'])->assertOk();

    expect($refreshed->json('state.ai_stream'))->toBeString()
        ->and($refreshed->json('state.ai_result'))->toBeNull()
        ->and($refreshed->json('state.ai_last_task'))->toBe('continue');

    AssistAgent::assertNeverPrompted();

    $events = streamEvents($this->post('/ai/_ai/stream', ['token' => $refreshed->json('state.ai_stream')])->assertOk());

    // The continuation streams; the finished suggestion includes the text it continues.
    expect(streamedText($events))->toBe('and it ships on Friday.')
        ->and(end($events))->toBe(['event' => 'done', 'data' => ['text' => 'The parcel is ready and it ships on Friday.']]);
});

it('reports errors as an error event', function (): void {
    TextAgent::fake([answer('Answer.')]);
    $order = order();
    $modal = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertOk();

    plugin()->authorize(fn (): bool => false);

    $events = streamEvents($this->post('/ai/_ai/stream', ['token' => $modal->json('form.state.ai_stream')])->assertOk());

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('error')
        ->and($events[0]['data']['message'])->toBeString();
});

it('streams copilot replies, then sends the stored messages', function (): void {
    CopilotAgent::fake([answer('There are two open orders today.', 120, 12)]);

    $response = $this->postJson('/ai/_ai/copilot/messages/stream', ['message' => 'How many orders?'])->assertOk();
    $events = streamEvents($response);
    $done = end($events);

    expect(streamedText($events))->toBe('There are two open orders today.')
        ->and($done['event'])->toBe('done')
        ->and($done['data']['messages'][1]['content'])->toBe('There are two open orders today.')
        ->and($done['data']['messages'][1]['usage'])->toMatchArray(['inputTokens' => 120, 'outputTokens' => 12])
        ->and(Message::query()->pluck('role')->all())->toBe(['user', 'assistant']);

    // The copilot advertises the endpoint only with streaming().
    expect(json_encode($this->inertiaProps('/ai/orders')))->toContain('_ai\/copilot\/messages\/stream');
});

it('formats server-sent events', function (): void {
    expect(EventStream::format('delta', ['text' => "a\nb"]))->toBe("event: delta\ndata: {\"text\":\"a\\nb\"}\n\n")
        ->and(StreamJob::open('nonsense'))->toBeNull();
});

it('falls back to one answer sent in chunks when the provider cannot stream', function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('ai'));

    $agent = new class extends TextAgent
    {
        public function stream(Laravel\Ai\Contracts\AgentInput|Laravel\Ai\Messages\UserMessage|Laravel\Ai\Approvals\Decisions|string $prompt, array $attachments = [], Laravel\Ai\Enums\Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): Laravel\Ai\Responses\StreamableAgentResponse
        {
            throw new RuntimeException('This provider does not stream.');
        }

        public function prompt(Laravel\Ai\Contracts\AgentInput|Laravel\Ai\Messages\UserMessage|Laravel\Ai\Approvals\Decisions|string $prompt, array $attachments = [], Laravel\Ai\Enums\Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): Laravel\Ai\Responses\AgentResponse
        {
            return answer('One whole answer, sent in pieces.');
        }
    };

    $chunks = [];
    $response = app(AlfaSix\AlfakitAi\Support\AiRunner::class)->stream(AlfaSix\AlfakitAi\Enums\AiFeature::Actions, $agent, 'Hello', function (string $delta) use (&$chunks): void {
        $chunks[] = $delta;
    });

    expect($response->text)->toBe('One whole answer, sent in pieces.')
        ->and(implode('', $chunks))->toBe('One whole answer, sent in pieces.')
        ->and(count($chunks))->toBeGreaterThan(1)
        ->and(AiUsage::query()->sole()->getAttribute('status'))->toBe(AiUsage::SUCCESS);
});
