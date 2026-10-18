<?php

declare(strict_types=1);

use AlfaSix\AlfakitAi\Agents\AssistAgent;
use AlfaSix\AlfakitAi\Agents\StructuredAgent;
use AlfaSix\AlfakitAi\Agents\TextAgent;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use Laravel\Ai\Prompts\AgentPrompt;

use function AlfaSix\AlfakitAi\Tests\answer;
use function AlfaSix\AlfakitAi\Tests\component;
use function AlfaSix\AlfakitAi\Tests\execute;
use function AlfaSix\AlfakitAi\Tests\modal;
use function AlfaSix\AlfakitAi\Tests\order;
use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\refresh;
use function AlfaSix\AlfakitAi\Tests\token;
use function AlfaSix\AlfakitAi\Tests\user;

uses(AlfaSix\AlfakitAi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->actingAs(user());
    $this->order = order();
    $this->url = "/ai/orders/{$this->order->getKey()}/edit";
    $this->owner = ['number' => 'SO-1', 'customer' => 'Acme Corp', 'email' => '', 'total' => 120, 'status' => 'new', 'paid' => false, 'notes' => 'We needs the parcel fast, it are urgent.', 'description' => '<p>Long text</p>'];
});

it('adds the writing assistant as a hint action of text fields', function (): void {
    $form = $this->inertiaProps($this->url)['page'];
    $json = json_encode($form);

    expect($json)->toContain('"name":"aiAssist"')
        ->and(substr_count((string) $json, '"name":"aiAssist"'))->toBe(3);

    plugin()->disable(AiFeature::Assist);

    expect(json_encode($this->inertiaProps($this->url)['page']))->not->toContain('"name":"aiAssist"');
});

it('suggests a rewrite without touching the field until it is accepted', function (): void {
    AssistAgent::fake([answer('We need the parcel fast; it is urgent.')]);
    $token = token($this->url);

    $modal = modal('ai', ['token' => $token, 'component' => 'field:form::notes', 'action' => 'aiAssist', 'state' => $this->owner])->assertOk();

    expect(component($modal->json('form.components'), 'ai_task'))->not->toBeNull();
    AssistAgent::assertNeverPrompted();

    $state = [...$modal->json('form.state'), 'ai_task' => 'grammar'];
    $refreshed = refresh('ai', ['token' => $token, 'component' => 'action:field:form::notes.aiAssist', 'ownerState' => $this->owner, 'state' => $state, 'path' => 'ai_task'])->assertOk();

    expect($refreshed->json('state.ai_result'))->toBe('We need the parcel fast; it is urgent.')
        ->and($refreshed->json('state.ai_task'))->toBeNull()
        ->and($refreshed->json('state.ai_last_task'))->toBe('grammar');

    AssistAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Fix spelling') && str_contains($prompt->prompt, 'it are urgent'));

    // The field changes only on "Use suggestion".
    execute('ai', ['token' => $token, 'component' => 'field:form::notes', 'action' => 'aiAssist', 'state' => $this->owner, 'data' => ['ai_result' => 'We need the parcel fast; it is urgent.']])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('form.state.notes', 'We need the parcel fast; it is urgent.');

    expect($this->order->refresh()->getAttribute('notes'))->toBe('Customer wants delivery before Friday.');
});

it('asks for instructions before generating and shows errors inside the modal', function (): void {
    AssistAgent::fake()->preventStrayPrompts();
    $token = token($this->url);
    $state = modal('ai', ['token' => $token, 'component' => 'field:form::notes', 'action' => 'aiAssist', 'state' => $this->owner])->json('form.state');

    $refreshed = refresh('ai', ['token' => $token, 'component' => 'action:field:form::notes.aiAssist', 'ownerState' => $this->owner, 'state' => [...$state, 'ai_task' => 'generate'], 'path' => 'ai_task'])->assertOk();

    expect($refreshed->json('state.ai_error'))->toBe('Write some instructions first.');
    AssistAgent::assertNeverPrompted();
});

it('limits the tasks of a field', function (): void {
    $token = token($this->url);
    $modal = modal('ai', ['token' => $token, 'component' => 'field:form::description', 'action' => 'aiAssist', 'state' => $this->owner])->assertOk();

    expect(collect(component($modal->json('form.components'), 'ai_task')['options'] ?? [])->pluck('value')->all())->toBe(['shorter', 'grammar']);
});

it('translates a field and fills it on accept', function (): void {
    TextAgent::fake([answer('Le client veut une livraison avant vendredi.')]);
    $token = token($this->url);

    $modal = modal('ai', ['token' => $token, 'component' => 'field:form::notes', 'action' => 'translate', 'state' => $this->owner])->assertOk();

    expect($modal->json('form.state.ai_result'))->toBe('Le client veut une livraison avant vendredi.');
    TextAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'it are urgent'));

    execute('ai', ['token' => $token, 'component' => 'field:form::notes', 'action' => 'translate', 'state' => $this->owner, 'data' => ['locale' => $modal->json('form.state.locale'), 'ai_result' => 'Bonjour']])
        ->assertJsonPath('form.state.notes', 'Bonjour');
});

it('extracts pasted text into the form fields for review', function (): void {
    StructuredAgent::fake([['customer' => 'Globex', 'email' => 'jane@globex.test', 'total' => '250.5', 'status' => 'bogus']]);
    $token = token($this->url);
    $component = 'field:form::ai-extract';

    $state = modal('ai', ['token' => $token, 'component' => $component, 'action' => 'extract', 'state' => $this->owner])->assertOk()->json('form.state');
    StructuredAgent::assertNeverPrompted();

    $refreshed = refresh('ai', ['token' => $token, 'component' => "action:{$component}.extract", 'ownerState' => $this->owner, 'state' => [...$state, 'source_text' => 'Hi, Jane from Globex here, invoice total 250.50'], 'path' => 'source_text'])->assertOk();

    expect($refreshed->json('state.customer'))->toBe('Globex')
        ->and($refreshed->json('state.email'))->toBe('jane@globex.test')
        ->and($refreshed->json('state.total'))->toBe(250.5)
        ->and($refreshed->json('state.status'))->toBeNull();

    StructuredAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Globex') && str_contains($prompt->prompt, '"customer"'));

    execute('ai', ['token' => $token, 'component' => $component, 'action' => 'extract', 'state' => $this->owner, 'data' => $refreshed->json('state')])
        ->assertOk()
        ->assertJsonPath('form.state.customer', 'Globex')
        ->assertJsonPath('form.state.email', 'jane@globex.test')
        ->assertJsonPath('form.state.number', 'SO-1');
});
