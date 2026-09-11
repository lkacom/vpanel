<?php

namespace App\Filament\Resources;

use App\Events\OrderPaid;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Plan;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('card_payment_receipt')
                    ->label('رسید')->disk('public')->toggleable()->size(60)
                    ->url(fn (Order $record): ?string => $record->card_payment_receipt
                        ? Storage::disk('public')->url($record->card_payment_receipt) : null)
                    ->openUrlInNewTab(),

                TextColumn::make('user.email')->label('کاربر')->searchable()->sortable(),

                TextColumn::make('plan.name')
                    ->label('عنوان')
                    ->default(fn (Order $record): string => $record->plan_id ? $record->plan->name : 'شارژ کیف پول')
                    ->description(fn (Order $record): string => $record->renews_order_id ? ' (تمدید سفارش #' . $record->renews_order_id . ')' : '')
                    ->color(fn (Order $record) => $record->renews_order_id ? 'primary' : 'gray'),

                TextColumn::make('final_price')
                    ->label('مبلغ')
                    ->getStateUsing(function (Order $record) {
                        if (is_null($record->plan_id)) {
                            return number_format($record->amount) . ' تومان';
                        }
                        if ($record->status === 'pending') {
                            return $record->plan ? number_format($record->plan->price) . ' تومان' : '—';
                        }
                        $transaction = Transaction::where('order_id', $record->id)->first();
                        return $transaction
                            ? number_format($transaction->amount) . ' تومان'
                            : ($record->plan ? number_format($record->plan->price) . ' تومان' : '—');
                    }),

                TextColumn::make('payment_method')
                    ->label('روش پرداخت')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'wallet' => 'کیف پول', 'card' => 'کارت', 'crypto' => 'ارز دیجیتال', default => 'نامشخص',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'wallet' => 'success', 'card' => 'warning', 'crypto' => 'info', default => 'gray',
                    }),

                TextColumn::make('created_at')->label('تاریخ سفارش')->toggleable()->dateTime('Y-m-d')->sortable()
                    ->formatStateUsing(fn ($state) => Jalalian::fromDateTime($state)->format('Y/m/d')),

                TextColumn::make('status')->label('وضعیت')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning', 'paid' => 'success', 'failed' => 'danger', default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده',
                        'expired' => 'منقضی شده', 'failed' => 'خطا', default => $state,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('وضعیت')
                    ->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده']),
                Tables\Filters\SelectFilter::make('source')->label('منبع')->options(['web' => 'وب‌سایت']),
            ])
            ->actions([
                Action::make('approve')
                    ->label('تایید')->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تایید پرداخت سفارش')
                    ->modalDescription('آیا از تایید این پرداخت اطمینان دارید؟')
                    ->button()
                    ->visible(fn (Order $order): bool => $order->status === 'pending')
                    ->action(function (Order $order) {
                        DB::transaction(function () use ($order) {
                            $settings  = Setting::all()->pluck('value', 'key');
                            $user      = $order->user;
                            $plan      = $order->plan;

                            // شارژ کیف پول
                            if (! $plan) {
                                $order->update(['status' => 'paid']);
                                $user->increment('balance', $order->amount);
                                Transaction::create([
                                    'user_id'     => $user->id,
                                    'order_id'    => $order->id,
                                    'amount'      => $order->amount,
                                    'type'        => 'deposit',
                                    'status'      => 'completed',
                                    'description' => 'شارژ کیف پول (تایید دستی فیش)',
                                ]);
                                $user->notifications()->create([
                                    'type'    => 'wallet_charged_approved',
                                    'title'   => 'کیف پول شما شارژ شد!',
                                    'message' => 'مبلغ ' . number_format($order->amount) . ' تومان با موفقیت به کیف پول شما اضافه شد.',
                                    'link'    => route('dashboard', ['tab' => 'order_history']),
                                ]);
                                Notification::make()->title('کیف پول کاربر با موفقیت شارژ شد.')->success()->send();
                                return;
                            }

                            $panelType  = $settings->get('panel_type');
                            $isRenewal  = (bool) $order->renews_order_id;
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

                                    $linkType = $settings->get('xui_link_type', 'single');
                                    if ($linkType === 'subscription') {
                                        $subBaseUrl  = rtrim($settings->get('xui_subscription_url_base', ''), '/');
                                        $finalConfig = $subBaseUrl . '/sub/' . $response['generated_subId'];
                                        $success     = (bool) $subBaseUrl;
                                    } else {
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
                                        $success     = true;
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
                    }),

                Actions\EditAction::make()->button()->label('')->tooltip('ویرایش'),
                Actions\DeleteAction::make()->button()->label('')->tooltip('حذف'),
            ])
            ->bulkActions([Actions\BulkActionGroup::make([Actions\DeleteBulkAction::make()])]);
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
        ];
    }
}
