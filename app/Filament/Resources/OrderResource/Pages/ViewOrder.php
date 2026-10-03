<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Morilog\Jalali\Jalalian;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'جزئیات سفارش #' . $this->getRecord()->getKey();
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('مشخصات خرید')
                ->columns(3)
                ->schema([
                    TextEntry::make('id')
                        ->label('شماره سفارش')
                        ->formatStateUsing(fn ($state): string => '#' . $state),

                    TextEntry::make('user.name')->label('کاربر'),

                    TextEntry::make('user.email')->label('ایمیل کاربر')->copyable(),

                    TextEntry::make('plan.name')->label('پکیج'),

                    TextEntry::make('renews_order_id')
                        ->label('نوع سفارش')
                        ->formatStateUsing(fn ($state): string => 'تمدید سفارش #' . $state)
                        ->visible(fn (Order $record): bool => filled($record->renews_order_id)),

                    TextEntry::make('plan.volume_gb')
                        ->label('حجم')
                        ->formatStateUsing(fn ($state): string => $state . ' GB'),

                    TextEntry::make('plan.duration_label')->label('مدت'),

                    TextEntry::make('paid_amount')
                        ->label('مبلغ')
                        ->getStateUsing(fn (Order $record) => OrderResource::resolveAmount($record))
                        ->formatStateUsing(fn ($state): string => is_null($state) ? '—' : number_format((float) $state) . ' تومان'),

                    TextEntry::make('payment_method')
                        ->label('روش پرداخت')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => OrderResource::paymentMethodLabel($state))
                        ->color(fn (?string $state): string => OrderResource::paymentMethodColor($state)),

                    TextEntry::make('status')
                        ->label('وضعیت')
                        ->badge()
                        ->getStateUsing(fn (Order $record): string => OrderResource::statusState($record))
                        ->formatStateUsing(fn (string $state): string => OrderResource::statusLabel($state))
                        ->color(fn (string $state): string => OrderResource::statusColor($state)),

                    TextEntry::make('created_at')
                        ->label('تاریخ سفارش')
                        ->formatStateUsing(fn ($state): string => Jalalian::fromDateTime($state)->format('Y/m/d H:i')),

                    TextEntry::make('expires_at')
                        ->label('تاریخ انقضا')
                        ->getStateUsing(fn (Order $record) => $record->service_order->expires_at)
                        ->formatStateUsing(fn ($state): string => $state ? Jalalian::fromDateTime($state)->format('Y/m/d H:i') : '—'),

                    TextEntry::make('zarinpal_ref_id')
                        ->label('کد رهگیری درگاه')
                        ->copyable()
                        ->visible(fn (Order $record): bool => filled($record->zarinpal_ref_id)),
                ]),

            Section::make('رسید پرداخت')
                ->visible(fn (Order $record): bool => filled($record->card_payment_receipt))
                ->schema([
                    ImageEntry::make('card_payment_receipt')
                        ->label('')
                        ->disk('public')
                        ->size(420)
                        ->url(fn (Order $record): ?string => $record->card_payment_receipt
                            ? Storage::disk('public')->url($record->card_payment_receipt) : null)
                        ->openUrlInNewTab(),
                ]),

            Section::make('اطلاعات سرویس')
                ->visible(fn (Order $record): bool => filled($record->service_order->config_details))
                ->schema([
                    TextEntry::make('config_type')
                        ->label('نوع لینک')
                        ->badge()
                        ->color('info')
                        ->getStateUsing(function (Order $record): string {
                            $items = static::configItems($record);

                            return collect($items)->contains(
                                fn (string $item): bool => ! preg_match('#^(vless|vmess|trojan|ss)://#i', $item)
                            )
                                ? 'لینک سابسکریپشن'
                                : 'کانفیگ مستقیم';
                        }),

                    TextEntry::make('config_details')
                        ->label('لینک / کانفیگ خریداری‌شده')
                        ->getStateUsing(fn (Order $record): array => static::configItems($record))
                        ->listWithLineBreaks()
                        ->copyable()
                        ->copyMessage('کپی شد')
                        ->extraAttributes(['dir' => 'ltr', 'class' => 'font-mono text-sm break-all'])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            OrderResource::approveAction(),
        ];
    }

    /**
     * کانفیگ سرویس را به آرایه‌ای از لینک‌ها تبدیل می‌کند
     * (سابسکریپشن / یک کانفیگ / چند کانفیگ ذخیره‌شده به صورت JSON).
     *
     * @return array<int, string>
     */
    private static function configItems(Order $order): array
    {
        $config = trim((string) $order->service_order->config_details);

        if ($config === '') {
            return [];
        }

        $decoded = json_decode($config, true);

        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return [$config];
    }
}
