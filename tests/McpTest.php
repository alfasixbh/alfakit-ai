<?php

declare(strict_types=1);

use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Mcp\McpTokens;
use AlfaSix\AlfakitAi\Models\ToolCall;

use function AlfaSix\AlfakitAi\Tests\order;
use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\team;
use function AlfaSix\AlfakitAi\Tests\user;

uses(AlfaSix\AlfakitAi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * @param  array<string, mixed>  $params
 */
function rpc(string $url, string $method, array $params = [], ?string $token = null): Illuminate\Testing\TestResponse
{
    return AlfaSix\AlfakitAi\Tests\TestCase::current()->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ]));
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function callTool(string $url, string $token, string $tool, array $arguments): array
{
    $response = rpc($url, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments], $token)->assertOk();
    $result = $response->json('result');
    $text = $result['content'][0]['text'] ?? '';

    return ['error' => (bool) ($result['isError'] ?? false), 'data' => json_decode($text, true), 'text' => $text];
}

beforeEach(function (): void {
    $this->user = user();
    $this->token = McpTokens::issue(alfakit()->getPanel('ai'), $this->user, 'Claude Desktop');
});

it('rejects requests without a valid token', function (): void {
    rpc('/ai/mcp', 'tools/list')->assertUnauthorized();
    rpc('/ai/mcp', 'tools/list', [], '1|not-the-secret')->assertUnauthorized();
    rpc('/ai/mcp', 'tools/list', [], 'garbage')->assertUnauthorized();

    // Sanctum is installed in the test suite: its tokens are tried before the plugin's own.
    expect(McpTokens::sources(alfakit()->getPanel('ai')))->toBe([McpTokens::SANCTUM, McpTokens::PLUGIN]);
});

it('lists the resource tools', function (): void {
    $tools = collect(rpc('/ai/mcp', 'tools/list', [], $this->token)->assertOk()->json('result.tools'))->pluck('name')->all();

    expect($tools)->toBe(['list_resources', 'search_records', 'show_record', 'create_record', 'update_record']);

    $resources = callTool('/ai/mcp', $this->token, 'list_resources', []);

    expect(collect($resources['data']['resources'])->pluck('resource')->all())->toBe(['orders']);
});

it('searches and shows only records the user may view, with redaction', function (): void {
    $order = order(['number' => 'SO-1']);
    $private = order(['number' => 'SO-2', 'is_private' => true]);

    $search = callTool('/ai/mcp', $this->token, 'search_records', ['resource' => 'orders', 'query' => 'SO']);

    expect(collect($search['data']['results'])->pluck('title')->all())->toBe(['SO-1']);

    $shown = callTool('/ai/mcp', $this->token, 'show_record', ['resource' => 'orders', 'key' => (string) $order->getKey()]);

    expect($shown['data']['fields']['customer'])->toBe('Acme Corp')
        ->and($shown['data']['fields']['email'])->toBe('[redacted]')
        ->and($shown['data']['fields'])->not->toHaveKey('secret_code');

    expect(callTool('/ai/mcp', $this->token, 'show_record', ['resource' => 'orders', 'key' => (string) $private->getKey()])['error'])->toBeTrue()
        ->and(callTool('/ai/mcp', $this->token, 'show_record', ['resource' => 'users', 'key' => '1'])['error'])->toBeTrue();

    expect(ToolCall::query()->where('channel', 'mcp')->pluck('status')->all())->toBe(['ok', 'ok', 'denied', 'denied']);
});

it('creates and updates through the resource forms and policies', function (): void {
    $created = callTool('/ai/mcp', $this->token, 'create_record', ['resource' => 'orders', 'data' => ['number' => 'SO-NEW', 'customer' => 'Initech', 'total' => 50]]);

    expect($created['error'])->toBeFalse()
        ->and($created['data']['created']['fields']['customer'])->toBe('Initech');

    $invalid = callTool('/ai/mcp', $this->token, 'create_record', ['resource' => 'orders', 'data' => ['customer' => 'No number']]);

    expect($invalid['error'])->toBeTrue()->and($invalid['text'])->toContain('number');

    $order = order();
    $locked = order(['status' => 'locked']);

    expect(callTool('/ai/mcp', $this->token, 'update_record', ['resource' => 'orders', 'key' => (string) $order->getKey(), 'data' => ['customer' => 'Renamed']])['error'])->toBeFalse()
        ->and($order->refresh()->getAttribute('customer'))->toBe('Renamed')
        ->and($order->getAttribute('number'))->not->toBe('');

    expect(callTool('/ai/mcp', $this->token, 'update_record', ['resource' => 'orders', 'key' => (string) $locked->getKey(), 'data' => ['customer' => 'Nope']])['error'])->toBeTrue()
        ->and($locked->refresh()->getAttribute('customer'))->toBe('Acme Corp');

    AlfaSix\AlfakitAi\Tests\Fixtures\Policies\OrderPolicy::$canCreate = false;
    expect(callTool('/ai/mcp', $this->token, 'create_record', ['resource' => 'orders', 'data' => ['number' => 'X', 'customer' => 'Y']])['error'])->toBeTrue();
});

it('binds tenancy panels to the tenant in the URL and the token', function (): void {
    $alpha = team('Alpha');
    $beta = team('Beta');
    $this->user->teams()->attach($alpha->getKey());
    order(['number' => 'ALPHA-1', 'team_id' => $alpha->getKey()]);
    order(['number' => 'BETA-1', 'team_id' => $beta->getKey()]);

    $panel = alfakit()->getPanel('ai-team');
    $token = McpTokens::issue($panel, $this->user, 'Team token', $alpha);

    $search = callTool('/ai-team/mcp/alpha', $token, 'search_records', ['resource' => 'orders']);

    expect(collect($search['data']['results'])->pluck('title')->all())->toBe(['ALPHA-1']);

    rpc('/ai-team/mcp/beta', 'tools/list', [], $token)->assertNotFound();

    // A token of another panel doesn't work here.
    rpc('/ai-team/mcp/alpha', 'tools/list', [], $this->token)->assertUnauthorized();
});

it('is closed when MCP is off or the user may not use AI', function (): void {
    plugin()->authorize(fn (): bool => false);
    rpc('/ai/mcp', 'tools/list', [], $this->token)->assertForbidden();

    plugin()->authorize(null)->disable(AiFeature::Mcp);
    rpc('/ai/mcp', 'tools/list', [], $this->token)->assertNotFound();
});

it('rate limits MCP requests per user', function (): void {
    plugin()->rateLimit(2, AiFeature::Mcp);

    rpc('/ai/mcp', 'tools/list', [], $this->token)->assertOk();
    rpc('/ai/mcp', 'tools/list', [], $this->token)->assertOk();
    rpc('/ai/mcp', 'tools/list', [], $this->token)->assertStatus(429);
});
