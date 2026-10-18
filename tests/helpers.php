<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Tests\Fixtures\Models\AiUser;
use AlfaSix\AlfakitAi\Tests\Fixtures\Models\Order;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use LogicException;

function user(string $name = 'Ada Admin'): AiUser
{
    return AiUser::query()->create(['name' => $name, 'email' => uniqid('user', true).'@example.com', 'password' => 'password']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function order(array $attributes = []): Order
{
    return Order::query()->create([
        'number' => 'SO-'.random_int(1000, 9999),
        'customer' => 'Acme Corp',
        'email' => 'buyer@acme.test',
        'total' => 120,
        'secret_code' => 'TOP-SECRET-42',
        'notes' => 'Customer wants delivery before Friday.',
        ...$attributes,
    ]);
}

function team(string $name): Team
{
    return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
}

function plugin(string $panel = 'ai'): AiPlugin
{
    $plugin = AiPlugin::get(alfakit()->getPanel($panel));

    if ($plugin === null) {
        throw new LogicException("No AI plugin on [{$panel}].");
    }

    return $plugin;
}

/**
 * A fake answer with usage, as a provider reports it.
 */
function answer(string $text, int $input = 100, int $output = 20): AgentResponse
{
    return new AgentResponse('fake', $text, new TextUsage($input, $output), new Meta('openai', 'gpt-test'));
}

/**
 * The page token of an Inertia page.
 */
function token(string $url): string
{
    return (string) TestCase::current()->inertiaGet($url)->assertOk()->json('props.page.token');
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function modal(string $panel, array $body): TestResponse
{
    return TestCase::current()->postJson("/{$panel}/_alfakit/actions/modal", $body);
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function execute(string $panel, array $body): TestResponse
{
    return TestCase::current()->postJson("/{$panel}/_alfakit/actions", $body);
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function refresh(string $panel, array $body): TestResponse
{
    return TestCase::current()->postJson("/{$panel}/_alfakit/schema/refresh", $body);
}

/**
 * Every component in a serialised schema tree.
 *
 * @param  array<int|string, mixed>  $nodes
 * @return array<int, array<string, mixed>>
 */
function flatten(array $nodes): array
{
    $flat = [];

    foreach ($nodes as $node) {
        if (! is_array($node)) {
            continue;
        }

        if (isset($node['type']) && is_string($node['type'])) {
            $flat[] = $node;
        }

        foreach (['components', 'schema', 'children', 'items', 'content'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                array_push($flat, ...flatten(array_is_list($node[$key]) ? $node[$key] : [$node[$key]]));
            }
        }
    }

    return $flat;
}

/**
 * @param  array<int|string, mixed>  $nodes
 * @return array<string, mixed>|null
 */
function component(array $nodes, string $key): ?array
{
    foreach (flatten($nodes) as $node) {
        if (($node['key'] ?? null) === $key || ($node['name'] ?? null) === $key) {
            return $node;
        }
    }

    return null;
}
