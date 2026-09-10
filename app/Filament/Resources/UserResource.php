<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Hash;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static string|\UnitEnum|null $navigationGroup = 'مدیریت کاربران';

    protected static ?string $navigationLabel = 'کاربران سایت';
    protected static ?string $pluralModelLabel = 'کاربران سایت';
    protected static ?string $modelLabel = 'کاربر';
    public static function getNavigationBadge(): ?string
    {
        return static::$model::count();
    }
    protected static string|\Illuminate\Contracts\Support\Htmlable|null $navigationBadgeTooltip = 'تعداد کاربران';

    public static function form(Schema $schema): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('نام')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('ایمیل')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->label('رمز عبور جدید')
                    ->password()
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $context): bool => $context === 'create')
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_admin')
                    ->label('دسترسی کامل مدیریت'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('ایمیل')->searchable(),
                Tables\Columns\IconColumn::make('is_admin')->label('ادمین')->boolean()->icon('heroicon-o-users')->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ ثبت‌نام')->dateTime('Y-m-d')->sortable()->formatStateUsing(function ($state) {
                    return Jalalian::fromDateTime($state)->format('Y/m/d');
                }),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->button()->label(''),
                Tables\Actions\DeleteAction::make()->button()->label(''),

                Tables\Actions\Action::make('adjust_wallet')
                    ->label('تنظیم کیف پول')
                    ->icon('heroicon-o-currency-dollar')
                    ->button()
                    ->color('warning')
                    ->modalHeading(fn (User $record) => "تنظیم کیف پول: {$record->name}")
                    ->modalDescription('موجودی کیف پول کاربر را افزایش یا کاهش دهید')
                    ->modalSubmitActionLabel('اعمال تغییر')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Placeholder::make('current_balance')
                            ->label('موجودی فعلی')
                            ->content(fn (User $record) => '💰 ' . number_format($record->balance ?? 0) . ' تومان')
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('amount')
                                    ->label('مبلغ تغییر')
                                    ->numeric()
                                    ->required()
                                    ->prefix('﷼')
                                    ->suffix('تومان')
                                    ->helperText('مثال: +100000 یا -50000')
                                    ->hint('عدد منفی برای کاهش')
                                    ->rules(['required', 'numeric', 'not_in:0'])
                                    ->live(onBlur: true),

                                Forms\Components\Placeholder::make('new_balance_preview')
                                    ->label('موجودی جدید')
                                    ->content(function (callable $get, User $record) {
                                        $amount = (int) $get('amount');
                                        if ($amount === 0 || empty($get('amount'))) return '—';
                                        $newBalance = ($record->balance ?? 0) + $amount;
                                        $emoji = $amount > 0 ? '⬆️' : '⬇️';
                                        return "{$emoji} " . number_format($newBalance) . ' تومان';
                                    }),
                            ]),

                        Forms\Components\Textarea::make('description')
                            ->label('دلیل تغییر')
                            ->required()
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('این توضیحات در تراکنش ثبت و به اطلاع کاربر می‌رسد')
                            ->placeholder('مثال: هدیه ویژه، جبران خسارت، یا تغییر دستی...'),
                    ])
                    ->action(function (User $record, array $data) {
                        $amount = (int) $data['amount'];
                        $description = $data['description'];

                        DB::transaction(function () use ($record, $amount, $description) {
                            $oldBalance = $record->balance;
                            $record->increment('balance', $amount);

                            Transaction::create([
                                'user_id' => $record->id,
                                'order_id' => null,
                                'amount' => $amount,
                                'type' => $amount > 0 ? 'deposit' : 'withdraw',
                                'status' => 'completed',
                                'description' => "تنظیم دستی توسط ادمین: {$description}",
                                'payment_method' => 'manual_admin',
                            ]);
                        });

                        Notification::make()
                            ->title('موفقیت')
                            ->body("کیف پول کاربر {$record->name} با موفقیت به‌روزرسانی شد ✅")
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalIconColor('warning')
                    ->modalSubmitActionLabel('بله، اعمال شود'),


            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),

        ];
    }
}
