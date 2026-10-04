<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Services\OrderApprovalService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Morilog\Jalali\Jalalian;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'سفارشات';

    protected static ?string $modelLabel = 'سفارش';

    protected static ?string $pluralModelLabel = 'سفارشات';

    protected static string|\UnitEnum|null $navigationGroup = 'مدیریت مالی';

    /**
     * فقط سفارش‌های خرید/تمدید پکیج؛ درخواست‌های شارژ کیف پول در «مدیریت شارژ» هستند.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->packageOrders()
            ->with(['user', 'plan', 'renewedOrder'])
            ->withSum(['transactions as paid_amount' => fn ($query) => $query->where('status', 'completed')], 'amount');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Select::make('user_id')->inlineLabel()->relationship('user', 'name')->label('کاربر')->disabled(),
            TextInput::make('plan_name')
                ->label('عنوان')
                ->disabled()
                ->inlineLabel()
                ->afterStateHydrated(function ($component, $state, $record) {
                    $component->state(is_null($record->plan_id) ? 'شارژ اعتبار' : ($record->plan?->name ?? ''));
                }),
            TextInput::make('final_price')->label('منبع')->disabled()->inlineLabel(),
            TextInput::make('created_at')
                ->label('تاریخ سفارش')
                ->disabled()
                ->inlineLabel()
                ->afterStateHydrated(fn ($component, $state) => $component->state(
                    is_null($state) ? 'غیرفعال' : Jalalian::fromDateTime($state)->format('Y/m/d')
                )),
            TextInput::make('expires_at')
                ->label('تاریخ انقضاء')
                ->disabled()
                ->inlineLabel()
                ->afterStateHydrated(fn ($component, $state) => $component->state(
                    is_null($state) ? 'غیرفعال' : Jalalian::fromDateTime($state)->format('Y/m/d')
                )),
            Forms\Components\Select::make('status')
                ->inlineLabel()
                ->label('وضعیت سفارش')
                ->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده'])
                ->required(),
            Forms\Components\Textarea::make('config_details')->inlineLabel()->label('اطلاعات کانفیگ سرویس')->rows(10),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers مشترک بین لیست و صفحه جزئیات
    // ──────────────────────────────────────────────────────────────

    public static function paymentMethodLabel(?string $state): string
    {
        return match ($state) {
            'wallet'   => 'کیف پول',
            'card'     => 'کارت به کارت',
            'zarinpal' => 'زرین‌پال',
            'crypto'   => 'ارز دیجیتال',
            null, ''   => 'نامشخص',
            default    => $state,
        };
    }

    public static function paymentMethodColor(?string $state): string
    {
        return match ($state) {
            'wallet'   => 'success',
            'card'     => 'warning',
            'zarinpal' => 'info',
            'crypto'   => 'primary',
            default    => 'gray',
        };
    }

    /** active | inactive | pending | failed */
    public static function statusState(Order $order): string
    {
        return match (true) {
            $order->status === 'paid'    => $order->is_config_active ? 'active' : 'inactive',
            $order->status === 'pending' => 'pending',
            default                      => 'failed',
        };
    }

    public static function statusLabel(string $state): string
    {
        return match ($state) {
            'active'   => 'موفق',
            'inactive' => 'نا موفق',
            'pending'  => 'در انتظار تایید',
            default    => 'خطا',
        };
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'active'  => 'success',
            'pending' => 'warning',
            default   => 'danger',
        };
    }

    /** مبلغ پرداخت‌شده؛ در صورت نبود تراکنش، قیمت پکیج. */
    public static function resolveAmount(Order $order): int|float|null
    {
        return $order->paid_amount ?? $order->plan?->price;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('کاربر')
                    ->description(fn (Order $record): ?string => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'user',
                        fn (Builder $user) => $user->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                    )),

                TextColumn::make('plan.name')
                    ->label('پکیج')
                    ->description(fn (Order $record): ?string => $record->renews_order_id ? 'تمدید سفارش #' . $record->renews_order_id : null)
                    ->color(fn (Order $record) => $record->renews_order_id ? 'primary' : 'gray'),

                TextColumn::make('paid_amount')
                    ->label('مبلغ')
                    ->getStateUsing(fn (Order $record) => static::resolveAmount($record))
                    ->formatStateUsing(fn ($state): string => is_null($state) ? '—' : number_format((float) $state) . ' تومان'),

                TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => Jalalian::fromDateTime($state)->format('Y/m/d H:i')),

                TextColumn::make('payment_method')
                    ->label('روش پرداخت')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::paymentMethodLabel($state))
                    ->color(fn (?string $state): string => static::paymentMethodColor($state)),

                TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->getStateUsing(fn (Order $record): string => static::statusState($record))
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state))
                    ->color(fn (string $state): string => static::statusColor($state)),

                ImageColumn::make('card_payment_receipt')
                    ->label('رسید کارت')
                    ->getStateUsing(fn (Order $record): ?string => $record->receipt_url)
                    ->size(60)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->url(fn (Order $record): ?string => $record->receipt_url)
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Order $record): string => static::getUrl('view', ['record' => $record]))
            ->filters([
                Tables\Filters\SelectFilter::make('config_state')
                    ->label('وضعیت کانفیگ')
                    ->options(['active' => 'فعال', 'inactive' => 'غیرفعال'])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active'   => $query->withActiveConfig(),
                            'inactive' => $query->where('status', 'paid')
                                ->whereNotIn('orders.id', Order::query()->withActiveConfig()->select('orders.id')),
                            default    => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('روش پرداخت')
                    ->options([
                        'wallet'   => 'کیف پول',
                        'card'     => 'کارت به کارت',
                        'zarinpal' => 'زرین‌پال',
                    ]),
            ])
            ->actions([
                static::approveAction(),
                Actions\ViewAction::make()->button()->label('')->tooltip('جزئیات'),
            ])
            ->bulkActions([Actions\BulkActionGroup::make([Actions\DeleteBulkAction::make()])]);
    }

    /**
     * تایید دستی پرداخت کارت به کارت — فقط برای سفارش‌های در انتظار.
     * سفارش‌های موفق دکمه تایید ندارند.
     *
     * ساخت سرویس در پنل از همان مسیر مشترک پرداخت‌ها (زرین‌پال / کیف پول) انجام می‌شود.
     */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('تایید')->icon('heroicon-o-check-circle')->color('success')
            ->requiresConfirmation()
            ->modalHeading('تایید پرداخت سفارش')
            ->modalDescription('با تایید، سرویس در پنل ساخته (یا تمدید) و برای کاربر فعال می‌شود. آیا از تایید این پرداخت اطمینان دارید؟')
            ->button()
            ->visible(fn (Order $order): bool => $order->status === 'pending')
            ->action(function (Order $order): void {
                try {
                    app(OrderApprovalService::class)->approveCardPayment($order);

                    Notification::make()->title('پرداخت تایید و سرویس فعال شد.')->success()->send();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('خطا در ساخت سرویس')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
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
            'index'  => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'view'   => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
