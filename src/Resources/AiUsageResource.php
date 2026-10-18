<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Resources;

use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Models\AiUsage;
use AlfaSix\AlfakitAi\Resources\AiUsageResource\Pages\ListAiUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every AI call of the panel (feature, user, provider, model, tokens, estimated cost,
 * duration, status) with filters and totals. Users see their own calls; `canViewAllUsage()`
 * shows everyone's. Read only. Tenancy panels only list the current tenant's calls.
 */
class AiUsageResource extends Resource
{
    protected static ?string $model = AiUsage::class;

    protected static ?string $slug = 'ai-usage';

    protected static bool $isScopedToTenant = false;

    protected static bool $isGloballySearchable = false;

    protected static bool $isDiscovered = false;

    public static function getModelLabel(): string
    {
        return (string) __('alfakit-ai::ai.usage.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('alfakit-ai::ai.usage.plural');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('alfakit-ai::ai.usage.navigation');
    }

    public static function getNavigationIcon(): ?string
    {
        return 'sparkles';
    }

    public static function getNavigationGroup(): ?string
    {
        return AiPlugin::get()?->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return AiPlugin::get()?->getNavigationSort();
    }

    public static function getEloquentQuery(): Builder
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $query = AiUsage::query()->with('user')->where('panel', $panel?->getId() ?? '');
        $tenant = alfakit()->getTenant();

        if ($tenant !== null) {
            $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', $tenant->getKey());
        }

        if (! (AiPlugin::get()?->userCanViewAllUsage() ?? false)) {
            $user = $panel?->auth()->user();

            if (! $user instanceof Model) {
                return $query->whereRaw('1 = 0');
            }

            $query->where('user_type', $user->getMorphClass())->where('user_id', $user->getKey());
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        $plugin = AiPlugin::get();
        $user = (alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel())?->auth()->user();

        return $plugin !== null && $plugin->isEnabled() && $user instanceof Model && ($plugin->userCanViewAllUsage() || $plugin->canUse(AiFeature::Actions) || $plugin->canUse(AiFeature::Copilot));
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && static::getEloquentQuery()->whereKey($record->getKey())->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $plugin = AiPlugin::get();
        $currency = $plugin?->getCurrency() ?? 'USD';
        $showsUsers = $plugin?->userCanViewAllUsage() ?? false;

        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('alfakit-ai::ai.usage.columns.date'))->dateTime()->sortable(),
                TextColumn::make('feature')->label(__('alfakit-ai::ai.usage.columns.feature'))->badge()
                    ->formatStateUsing(static fn (?string $state): string => $state === null ? '' : (AiFeature::tryFrom($state)?->getLabel() ?? $state)),
                TextColumn::make('context')->label(__('alfakit-ai::ai.usage.columns.context'))->toggleable(),
                TextColumn::make('user.name')->label(__('alfakit-ai::ai.usage.columns.user'))->visible($showsUsers),
                TextColumn::make('model')->label(__('alfakit-ai::ai.usage.columns.model'))
                    ->description(static fn (AiUsage $record): ?string => $record->getAttribute('provider')),
                TextColumn::make('input_tokens')->label(__('alfakit-ai::ai.usage.columns.input_tokens'))->numeric()->sortable()->alignEnd(),
                TextColumn::make('output_tokens')->label(__('alfakit-ai::ai.usage.columns.output_tokens'))->numeric()->sortable()->alignEnd(),
                TextColumn::make('cost')->label(__('alfakit-ai::ai.usage.columns.cost'))->money($currency, decimalPlaces: 4)->sortable()->alignEnd()->placeholder('—'),
                TextColumn::make('duration_ms')->label(__('alfakit-ai::ai.usage.columns.duration'))->numeric()->suffix(' ms')->sortable()->alignEnd(),
                TextColumn::make('status')->label(__('alfakit-ai::ai.usage.columns.status'))->badge()
                    ->color(static fn (?string $state): string => $state === AiUsage::SUCCESS ? 'success' : 'danger')
                    ->formatStateUsing(static fn (?string $state): string => (string) __('alfakit-ai::ai.usage.status.'.($state === AiUsage::SUCCESS ? 'success' : 'failed')))
                    ->tooltip(static fn (AiUsage $record): ?string => $record->getAttribute('error')),
            ])
            ->filters([
                SelectFilter::make('feature')->label(__('alfakit-ai::ai.usage.columns.feature'))->options(AiFeature::options()),
                SelectFilter::make('status')->label(__('alfakit-ai::ai.usage.columns.status'))->options([
                    AiUsage::SUCCESS => __('alfakit-ai::ai.usage.status.success'),
                    AiUsage::FAILED => __('alfakit-ai::ai.usage.status.failed'),
                ]),
                SelectFilter::make('model')->label(__('alfakit-ai::ai.usage.columns.model'))
                    ->options(static fn (): array => static::getEloquentQuery()->whereNotNull('model')->distinct()->orderBy('model')->pluck('model', 'model')->all()),
                Filter::make('created_at')
                    ->label(__('alfakit-ai::ai.usage.columns.date'))
                    ->schema([
                        DatePicker::make('from')->label(__('alfakit-ai::ai.usage.filters.from')),
                        DatePicker::make('until')->label(__('alfakit-ai::ai.usage.filters.until')),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        $timezone = alfakit()->getTimezone();

                        if (is_string($data['from'] ?? null) && $data['from'] !== '') {
                            $query->where('created_at', '>=', Carbon::parse($data['from'], $timezone)->startOfDay()->utc());
                        }

                        if (is_string($data['until'] ?? null) && $data['until'] !== '') {
                            $query->where('created_at', '<=', Carbon::parse($data['until'], $timezone)->endOfDay()->utc());
                        }

                        return $query;
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('alfakit-ai::ai.usage.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiUsage::route('/'),
        ];
    }
}
