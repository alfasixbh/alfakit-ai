<?php

declare(strict_types=1);

use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Resources\AiUsageResource;
use Illuminate\Support\Facades\Route;

use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\user;

uses(AlfaSix\AlfakitAi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

it('registers the plugin with its defaults', function (): void {
    $plain = plugin('ai-plain');

    expect($plain->isEnabled())->toBeTrue()
        ->and($plain->isEnabled(AiFeature::Actions))->toBeTrue()
        ->and($plain->isEnabled(AiFeature::Assist))->toBeTrue()
        ->and($plain->isEnabled(AiFeature::Filters))->toBeTrue()
        ->and($plain->isEnabled(AiFeature::Copilot))->toBeFalse()
        ->and($plain->isEnabled(AiFeature::Mcp))->toBeFalse()
        ->and($plain->getProvider(AiFeature::Copilot))->toBeNull()
        ->and($plain->getRateLimits(AiFeature::Copilot))->toBe(['*' => 30, 'copilot' => 10])
        ->and(alfakit()->getPanel('ai-plain')->getResources())->toContain(AiUsageResource::class)
        ->and(alfakit()->getPanel('ai-plain')->getFrontendTranslations())->toHaveKey('ai');
});

it('registers copilot and MCP routes only on panels that turn them on', function (): void {
    expect(Route::has('alfakit.ai.ai.copilot.send'))->toBeTrue()
        ->and(Route::has('alfakit.ai-plain.ai.copilot.send'))->toBeFalse()
        ->and(Route::has('alfakit-ai.ai.mcp'))->toBeTrue()
        ->and(Route::has('alfakit-ai.ai-plain.mcp'))->toBeFalse();
});

it('configures providers and models per feature', function (): void {
    $plugin = AiPlugin::make()
        ->provider('openai', 'gpt-4.1-mini')
        ->provider('anthropic', 'claude-test', feature: AiFeature::Copilot)
        ->model('gpt-4.1', AiFeature::Filters);

    expect($plugin->getProvider(AiFeature::Actions))->toBe('openai')
        ->and($plugin->getModel(AiFeature::Actions))->toBe('gpt-4.1-mini')
        ->and($plugin->getProvider(AiFeature::Copilot))->toBe('anthropic')
        ->and($plugin->getModel(AiFeature::Copilot))->toBe('claude-test')
        ->and($plugin->getProvider(AiFeature::Filters))->toBe('openai')
        ->and($plugin->getModel(AiFeature::Filters))->toBe('gpt-4.1');
});

it('switches the panel, features and users off', function (): void {
    $user = user();
    $this->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('ai'));

    $plugin = plugin();

    expect($plugin->canUse(AiFeature::Copilot))->toBeTrue();

    $plugin->disable(AiFeature::Copilot);
    expect($plugin->canUse(AiFeature::Copilot))->toBeFalse()->and($plugin->canUse(AiFeature::Actions))->toBeTrue();

    $plugin->authorize(fn ($user): bool => $user->getAttribute('name') === 'Someone else');
    expect($plugin->canUse(AiFeature::Actions))->toBeFalse();

    $plugin->authorize(null)->enabled(false);
    expect($plugin->canUse(AiFeature::Actions))->toBeFalse();
});

it('estimates costs from prices per million tokens', function (): void {
    $plugin = AiPlugin::make()->pricing(['gpt-test' => ['input' => 2, 'output' => 8], '*' => ['input' => 1, 'output' => 1]], 'eur');

    expect($plugin->estimateCost('gpt-test', 1_000_000, 500_000))->toBe(6.0)
        ->and($plugin->estimateCost('other', 1_000, 1_000))->toBe(0.002)
        ->and($plugin->getCurrency())->toBe('EUR')
        ->and(AiPlugin::make()->estimateCost('gpt-test', 10, 10))->toBeNull();
});
