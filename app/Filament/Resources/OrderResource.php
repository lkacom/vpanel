<?php

namespace App\Filament\Resources;

use App\Events\OrderPaid;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MarzbanService;
use App\Services\XUIServiceFactory;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'active'   => 'فعال',
            'inactive' => 'غیرفعال',
            'pending'  => 'در انتظار تایید',
            default    => 'ناموفق',
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
                    ->disk('public')
                    ->size(60)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->url(fn (?Order $record): ?string => $record?->card_payment_receipt
                        ? Storage::disk('public')->url($record->card_payment_receipt) : null)
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
     */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('تایید')->icon('heroicon-o-check-circle')->color('success')
            ->requiresConfirmation()
            ->modalHeading('تایید پرداخت سفارش')
            ->modalDescription('آیا از تایید این پرداخت اطمینان دارید؟')
            ->button()
            ->visible(fn (Order $order): bool => $order->status === 'pending')
            ->action(function (Order $order) {
                DB::transaction(function () use ($order) {
                    $settings = Setting::all()->pluck('value', 'key');
                    $user     = $order->user;
                    $plan     = $order->plan;

                    if (! $plan) {
                        Notification::make()->title('خطا')->body('پکیج این سفارش یافت نشد. درخواست‌های شارژ از منوی «مدیریت شارژ» بررسی می‌شوند.')->danger()->send();
                        return;
                    }

                    $panelType     = $settings->get('panel_type');
                    $isRenewal     = (bool) $order->renews_order_id;
                    $originalOrder = $isRenewal ? Order::find($order->renews_order_id) : null;

                    if ($isRenewal && ! $originalOrder) {
                        Notification::make()->title('خطا')->body('سفارش اصلی جهت تمدید یافت نشد.')->danger()->send();
                        return;
                    }

                    $uniqueUsername = "user-{$user->id}-order-" . ($isRenewal ? $originalOrder->id : $order->id);
                    $newExpiresAt   = $isRenewal
                        ? (new \DateTime($originalOrder->expires_at))->modify("+{$plan->duration_days} days")
                        : now()->addDays($plan->duration_days);

                    $finalConfig = '';
                    $success     = false;

                    try {
                        if ($panelType === 'marzban') {
                            $marzban  = new MarzbanService(
                                $settings->get('marzban_host'),
                                $settings->get('marzban_sudo_username'),
                                $settings->get('marzban_sudo_password'),
                                $settings->get('marzban_node_hostname')
                            );
                            $userData = [
                                'expire'     => $newExpiresAt->getTimestamp(),
                                'data_limit' => $plan->volume_gb * 1073741824,
                            ];
                            $response = $isRenewal
                                ? $marzban->updateUser($uniqueUsername, $userData)
                                : $marzban->createUser(array_merge($userData, ['username' => $uniqueUsername]));

                            if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                                $finalConfig = $marzban->generateSubscriptionLink($response);
                                $success     = true;
                            } else {
                                Notification::make()->title('خطا در مرزبان')->body($response['detail'] ?? 'پاسخ نامعتبر')->danger()->send();
                                return;
                            }

                        } elseif (in_array($panelType, ['sanaei', 'txui', 'xui'], true)) {
                            $xuiService = XUIServiceFactory::make(
                                $panelType,
                                (string) $settings->get('xui_host'),
                                (string) $settings->get('xui_user'),
                                (string) $settings->get('xui_pass')
                            );

                            // بارگذاری inbound های پکیج
                            $inboundIds = $plan->effective_inbound_ids;
                            if (empty($inboundIds)) {
                                Notification::make()->title('خطا')->body('برای این پکیج Inbound انتخاب نشده است.')->danger()->send();
                                return;
                            }

                            $primaryInboundId = (int) $inboundIds[0];
                            $inbound          = static::findInbound($primaryInboundId);
                            if (! $inbound) {
                                Notification::make()->title('خطا')->body("Inbound با ID {$primaryInboundId} در دیتابیس یافت نشد.")->danger()->send();
                                return;
                            }

                            if (! $xuiService->login()) {
                                Notification::make()->title('خطا')->body('خطا در لاگین به پنل X-UI.')->danger()->send();
                                return;
                            }

                            $inboundData = is_string($inbound->inbound_data)
                                ? json_decode($inbound->inbound_data, true)
                                : $inbound->inbound_data;

                            $clientData = [
                                'email'            => $uniqueUsername,
                                'total'            => $plan->volume_gb * 1073741824,
                                'expiryTime'       => $newExpiresAt->getTimestamp() * 1000,
                                '_all_inbound_ids' => array_map('intval', $inboundIds),
                            ];

                            if ($isRenewal) {
                                // تمدید
                                Notification::make()->title('خطا')->body('تمدید خودکار برای پنل X-UI از پنل ادمین پشتیبانی نمی‌شود. کاربر باید از داشبورد تمدید کند.')->warning()->send();
                                return;
                            }

                            $response = $xuiService->addClient($primaryInboundId, $clientData);

                            if (! ($response['success'] ?? false)) {
                                Notification::make()->title('خطا در ساخت کاربر در پنل')->body($response['msg'] ?? 'پاسخ نامعتبر')->danger()->send();
                                return;
                            }

                            // تشخیص خودکار نوع لینک: سابسکریپشن یا تکی
                            $subBaseUrl = $xuiService->getSubscriptionBaseUrl();
                            if ($subBaseUrl) {
                                $subId = $response['generated_subId'] ?? null;
                                if ($subId) {
                                    $finalConfig = $subBaseUrl . '/' . $subId;
                                    $success = true;
                                }
                            }

                            if (! $success) {
                                // Single link
                                $uuid           = $response['generated_uuid'];
                                $streamSettings = $inboundData['streamSettings'] ?? [];
                                if (is_string($streamSettings)) {
                                    $streamSettings = json_decode($streamSettings, true) ?? [];
                                }
                                $parsedUrl        = parse_url($settings->get('xui_host', ''));
                                $serverIpOrDomain = ! empty($inboundData['listen']) ? $inboundData['listen'] : ($parsedUrl['host'] ?? '');
                                $port             = $inboundData['port'] ?? 443;
                                $remark           = $inboundData['remark'] ?? '';
                                $paramsArray      = array_filter([
                                    'type'     => $streamSettings['network'] ?? null,
                                    'security' => $streamSettings['security'] ?? null,
                                    'path'     => $streamSettings['wsSettings']['path'] ?? $streamSettings['grpcSettings']['serviceName'] ?? null,
                                    'sni'      => $streamSettings['tlsSettings']['serverName'] ?? null,
                                    'host'     => $streamSettings['wsSettings']['headers']['Host'] ?? null,
                                ]);
                                $params      = http_build_query($paramsArray);
                                $fullRemark  = $uniqueUsername . '|' . $remark;
                                $finalConfig = "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#" . urlencode($fullRemark);
                                $success = true;
                            }

                        } else {
                            Notification::make()->title('خطا')->body('نوع پنل در تنظیمات مشخص نشده است.')->danger()->send();
                            return;
                        }
                    } catch (\Exception $e) {
                        Notification::make()->title('خطا')->body($e->getMessage())->danger()->send();
                        return;
                    }

                    if (! $success) {
                        Notification::make()->title('خطا')->body('خطا در فعال‌سازی سرویس.')->danger()->send();
                        return;
                    }

                    $order->update([
                        'config_details' => $finalConfig,
                        'expires_at'     => $newExpiresAt,
                        'status'         => 'paid',
                    ]);

                    Transaction::create([
                        'user_id'     => $user->id,
                        'order_id'    => $order->id,
                        'amount'      => $plan->price,
                        'type'        => 'purchase',
                        'status'      => 'completed',
                        'description' => "خرید سرویس {$plan->name}",
                    ]);

                    $user->notifications()->create([
                        'type'    => 'service_activated_admin',
                        'title'   => 'سرویس شما فعال شد!',
                        'message' => "خرید سرویس {$plan->name} توسط مدیر تایید و فعال شد.",
                        'link'    => route('dashboard', ['tab' => 'my_services']),
                    ]);

                    OrderPaid::dispatch($order);
                    Notification::make()->title('عملیات با موفقیت انجام شد.')->success()->send();
                });
            });
    }

    /**
     * پیدا کردن Inbound از دیتابیس با panel inbound ID
     */
    private static function findInbound(int $panelInboundId): ?Inbound
    {
        $inbound = Inbound::query()->where('inbound_data->id', $panelInboundId)->first();
        if (! $inbound) {
            $inbound = Inbound::all()->first(function (Inbound $i) use ($panelInboundId): bool {
                $data = $i->inbound_data;
                return is_array($data) && isset($data['id']) && (int) $data['id'] === $panelInboundId;
            });
        }
        return $inbound;
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
