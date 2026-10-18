<?php

declare(strict_types=1);

use AlfaSix\AlfakitAi\Models\AiUsage;
use AlfaSix\AlfakitAi\Support\RecordData;
use AlfaSix\AlfakitAi\Support\Redactor;
use AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource;

use function AlfaSix\AlfakitAi\Tests\order;
use function AlfaSix\AlfakitAi\Tests\plugin;
use function AlfaSix\AlfakitAi\Tests\user;

uses(AlfaSix\AlfakitAi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * @param  array<string, mixed>  $attributes
 */
function usage(Illuminate\Database\Eloquent\Model $user, array $attributes = []): AiUsage
{
    return AiUsage::query()->create([
        'panel' => 'ai', 'feature' => 'actions', 'user_type' => $user->getMorphClass(), 'user_id' => $user->getKey(),
        'provider' => 'openai', 'model' => 'gpt-test', 'input_tokens' => 1000, 'output_tokens' => 500, 'cost' => 0.002,
        'duration_ms' => 800, 'status' => AiUsage::SUCCESS, ...$attributes,
    ]);
}

it('lists the user\'s own AI calls with totals', function (): void {
    $me = user('Me');
    $other = user('Other');
    usage($me, ['context' => 'mine']);
    usage($me, ['status' => AiUsage::FAILED, 'context' => 'mine-failed']);
    usage($other, ['context' => 'theirs']);
    usage($me, ['panel' => 'ai-plain', 'context' => 'other-panel']);

    $this->actingAs($me);
    $page = $this->inertiaProps('/ai/ai-usage')['page'];
    $json = json_encode($page);

    expect($json)->toContain('mine')->toContain('mine-failed')->not->toContain('theirs')->not->toContain('other-panel')
        ->and($page['headerActions'] ?? [])->toBe([]);

    alfakit()->setCurrentPanel(alfakit()->getPanel('ai'));
    $stats = (new class extends AlfaSix\AlfakitAi\Resources\AiUsageResource\Widgets\AiUsageStats
    {
        public function getPageTableQuery(): Illuminate\Database\Eloquent\Builder
        {
            return AlfaSix\AlfakitAi\Resources\AiUsageResource::getEloquentQuery();
        }

        public function stats(): array
        {
            return array_map(fn ($stat) => $stat->toSchema(), $this->getStats());
        }
    })->stats();

    expect(json_encode($stats))->toContain('"value":"2"')->toContain('1 failed')->toContain('3K')->toContain('$0.00');
});

it('shows everyone\'s calls to users allowed by canViewAllUsage()', function (): void {
    $me = user('Me');
    usage(user('Other'), ['context' => 'theirs']);
    plugin()->canViewAllUsage(fn ($user): bool => $user->getAttribute('name') === 'Me');

    $this->actingAs($me);

    expect(json_encode($this->inertiaProps('/ai/ai-usage')))->toContain('theirs');
});

it('filters the usage by feature and status', function (): void {
    $me = user('Me');
    usage($me, ['feature' => 'copilot', 'context' => 'chat-call']);
    usage($me, ['feature' => 'actions', 'context' => 'action-call']);

    $this->actingAs($me);
    $json = json_encode($this->inertiaProps('/ai/ai-usage?filters[feature][value]=copilot'));

    expect($json)->toContain('chat-call')->not->toContain('action-call');
});

it('redacts hidden, default and configured attributes', function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('ai'));
    $order = order();
    $redactor = Redactor::make(plugin());

    expect($redactor->isRedacted('email', 'x', $order))->toBeTrue()
        ->and($redactor->isRedacted('secret_code', 'x', $order))->toBeTrue()
        ->and($redactor->isRedacted('api_token'))->toBeTrue()
        ->and($redactor->isRedacted('password'))->toBeTrue()
        ->and($redactor->isRedacted('customer', 'x', $order))->toBeFalse();

    plugin()->redact([OrderResource::getModel().'.customer'])->redactUsing(fn (string $attribute, mixed $value): bool => $value === 'Customer wants delivery before Friday.');

    $data = RecordData::for($order, OrderResource::class, Redactor::make(plugin()));

    expect($data['fields']['customer'])->toBe('[redacted]')
        ->and($data['fields']['notes'])->toBe('[redacted]')
        ->and($data['fields']['number'])->toBe($order->getAttribute('number'))
        ->and($data['fields'])->not->toHaveKey('secret_code');
});
