<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Resources;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Actions;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitAi\Actions\AiAction;
use AlfaSix\AlfakitAi\Actions\ClassifyAction;
use AlfaSix\AlfakitAi\Actions\ExtractIntoFieldsAction;
use AlfaSix\AlfakitAi\Actions\SummarizeAction;
use AlfaSix\AlfakitAi\Actions\TranslateAction;
use AlfaSix\AlfakitAi\Tests\Fixtures\Models\Order;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    public const STATUSES = ['new' => 'New', 'paid' => 'Paid', 'shipped' => 'Shipped', 'locked' => 'Locked'];

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'orders';

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'customer'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('number')->required(),
            TextInput::make('customer')->required()->aiAssist(),
            TextInput::make('email')->email(),
            TextInput::make('total')->numeric(),
            Select::make('status')->options(self::STATUSES)->default('new')->hintAction(ClassifyAction::make()->fillField()),
            Toggle::make('paid'),
            DatePicker::make('ordered_at'),
            Textarea::make('notes')->aiAssist()->hintAction(TranslateAction::make()->fillField()),
            RichEditor::make('description')->aiAssist(['shorter', 'grammar']),
            Actions::make([ExtractIntoFieldsAction::make()->fields(['customer', 'email', 'total', 'status'])])->key('ai-extract'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('number'),
            TextEntry::make('customer'),
            TextEntry::make('email'),
            TextEntry::make('total'),
            TextEntry::make('status'),
            TextEntry::make('notes'),
            TextEntry::make('secret_code'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('customer')->searchable(),
                TextColumn::make('total')->sortable(),
                TextColumn::make('status'),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
                TernaryFilter::make('paid'),
                QueryBuilder::make()->constraints([
                    NumberConstraint::make('total'),
                    DateConstraint::make('ordered_at'),
                    TextConstraint::make('customer'),
                ]),
            ])
            ->recordActions([
                SummarizeAction::make(),
                AiAction::make('draftReply')
                    ->prompt(fn (Order $record): string => "Draft a reply to {$record->getAttribute('customer')}.")
                    ->schema([
                        TextInput::make('subject'),
                        Textarea::make('body'),
                        Select::make('tone')->options(['warm' => 'Warm', 'formal' => 'Formal']),
                    ])
                    ->action(function (array $data, Order $record): void {
                        $record->update(['notes' => $data['subject'].': '.$data['body']]);
                    }),
                AiAction::make('explainLocally')
                    ->provider('ollama')
                    ->prompt(fn (Order $record): string => "Explain order {$record->getAttribute('number')}."),
                ClassifyAction::make('categorize')->options(self::STATUSES)->saveTo('status'),
                Action::make('markPaid')
                    ->requiresConfirmation()
                    ->authorize(fn (Model $record): bool => static::canEdit($record))
                    ->action(fn (Order $record) => $record->update(['paid' => true, 'status' => 'paid'])),
                Action::make('archive')->authorize(false)->action(fn () => null),
            ])
            ->headerActions([Action::make('export')->action(fn () => null)])
            ->aiFilters();
    }

    public static function getPages(): array
    {
        return [
            'index' => OrderResource\Pages\ListOrders::route('/'),
            'create' => OrderResource\Pages\CreateOrder::route('/create'),
            'view' => OrderResource\Pages\ViewOrder::route('/{record}'),
            'edit' => OrderResource\Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
