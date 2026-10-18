<?php

declare(strict_types=1);

use AlfaSix\AlfakitAi\Agents\StructuredAgent;
use AlfaSix\AlfakitAi\Agents\TextAgent;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Models\AiUsage;
use AlfaSix\AlfakitAi\Tests\Fixtures\Models\Order;
use Laravel\Ai\Prompts\AgentPrompt;

use function AlfaSix\AlfakitAi\Tests\answer;
use function AlfaSix\AlfakitAi\Tests\execute;
use function AlfaSix\AlfakitAi\Tests\modal;
use function AlfaSix\AlfakitAi\Tests\order;
use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\token;
use function AlfaSix\AlfakitAi\Tests\user;

uses(AlfaSix\AlfakitAi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->actingAs(user());
});

it('summarises a record with only its visible, redacted fields and logs the call', function (): void {
    TextAgent::fake([answer('Acme ordered SO-1 for 120.')])->preventStrayPrompts();
    $order = order(['number' => 'SO-1']);

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])
        ->assertOk();

    expect($response->json('form.state.ai_result'))->toBe('Acme ordered SO-1 for 120.')
        ->and($response->json('description'))->toContain('AI can make mistakes');

    TextAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return str_contains($prompt->prompt, 'Acme Corp')
            && str_contains($prompt->prompt, '<data name="source">')
            && str_contains($prompt->prompt, '[redacted]')
            && ! str_contains($prompt->prompt, 'buyer@acme.test')
            && ! str_contains($prompt->prompt, 'TOP-SECRET-42');
    });

    $usage = AiUsage::query()->sole();

    expect($usage->getAttribute('feature'))->toBe('actions')
        ->and($usage->getAttribute('context'))->toBe('summarize')
        ->and($usage->getAttribute('status'))->toBe(AiUsage::SUCCESS)
        ->and($usage->getAttribute('input_tokens'))->toBe(100)
        ->and($usage->getAttribute('output_tokens'))->toBe(20)
        ->and($usage->getAttribute('model'))->toBe('gpt-test')
        ->and($usage->getAttribute('cost'))->toBe(0.00014)
        ->and($usage->getAttribute('panel'))->toBe('ai')
        ->and((int) $usage->getAttribute('user_id'))->toBe((int) auth()->id());
});

it('never sends a record the user may not view', function (): void {
    TextAgent::fake()->preventStrayPrompts();
    $private = order(['is_private' => true]);

    modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => $private->getKey()])
        ->assertOk()
        ->assertJsonPath('form.state.ai_result', null);

    TextAgent::assertNeverPrompted();
});

it('fills structured output fields, keeping only values the fields accept', function (): void {
    StructuredAgent::fake([['subject' => 'Your order', 'body' => 'Thanks!', 'tone' => 'sarcastic', 'extra' => 'dropped']]);
    $order = order();

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'draftReply', 'record' => $order->getKey()])
        ->assertOk();

    expect($response->json('form.state.subject'))->toBe('Your order')
        ->and($response->json('form.state.body'))->toBe('Thanks!')
        ->and($response->json('form.state.tone'))->toBeNull()
        ->and($response->json('form.state'))->not->toHaveKey('extra');

    // Submitting runs the action with what the user reviewed.
    execute('ai', [
        'token' => token('/ai/orders'), 'component' => 'table', 'action' => 'draftReply', 'record' => $order->getKey(),
        'data' => ['subject' => 'Edited', 'body' => 'By me', 'tone' => 'warm'],
    ])->assertOk();

    expect($order->refresh()->getAttribute('notes'))->toBe('Edited: By me');
});

it('shows provider failures as a notification and logs them', function (): void {
    TextAgent::fake(fn () => throw new RuntimeException('Upstream 500 with key sk-abcdefghijklmnopqrstuvwxyz'));

    $response = modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => order()->getKey()])
        ->assertOk();

    expect($response->json('cancelled'))->toBeTrue();

    $usage = AiUsage::query()->sole();

    expect($usage->getAttribute('status'))->toBe(AiUsage::FAILED)
        ->and($usage->getAttribute('error'))->toContain('RuntimeException')
        ->and($usage->getAttribute('error'))->not->toContain('sk-abcdefghijklmnopqrstuvwxyz');
});

it('rate limits AI calls per user', function (): void {
    plugin()->rateLimit(2, AiFeature::Actions);
    TextAgent::fake([answer('1'), answer('2'), answer('3')]);
    $order = order();
    $token = token('/ai/orders');

    foreach ([1, 2] as $attempt) {
        modal('ai', ['token' => $token, 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertJsonMissingPath('cancelled');
    }

    modal('ai', ['token' => $token, 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])
        ->assertJsonPath('cancelled', true);

    TextAgent::assertPromptedTimes(2);
});

it('hides AI actions when the plugin, the feature or the user is switched off', function (): void {
    $order = order();
    $names = function (): array {
        $json = json_encode(AlfaSix\AlfakitAi\Tests\TestCase::current()->inertiaProps('/ai/orders'));
        preg_match_all('/"name":"(summarize|draftReply|aiFilters)"/', (string) $json, $matches);

        return array_values(array_unique($matches[1]));
    };

    expect($names())->toContain('summarize', 'aiFilters');

    plugin()->disable(AiFeature::Actions);
    expect($names())->not->toContain('summarize')->toContain('aiFilters');

    plugin()->authorize(fn (): bool => false);
    expect($names())->toBe([]);

    $token = token('/ai/orders');
    modal('ai', ['token' => $token, 'component' => 'table', 'action' => 'summarize', 'record' => $order->getKey()])->assertForbidden();
});

it('saves a classification only when the user may edit the record', function (): void {
    StructuredAgent::fake([['value' => 'shipped', 'reason' => 'Tracking number given.'], ['value' => 'shipped', 'reason' => 'x']]);
    $order = order();
    $locked = order(['status' => 'locked']);
    $token = token('/ai/orders');

    modal('ai', ['token' => $token, 'component' => 'table', 'action' => 'categorize', 'record' => $order->getKey()])
        ->assertOk()
        ->assertJsonPath('form.state.value', 'shipped');

    execute('ai', ['token' => $token, 'component' => 'table', 'action' => 'categorize', 'record' => $order->getKey(), 'data' => ['value' => 'shipped']])->assertOk();
    expect($order->refresh()->getAttribute('status'))->toBe('shipped');

    execute('ai', ['token' => $token, 'component' => 'table', 'action' => 'categorize', 'record' => $locked->getKey(), 'data' => ['value' => 'shipped']])->assertForbidden();
    expect($locked->refresh()->getAttribute('status'))->toBe('locked');

    // Only an option of the list is accepted.
    execute('ai', ['token' => $token, 'component' => 'table', 'action' => 'categorize', 'record' => $order->getKey(), 'data' => ['value' => 'deleted']])->assertJsonValidationErrors('value');
});

it('uses the configured provider and model for a feature', function (): void {
    plugin()->provider('openai', 'gpt-special', feature: AiFeature::Actions);
    TextAgent::fake([answer('ok')]);

    modal('ai', ['token' => token('/ai/orders'), 'component' => 'table', 'action' => 'summarize', 'record' => order()->getKey()])->assertOk();

    TextAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'gpt-special');
    expect(Order::query()->count())->toBe(1);
});
