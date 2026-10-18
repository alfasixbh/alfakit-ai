<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitAi\Mcp\McpTokens;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Tokens\SanctumTokenRepository;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

use function AlfaSix\AlfakitAi\Tests\order;
use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\team;
use function AlfaSix\AlfakitAi\Tests\user;

/*
 * MCP clients authenticated with Laravel Sanctum personal access tokens and with the API
 * plugin's token repository (database and Sanctum-backed).
 */

uses(AlfaSix\AlfakitAi\Tests\McpTokenSourcesTestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * @param  array<string, mixed>  $params
 */
function mcpCall(string $url, string $method, string $token, array $params = []): Illuminate\Testing\TestResponse
{
    return AlfaSix\AlfakitAi\Tests\TestCase::current()->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{error: bool, data: mixed}
 */
function mcpTool(string $url, string $token, string $tool, array $arguments = []): array
{
    $result = mcpCall($url, 'tools/call', $token, ['name' => $tool, 'arguments' => (object) $arguments])->assertOk()->json('result');

    return ['error' => (bool) ($result['isError'] ?? false), 'data' => json_decode($result['content'][0]['text'] ?? '', true)];
}

beforeEach(function (): void {
    $this->user = user();
    order(['number' => 'SO-1']);
});

it('authenticates Sanctum tokens with their abilities, next to the plugin\'s own tokens', function (): void {
    expect(McpTokens::sources(alfakit()->getPanel('ai')))->toBe([McpTokens::SANCTUM, McpTokens::PLUGIN]);

    $readOnly = $this->user->createToken('mcp', ['orders:read'])->plainTextToken;

    mcpCall('/ai/mcp', 'tools/list', $readOnly)->assertOk();
    expect(collect(mcpTool('/ai/mcp', $readOnly, 'search_records', ['resource' => 'orders'])['data']['results'])->pluck('title')->all())->toBe(['SO-1'])
        ->and(mcpTool('/ai/mcp', $readOnly, 'create_record', ['resource' => 'orders', 'data' => ['number' => 'SO-2', 'customer' => 'X', 'total' => 1]])['error'])->toBeTrue();

    expect(PersonalAccessToken::findToken($readOnly)?->getAttribute('last_used_at'))->not->toBeNull();

    // `createToken()` without abilities grants `*`.
    $everything = $this->user->createToken('mcp')->plainTextToken;
    expect(mcpTool('/ai/mcp', $everything, 'create_record', ['resource' => 'orders', 'data' => ['number' => 'SO-2', 'customer' => 'X', 'total' => 1]])['error'])->toBeFalse();

    // Installing Sanctum doesn't invalidate tokens issued with `alfakit-ai:mcp-token`.
    mcpCall('/ai/mcp', 'tools/list', McpTokens::issue(alfakit()->getPanel('ai'), $this->user, 'Desktop'))->assertOk();
    $this->artisan('alfakit-ai:mcp-token', ['user' => $this->user->getAttribute('email'), '--panel' => 'ai'])->assertSuccessful();
});

it('rejects expired Sanctum tokens and tokens of other models', function (): void {
    mcpCall('/ai/mcp', 'tools/list', $this->user->createToken('old', ['*'], now()->subMinute())->plainTextToken)->assertUnauthorized();

    $token = $this->user->createToken('mcp')->plainTextToken;
    config(['sanctum.expiration' => 60]);
    $this->travel(2)->hours();
    mcpCall('/ai/mcp', 'tools/list', $token)->assertUnauthorized();
    config(['sanctum.expiration' => null]);
    mcpCall('/ai/mcp', 'tools/list', $token)->assertOk();

    // A Sanctum token of another tokenable model is never signed in as a panel user.
    $team = team('Alpha');
    $teamToken = PersonalAccessToken::query()->forceCreate([
        'tokenable_type' => $team->getMorphClass(),
        'tokenable_id' => $team->getKey(),
        'name' => 'team',
        'token' => hash('sha256', $secret = str_repeat('t', 40)),
        'abilities' => ['*'],
    ]);

    mcpCall('/ai/mcp', 'tools/list', $teamToken->getKey().'|'.$secret)->assertUnauthorized();
    expect(Team::query()->count())->toBe(1);
});

it('narrows the token stores with mcpTokenSources()', function (): void {
    $sanctum = $this->user->createToken('mcp')->plainTextToken;
    $own = McpTokens::issue(alfakit()->getPanel('ai'), $this->user, 'Desktop');

    plugin()->mcpTokenSources([McpTokens::PLUGIN]);
    mcpCall('/ai/mcp', 'tools/list', $sanctum)->assertUnauthorized();
    mcpCall('/ai/mcp', 'tools/list', $own)->assertOk();

    plugin()->mcpTokenSources([McpTokens::SANCTUM]);
    mcpCall('/ai/mcp', 'tools/list', $sanctum)->assertOk();
    mcpCall('/ai/mcp', 'tools/list', $own)->assertUnauthorized();
    $this->artisan('alfakit-ai:mcp-token', ['user' => $this->user->getAttribute('email'), '--panel' => 'ai'])->assertFailed();

    expect(fn () => plugin()->mcpTokenSources(['passport']))->toThrow(InvalidArgumentException::class);
});

it('keeps plugin tokens working when Sanctum\'s table was never migrated', function (): void {
    Schema::drop('personal_access_tokens');

    mcpCall('/ai/mcp', 'tools/list', McpTokens::issue(alfakit()->getPanel('ai'), $this->user, 'Desktop'))->assertOk();
    mcpCall('/ai/mcp', 'tools/list', '1|'.str_repeat('x', 40))->assertUnauthorized();
});

it('lets Sanctum tokens use tenancy panels for the user\'s own tenants', function (): void {
    $alpha = team('Alpha');
    team('Beta');
    $this->user->teams()->attach($alpha->getKey());
    $token = $this->user->createToken('mcp', ['orders:read'])->plainTextToken;

    mcpCall('/ai-team/mcp/alpha', 'tools/list', $token)->assertOk();
    mcpCall('/ai-team/mcp/beta', 'tools/list', $token)->assertNotFound();
});

it('authenticates with the API plugin\'s tokens and abilities', function (): void {
    $panel = alfakit()->getPanel('ai-api');
    $api = ApiPlugin::get($panel);
    assert($api !== null);

    expect(McpTokens::sources($panel))->toBe([McpTokens::API, McpTokens::SANCTUM, McpTokens::PLUGIN]);

    $readOnly = $api->getTokenRepository()->create($this->user, 'ai-api', 'MCP', ['orders:read'])->plainTextToken;

    expect(collect(mcpTool('/ai-api/mcp', $readOnly, 'search_records', ['resource' => 'orders'])['data']['results'])->pluck('title')->all())->toBe(['SO-1'])
        ->and(mcpTool('/ai-api/mcp', $readOnly, 'create_record', ['resource' => 'orders', 'data' => ['number' => 'SO-2', 'customer' => 'X', 'total' => 1]])['error'])->toBeTrue();

    // API tokens are bound to their panel; expired ones are refused.
    mcpCall('/ai/mcp', 'tools/list', $readOnly)->assertUnauthorized();
    mcpCall('/ai-api/mcp', 'tools/list', $api->getTokenRepository()->create($this->user, 'ai-api', 'Old', ['*'], now()->subMinute())->plainTextToken)->assertUnauthorized();
});

it('authenticates with an API plugin backed by Sanctum (SanctumTokenRepository)', function (): void {
    $panel = alfakit()->getPanel('ai-api');
    $api = ApiPlugin::get($panel);
    assert($api !== null);
    $api->tokenRepository(new SanctumTokenRepository);

    $writer = $api->getTokenRepository()->create($this->user, 'ai-api', 'MCP', ['orders:read', 'orders:create'])->plainTextToken;
    $other = $api->getTokenRepository()->create($this->user, 'elsewhere', 'Other panel', ['*'])->plainTextToken;

    expect(mcpTool('/ai-api/mcp', $writer, 'create_record', ['resource' => 'orders', 'data' => ['number' => 'SO-2', 'customer' => 'X', 'total' => 1]])['error'])->toBeFalse()
        ->and(mcpTool('/ai-api/mcp', $writer, 'update_record', ['resource' => 'orders', 'key' => '1', 'data' => ['customer' => 'Y']])['error'])->toBeTrue();

    // A token the API plugin issued for another panel is a Sanctum token too, but the
    // Sanctum store refuses it here as well.
    mcpCall('/ai-api/mcp', 'tools/list', $other)->assertUnauthorized();
    mcpCall('/ai/mcp', 'tools/list', $other)->assertUnauthorized();
    mcpCall('/ai/mcp', 'tools/list', $writer)->assertUnauthorized();

    plugin('ai-api')->mcpTokenSources([McpTokens::API]);
    mcpCall('/ai-api/mcp', 'tools/list', $writer)->assertOk();
});
