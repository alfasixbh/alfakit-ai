<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Resources\AiUsageResource\Widgets;

use AlfaSix\Alfakit\Widgets\Concerns\InteractsWithPageTable;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Models\AiUsage;
use Illuminate\Support\Number;

/**
 * Totals of the usage table as filtered: calls, tokens, estimated cost, average duration
 * and failures.
 */
class AiUsageStats extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected function getStats(): array
    {
        $query = $this->getPageTableQuery()->reorder();
        $totals = (clone $query)->toBase()->selectRaw('count(*) as calls, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, sum(cost) as cost, coalesce(avg(duration_ms), 0) as duration')->first();
        $failed = (clone $query)->where('status', AiUsage::FAILED)->count();

        $calls = (int) ($totals->calls ?? 0);
        $tokens = (int) ($totals->input_tokens ?? 0) + (int) ($totals->output_tokens ?? 0);
        $cost = $totals->cost ?? null;
        $locale = app()->getLocale();
        $plugin = AiPlugin::get();

        return [
            Stat::make(__('alfakit-ai::ai.usage.stats.calls'), Number::format($calls, locale: $locale))
                ->description($failed > 0 ? (string) __('alfakit-ai::ai.usage.stats.failed', ['count' => Number::format($failed, locale: $locale)]) : null)
                ->descriptionColor($failed > 0 ? 'danger' : null)
                ->icon('sparkles'),
            Stat::make(__('alfakit-ai::ai.usage.stats.tokens'), Number::abbreviate($tokens, maxPrecision: 1))
                ->description((string) __('alfakit-ai::ai.usage.stats.tokens_split', [
                    'input' => Number::abbreviate((int) ($totals->input_tokens ?? 0), maxPrecision: 1),
                    'output' => Number::abbreviate((int) ($totals->output_tokens ?? 0), maxPrecision: 1),
                ]))
                ->icon('hash'),
            Stat::make(__('alfakit-ai::ai.usage.stats.cost'), $cost === null ? '—' : (string) Number::currency((float) $cost, $plugin?->getCurrency() ?? 'USD', $locale))
                ->description($plugin?->hasPricing() ? null : (string) __('alfakit-ai::ai.usage.stats.no_pricing'))
                ->icon('coins'),
            Stat::make(__('alfakit-ai::ai.usage.stats.duration'), Number::format((float) ($totals->duration ?? 0), precision: 0, locale: $locale).' ms')
                ->icon('timer'),
        ];
    }
}
