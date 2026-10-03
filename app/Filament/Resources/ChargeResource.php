<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChargeResource\Pages;
use App\Models\Order;
use App\Services\WalletChargeService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Morilog\Jalali\Jalalian;
use RuntimeException;

/**
 * مدیریت درخواست‌های شارژ کیف پول که با «کارت به کارت» ثبت شده‌اند.
 */
class ChargeResource extends Resource
{
    protected static ?string $model = Order::class;

    // همین مدل در OrderResource هم استفاده می‌شود؛ slug جداگانه برای جلوگیری از تداخل route
    protected static ?string $slug = 'charge-requests';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'مدیریت شارژ';

    protected static ?string $modelLabel = 'درخواست شارژ';

    protected static ?string $pluralModelLabel = 'درخواست‌های شارژ';

    protected static string|\UnitEnum|null $navigationGroup = 'مدیریت مالی';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->chargeRequests()
            ->where('payment_method', 'card')
            ->with('user');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** تعداد فیش‌های ارسال‌شده‌ای که منتظر بررسی هستند. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('status', 'pending')
            ->whereNotNull('card_payment_receipt')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function statusLabel(?string $state): string
    {
        return match ($state) {
            'pending' => 'در انتظار بررسی',
            'paid'    => 'تایید شده',
            'failed'  => 'رد شده',
            default   => (string) $state,
        };
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'pending' => 'warning',
            'paid'    => 'success',
            'failed'  => 'danger',
            default   => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('card_payment_receipt')
                    ->label('فیش')
                    ->getStateUsing(fn (Order $record): ?string => $record->receipt_url)
                    ->size(60)
                    ->url(fn (Order $record): ?string => $record->receipt_url)
                    ->openUrlInNewTab(),

                TextColumn::make('user.name')
                    ->label('کاربر')
                    ->description(fn (Order $record): ?string => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'user',
                        fn (Builder $user) => $user->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                    )),

                TextColumn::make('amount')
                    ->label('مبلغ')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state) . ' تومان'),

                TextColumn::make('created_at')
                    ->label('تاریخ درخواست')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => Jalalian::fromDateTime($state)->format('Y/m/d H:i')),

                TextColumn::make('receipt_state')
                    ->label('فیش')
                    ->badge()
                    ->getStateUsing(fn (Order $record): string => filled($record->card_payment_receipt) ? 'sent' : 'missing')
                    ->formatStateUsing(fn (string $state): string => $state === 'sent' ? 'ارسال شده' : 'ارسال نشده')
                    ->color(fn (string $state): string => $state === 'sent' ? 'success' : 'danger'),

                TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::statusLabel($state))
                    ->color(fn (?string $state): string => static::statusColor($state)),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Order $record): string => static::getUrl('view', ['record' => $record]))
            ->filters([
                Tables\Filters\Filter::make('has_receipt')
                    ->label('فقط درخواست‌های دارای فیش')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('card_payment_receipt')),
            ])
            ->actions([
                static::approveAction(),
                static::rejectAction(),
                Actions\ViewAction::make()->button()->label('بررسی فیش'),
            ]);
    }

    /** تایید فیش و شارژ کیف پول — فقط برای درخواست‌های در انتظارِ دارای فیش. */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('تایید و شارژ')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->button()
            ->requiresConfirmation()
            ->modalHeading('تایید فیش و شارژ کیف پول')
            ->modalDescription(fn (Order $record): string => 'مبلغ ' . number_format((float) $record->amount)
                . ' تومان به کیف پول ' . ($record->user?->name ?? 'کاربر')
                . ' اضافه می‌شود. فیش واریزی را بررسی کرده‌اید؟')
            ->visible(fn (Order $record): bool => $record->status === 'pending' && filled($record->card_payment_receipt))
            ->action(function (Order $record): void {
                try {
                    app(WalletChargeService::class)->approve($record);
                    Notification::make()->title('کیف پول کاربر با موفقیت شارژ شد.')->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title('خطا')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** رد فیش — کاربر با یک اعلان مطلع می‌شود. */
    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('رد فیش')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->button()
            ->modalHeading('رد درخواست شارژ')
            ->form([
                Textarea::make('reason')
                    ->label('دلیل رد (اختیاری — برای کاربر نمایش داده می‌شود)')
                    ->rows(3),
            ])
            ->visible(fn (Order $record): bool => $record->status === 'pending')
            ->action(function (Order $record, array $data): void {
                try {
                    app(WalletChargeService::class)->reject($record, $data['reason'] ?? null);
                    Notification::make()->title('درخواست شارژ رد شد.')->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title('خطا')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCharges::route('/'),
            'view'  => Pages\ViewCharge::route('/{record}'),
        ];
    }
}
