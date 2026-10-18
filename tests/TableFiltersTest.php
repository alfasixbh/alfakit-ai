<?php

declare(strict_types=1);

use AlfaSix\AlfakitAi\Agents\FilterAgent;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Filters\FilterInterpreter;
use AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource;
use AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource\Pages\ListOrders;
use Laravel\Ai\Prompts\AgentPrompt;

use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\token;
use function AlfaSix\AlfakitAi\Tests\user;

uses(AlfaSix\AlfakitAi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->actingAs(user());
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function filterAnswer(array $overrides = []): array
{
    return ['search' => '', 'sort_column' => '', 'sort_direction' => '', 'filters' => [], 'combinator' => 'and', 'rules' => [], 'explanation' => 'Paid orders over 100.', 'unsupported' => false, ...$overrides];
}

function askFilters(string $query): Illuminate\Testing\TestResponse
{
    return AlfaSix\AlfakitAi\Tests\TestCase::current()->post('/ai/_alfakit/actions', [
        'token' => token('/ai/orders'),
        'component' => 'table',
        'action' => 'aiFilters',
        'data' => ['query' => $query],
    ], ['X-Inertia' => 'true', 'X-Inertia-Version' => '', 'Referer' => 'http://localhost/ai/orders?activeTab=all']);
}

function table(): AlfaSix\Alfakit\Tables\Table
{
    alfakit()->setCurrentPanel(alfakit()->getPanel('ai'));
    $page = ListOrders::fromContext(new AlfaSix\Alfakit\Support\SchemaContext('ai', ListOrders::class));

    return $page->getTable();
}

it('adds the "Ask AI" header action after the table\'s own header actions', function (): void {
    $names = collect(table()->getHeaderActions())->map(fn ($action) => $action->getName())->all();

    expect($names)->toBe(['export', 'aiFilters']);
});

it('describes only the table\'s own filters, constraints and sortable columns', function (): void {
    $description = FilterInterpreter::for(table())->describe();

    expect(collect($description['filters'])->pluck('name')->all())->toBe(['status', 'paid'])
        ->and(collect($description['query_builder']['constraints'])->pluck('name')->all())->toBe(['total', 'ordered_at', 'customer'])
        ->and(collect($description['sortable_columns'])->pluck('name')->all())->toBe(['number', 'total'])
        ->and($description['searchable'])->toBeTrue();
});

it('applies valid filters, rules, search and sort as ordinary table state', function (): void {
    FilterAgent::fake([filterAnswer([
        'search' => 'acme',
        'sort_column' => 'total',
        'sort_direction' => 'desc',
        'filters' => [['filter' => 'status', 'field' => 'value', 'values' => ['paid']]],
        'rules' => [['constraint' => 'total', 'operator' => 'gt', 'values' => ['100']]],
    ])]);

    $response = askFilters('paid acme orders over 100, biggest first')->assertRedirect();
    $url = urldecode((string) $response->headers->get('Location'));

    expect($url)->toStartWith('http://localhost/ai/orders?')
        ->toContain('activeTab=all')
        ->toContain('search=acme')
        ->toContain('sort=total')
        ->toContain('direction=desc')
        ->toContain('filters[status][value]=paid')
        ->toContain('filters[queryBuilder][query][rules][0][constraint]=total')
        ->toContain('filters[queryBuilder][query][rules][0][operator]=gt')
        ->toContain('filters[queryBuilder][query][rules][0][value]=100');

    FilterAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"queryBuilder"') && str_contains($prompt->prompt, 'paid acme orders'));
});

it('rejects answers that use unknown filters, fields, operators, columns or values', function (array $answer, string $error): void {
    $interpreter = FilterInterpreter::for(table());

    expect($interpreter->interpret(filterAnswer($answer)))->toBeNull()
        ->and(implode(' ', $interpreter->getErrors()))->toContain($error);
})->with([
    'unknown filter' => [['filters' => [['filter' => 'deleted_at', 'field' => 'value', 'values' => ['1']]]], 'Unknown filter'],
    'unknown field' => [['filters' => [['filter' => 'status', 'field' => 'sql', 'values' => ['1']]]], 'Unknown filter field'],
    'invalid option' => [['filters' => [['filter' => 'status', 'field' => 'value', 'values' => ['refunded']]]], 'Invalid value'],
    'unknown constraint' => [['rules' => [['constraint' => 'password', 'operator' => 'equals', 'values' => ['x']]]], 'Unknown rule'],
    'unknown operator' => [['rules' => [['constraint' => 'total', 'operator' => 'raw', 'values' => ['1 OR 1=1']]]], 'no operator'],
    'invalid number' => [['rules' => [['constraint' => 'total', 'operator' => 'gt', 'values' => ['lots']]]], 'Invalid value'],
    'unknown sort column' => [['sort_column' => 'secret_code'], 'Unknown sort column'],
]);

it('applies nothing when the answer is rejected or unsupported', function (): void {
    FilterAgent::fake([
        filterAnswer(['filters' => [['filter' => 'nope', 'field' => 'value', 'values' => ['1']]]]),
        filterAnswer(['unsupported' => true]),
    ]);

    foreach ([1, 2] as $attempt) {
        $location = (string) askFilters('something')->headers->get('Location');
        expect($location)->not->toContain('filters');
    }

    FilterAgent::assertPromptedTimes(2);
});

it('is unavailable when the feature is off', function (): void {
    plugin()->disable(AiFeature::Filters);

    expect(collect(table()->getHeaderActions())->filter(fn ($action) => $action->isVisible())->map(fn ($action) => $action->getName())->values()->all())->toBe(['export']);

    expect(OrderResource::class)->toBeString();
});
