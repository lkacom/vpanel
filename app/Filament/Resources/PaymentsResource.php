<?php

namespace App\Filament\Resources;

use App\Events\OrderPaid;
use App\Filament\Resources\PaymentsResource\Pages;
use App\Filament\Resources\PaymentsResource\RelationManagers;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MarzbanService;
use App\Services\XUIService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Laravel\Facades\Telegram;

class PaymentsResource extends Resource
{
    protected static ?string $model = Transaction::class;


    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'تراکنش ها';
    protected static ?string $modelLabel = 'تراکنش';
    protected static ?string $pluralModelLabel = 'تاریخچه تراکنش ها';
    protected static ?string $navigationGroup = 'تنظیمات';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('order_id')
                    ->label('شناسه')
                    ->default(fn ($record) => $record->order_id)
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\Select::make('user_id')->relationship('user', 'name')->label('کاربر')->disabled(),
                Forms\Components\TextInput::make('amount')->label('مبلغ'),
                Forms\Components\TextInput::make('type')
                    ->label('نوع تراکنش')
                    ->afterStateHydrated(function ($component, $state, $record) {
                        $component->state(match($record->type) {
                            'deposit' => 'افزایش اعتبار',
                            'purchase' => 'خرید',
                            default => $record->type,
                        });
                    })
                    ->disabled(),


                Forms\Components\TextInput::make('updated_at')->label('تاریخ'),
                Forms\Components\Textarea::make('description')->label('توضیحات'),


            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('تاریخچه تراکنشهای مالی')
            ->columns([
                Tables\Columns\TextColumn::make('order_id')->label('شناسه')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('کاربر')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount')->label('مبلغ')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('عنوان')
                    ->searchable()
                    ->sortable()
                    ->getStateUsing(fn ($record) => match($record->type) {
                        'deposit' => 'افزایش اعتبار',
                        'purchase' => 'خرید',
                        default => $record->type,
                    }),
                Tables\Columns\TextColumn::make('updated_at')->label('تاریخ')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('description')->label('توضیحات')->searchable()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('نوع تراکنش')->options(['purchase' => 'خرید', 'deposit' => 'افزایش اعتبار']),
            ])
            ->actions([
            ]);

    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array { return ['index' => Pages\ListPayments::route('/'),




    ];

    }
}

