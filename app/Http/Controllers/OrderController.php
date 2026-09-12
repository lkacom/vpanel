<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MarzbanService;
use App\Services\XUIServiceContract;
use App\Services\XUIServiceFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function store(Plan $plan): RedirectResponse
    {
        $order = Auth::user()->orders()->create([
            'plan_id' => $plan->id,
            'status'  => 'pending',
            'source'  => 'web',
        ]);

        Auth::user()->notifications()->create([
            'type'    => 'new_order_created',
            'title'   => 'سفارش جدید شما ثبت شد!',
            'message' => "سفارش #{$order->id} برای پلن {$plan->name} با موفقیت ثبت شد و در انتظار پرداخت است.",
            'link'    => route('order.show', $order->id),
        ]);

        return redirect()->route('order.show', $order->id);
    }

    public function show(Order $order): mixed
    {
        if (Auth::id() !== $order->user_id) {
            abort(403, 'شما به این صفحه دسترسی ندارید.');
        }
        if ($order->status === 'paid') {
            return redirect()->route('dashboard')->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }

        return view('payment.show', ['order' => $order]);
    }

    public function processCardPayment(Order $order): mixed
    {
        $order->update(['payment_method' => 'card']);

        return view('payment.card-receipt', [
            'order'    => $order,
            'settings' => Setting::all()->pluck('value', 'key'),
        ]);
    }

    public function showChargeForm(): mixed
    {
        return view('wallet.charge');
    }

    public function createChargeOrder(Request $request): RedirectResponse
    {
        $request->validate(['amount' => 'required|numeric|min:10000']);
        $order = Auth::user()->orders()->create([
            'plan_id' => null,
            'amount'  => $request->amount,
            'status'  => 'pending',
            'source'  => 'web',
        ]);

        Auth::user()->notifications()->create([
            'type'    => 'wallet_charge_pending',
            'title'   => 'درخواست شارژ کیف پول ثبت شد!',
            'message' => 'سفارش شارژ کیف پول به مبلغ ' . number_format($request->amount) . ' تومان در انتظار پرداخت شماست.',
            'link'    => route('order.show', $order->id),
        ]);

        return redirect()->route('order.show', $order->id);
    }

    public function renew(Order $order): RedirectResponse
    {
        if (Auth::id() !== $order->user_id || $order->status !== 'paid') {
            abort(403);
        }

        $newOrder                  = $order->replicate();
        $newOrder->created_at      = now();
        $newOrder->status          = 'pending';
        $newOrder->source          = 'web';
        $newOrder->config_details  = null;
        $newOrder->expires_at      = null;
        $newOrder->renews_order_id = $order->id;
        $newOrder->save();

        Auth::user()->notifications()->create([
            'type'    => 'renewal_order_created',
            'title'   => 'درخواست تمدید سرویس ثبت شد!',
            'message' => "سفارش تمدید سرویس {$order->plan->name} با موفقیت ثبت شد و در انتظار پرداخت است.",
            'link'    => route('order.show', $newOrder->id),
        ]);

        return redirect()->route('order.show', $newOrder->id)
            ->with('status', 'سفارش تمدید شما ایجاد شد. لطفاً هزینه را پرداخت کنید.');
    }

    public function submitCardReceipt(Request $request, Order $order): RedirectResponse
    {
        $request->validate(['receipt' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048']);
        $path = $request->file('receipt')->store('receipts', 'public');
        $order->update(['card_payment_receipt' => $path]);

        Auth::user()->notifications()->create([
            'type'    => 'card_receipt_submitted',
            'title'   => 'رسید پرداخت شما ارسال شد!',
            'message' => "رسید پرداخت سفارش #{$order->id} با موفقیت دریافت شد و در انتظار تایید مدیر است.",
            'link'    => route('order.show', $order->id),
        ]);

        return redirect()->route('dashboard')
            ->with('status', 'رسید شما با موفقیت ارسال شد. پس از تایید توسط مدیر، سرویس شما فعال خواهد شد.');
    }

    public function processWalletPayment(Order $order): RedirectResponse
    {
        if (auth()->id() !== $order->user_id) {
            abort(403);
        }
        if (! $order->plan) {
            return redirect()->back()->with('error', 'این عملیات برای شارژ کیف پول مجاز نیست.');
        }

        $user  = auth()->user();
        $plan  = $order->plan;
        $price = $plan->price;

        if ($user->balance < $price) {
            return redirect()->back()->with('error', 'موجودی کیف پول شما برای انجام این عملیات کافی نیست.');
        }

        try {
            $settings  = Setting::all()->pluck('value', 'key');
            $panelType = $settings->get('panel_type');
            $isRenewal = (bool) $order->renews_order_id;

            $uniqueUsername = $isRenewal
                ? "user-{$user->id}-order-" . $order->renews_order_id
                : "user-{$user->id}-order-" . $order->id;

            // محاسبه تاریخ انقضا
            $baseDate = now();
            if ($isRenewal && $order->renews_order_id) {
                $originalOrder = Order::find($order->renews_order_id);
                if ($originalOrder && $originalOrder->expires_at) {
                    $baseDate = new \DateTime($originalOrder->expires_at);
                }
            }
            $newExpiresAt = (clone $baseDate)->modify("+{$plan->duration_days} days");
            $timestamp    = $newExpiresAt->getTimestamp();

            // ابتدا کلاینت را در پنل بساز (قبل از کسر موجودی)
            [$success, $finalConfig] = match ($panelType) {
                'marzban' => $this->handleMarzban($settings, $plan, $isRenewal, $uniqueUsername, $timestamp),
                'sanaei', 'txui', 'xui' => $this->handleXUI(
                    $panelType, $settings, $plan, $order, $isRenewal,
                    $uniqueUsername, $timestamp, $newExpiresAt
                ),
                default => throw new \Exception('نوع پنل در تنظیمات مشخص نشده است.'),
            };

            if (! $success) {
                throw new \Exception('خطا در ساخت اکانت.');
            }

            // ── کلاینت با موفقیت ساخته شد — حالا موجودی را کسر کن ──
            DB::transaction(function () use ($order, $user, $plan, $price, $finalConfig, $newExpiresAt, $isRenewal) {
                $user->decrement('balance', $price);

                $user->notifications()->create([
                    'type'    => 'wallet_deducted',
                    'title'   => 'کسر از کیف پول شما',
                    'message' => 'مبلغ ' . number_format($price) . " تومان برای سفارش #{$order->id} از کیف پول شما کسر شد.",
                    'link'    => route('dashboard', ['tab' => 'order_history']),
                ]);

                // آپدیت سفارش
                if ($isRenewal) {
                    $originalOrder = Order::find($order->renews_order_id);
                    $originalOrder->update([
                        'config_details' => $finalConfig,
                        'expires_at'     => $newExpiresAt->format('Y-m-d H:i:s'),
                    ]);
                    $user->update(['show_renewal_notification' => true]);
                    $user->notifications()->create([
                        'type'    => 'service_renewed',
                        'title'   => 'سرویس شما تمدید شد!',
                        'message' => "سرویس {$plan->name} با موفقیت تمدید شد.",
                        'link'    => route('dashboard', ['tab' => 'my_services']),
                    ]);
                } else {
                    $order->update([
                        'config_details' => $finalConfig,
                        'expires_at'     => $newExpiresAt,
                    ]);
                    $user->notifications()->create([
                        'type'    => 'service_purchased',
                        'title'   => 'سرویس شما فعال شد!',
                        'message' => "سرویس {$plan->name} با موفقیت خریداری و فعال شد.",
                        'link'    => route('dashboard', ['tab' => 'my_services']),
                    ]);
                }

                $order->update(['status' => 'paid', 'payment_method' => 'wallet']);

                Transaction::create([
                    'user_id'     => $user->id,
                    'order_id'    => $order->id,
                    'amount'      => $price,
                    'type'        => 'purchase',
                    'status'      => 'completed',
                    'description' => ($isRenewal ? 'تمدید سرویس' : 'خرید سرویس') . " {$plan->name} از کیف پول",
                ]);

                OrderPaid::dispatch($order);
            });

        } catch (\Exception $e) {
            Log::error('Wallet Payment Failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            try {
                $order->update(['status' => 'failed', 'payment_method' => 'wallet']);
            } catch (\Throwable) {
            }

            $user->notifications()->create([
                'type'    => 'payment_failed',
                'title'   => 'خطا در فعال‌سازی سرویس!',
                'message' => 'خطا در ساخت اکانت. موجودی کیف پول شما تغییر نکرده است.',
                'link'    => route('dashboard', ['tab' => 'order_history']),
            ]);

            return redirect()->route('dashboard')->with('error', 'خطا در ساخت اکانت. لطفاً دوباره تلاش کنید.');
        }

        return redirect()->route('dashboard')->with('status', 'سرویس شما با موفقیت فعال شد.');
    }

    // ──────────────────────────────────────────────────────────────
    // Marzban
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Support\Collection<string, string>  $settings
     * @return array{bool, string}
     */
    private function handleMarzban($settings, Plan $plan, bool $isRenewal, string $uniqueUsername, int $timestamp): array
    {
        $marzban = new MarzbanService(
            $settings->get('marzban_host'),
            $settings->get('marzban_sudo_username'),
            $settings->get('marzban_sudo_password'),
            $settings->get('marzban_node_hostname')
        );

        $userData = [
            'expire'     => $timestamp,
            'data_limit' => $plan->volume_gb * 1073741824,
        ];

        $response = $isRenewal
            ? $marzban->updateUser($uniqueUsername, $userData)
            : $marzban->createUser(array_merge($userData, ['username' => $uniqueUsername]));

        if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
            return [true, $marzban->generateSubscriptionLink($response)];
        }

        throw new \Exception('خطا در ارتباط با مرزبان: ' . ($response['detail'] ?? 'پاسخ نامعتبر'));
    }

    // ──────────────────────────────────────────────────────────────
    // X-UI (ثنایی و علیرضا)
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Support\Collection<string, string>  $settings
     * @return array{bool, string}
     */
    private function handleXUI(
        string $panelType,
        $settings,
        Plan $plan,
        Order $order,
        bool $isRenewal,
        string $uniqueUsername,
        int $timestamp,
        \DateTimeInterface $newExpiresAt
    ): array {
        $xuiService = XUIServiceFactory::make(
            $panelType,
            (string) $settings->get('xui_host'),
            (string) $settings->get('xui_user'),
            (string) $settings->get('xui_pass')
        );

        // ── بارگذاری inbound های پکیج ──
        $inboundIds = $plan->effective_inbound_ids;

        if (empty($inboundIds)) {
            throw new \Exception('برای این پکیج سروری تعریف نشده است.');
        }

        // اولین inbound را برای دریافت اطلاعات اتصال (port, remark, streamSettings) استفاده می‌کنیم
        $primaryInboundId = (int) $inboundIds[0];
        $primaryInbound   = $this->findInbound($primaryInboundId);
        $primaryData      = $primaryInbound->inbound_data;

        if (! $xuiService->login()) {
            throw new \Exception('خطا در اتصال به پنل.');
        }

        $clientData = [
            'email'      => $uniqueUsername,
            'total'      => $plan->volume_gb * 1073741824,
            'expiryTime' => $timestamp * 1000,
        ];

        if ($isRenewal) {
            return $this->renewXUIClient($xuiService, $settings, $primaryData, $inboundIds, $clientData, $order, $uniqueUsername);
        }

        return $this->createXUIClient($xuiService, $settings, $panelType, $primaryData, $inboundIds, $clientData, $uniqueUsername);
    }

    /**
     * ایجاد کلاینت جدید در X-UI.
     *
     * - پنل ثنایی v3+: یک کلاینت با inboundIds چندگانه ایجاد می‌شود
     * - پنل علیرضا: کلاینت به یک inbound اضافه می‌شود
     *
     * @param  array<int, string>   $inboundIds
     * @param  array<string, mixed> $clientData
     * @param  array<string, mixed> $primaryData
     * @return array{bool, string}
     */
    private function createXUIClient(
        XUIServiceContract $xuiService,
        $settings,
        string $panelType,
        array $primaryData,
        array $inboundIds,
        array $clientData,
        string $uniqueUsername
    ): array {
        // برای پنل ثنایی v3+ همه inbound ها را می‌فرستیم
        // addClient در SanaeiXUIService از inboundIds پشتیبانی می‌کند
        $numericIds = array_map('intval', $inboundIds);

        $response = $xuiService->addClient($numericIds[0], array_merge($clientData, [
            '_all_inbound_ids' => $numericIds,  // برای استفاده در SanaeiXUIService::addClientModern
        ]));

        if (! ($response['success'] ?? false)) {
            throw new \Exception('خطا در ساخت اکانت در پنل.');
        }

        $linkType = $settings->get('xui_link_type', 'single');

        if ($linkType === 'subscription') {
            $subId      = $response['generated_subId'];
            $subBaseUrl = rtrim($settings->get('xui_subscription_url_base', ''), '/');
            if (! $subBaseUrl || ! $subId) {
                throw new \Exception('تنظیمات سابسکریپشن ناقص است.');
            }
            return [true, $subBaseUrl . '/sub/' . $subId];
        }

        // Single link: ساخت لینک VLESS از اطلاعات inbound اول
        $uuid   = $response['generated_uuid'];
        $config = $this->buildVlessLink($uuid, $primaryData, $settings->get('xui_host', ''), $uniqueUsername);

        return [true, $config];
    }

    /**
     * تمدید کلاینت موجود در X-UI.
     *
     * @param  array<int, string>   $inboundIds
     * @param  array<string, mixed> $clientData
     * @param  array<string, mixed> $primaryData
     * @return array{bool, string}
     */
    private function renewXUIClient(
        XUIServiceContract $xuiService,
        $settings,
        array $primaryData,
        array $inboundIds,
        array $clientData,
        Order $order,
        string $uniqueUsername
    ): array {
        $originalOrder = Order::find($order->renews_order_id);
        if (! $originalOrder || ! $originalOrder->config_details) {
            throw new \Exception('اطلاعات سرویس اصلی جهت تمدید یافت نشد.');
        }

        $originalConfig = $originalOrder->config_details;
        $linkType       = $settings->get('xui_link_type', 'single');
        $primaryId      = (int) $inboundIds[0];

        if ($linkType === 'subscription') {
            preg_match('/\/sub\/([a-zA-Z0-9]+)/', $originalConfig, $matches);
            $subId = $matches[1] ?? null;
            if (! $subId) {
                throw new \Exception('شناسه اشتراک (subId) در کانفیگ قبلی یافت نشد.');
            }

            $clientData['subId'] = $subId;
            $clients             = $xuiService->getClients($primaryId);
            $client              = collect($clients)->firstWhere('subId', $subId)
                ?? collect($clients)->firstWhere('email', $uniqueUsername);
            $clientId            = $client['id'] ?? null;

            if (! $clientId) {
                // کلاینت پیدا نشد — ایجاد جدید
                Log::warning('Client not found for renewal (subscription), creating new.', compact('uniqueUsername', 'subId'));
                $addResp = $xuiService->addClient($primaryId, $clientData);
                if (! ($addResp['success'] ?? false)) {
                    throw new \Exception('خطا در تمدید سرویس.');
                }
                $subBaseUrl = rtrim($settings->get('xui_subscription_url_base', ''), '/');
                $newSubId   = $addResp['generated_subId'];
                return [true, $subBaseUrl . '/sub/' . $newSubId];
            }

            $clientData['id'] = $clientId;
            $resp             = $xuiService->updateClient($primaryId, $clientId, $clientData);
            if (! ($resp['success'] ?? false)) {
                throw new \Exception('خطا در تمدید سرویس.');
            }

            return [true, $originalConfig];
        }

        // single link
        preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $originalConfig, $matches);
        $clientId = $matches[1] ?? null;
        if (! $clientId) {
            throw new \Exception('اطلاعات سرویس قبلی نامعتبر است.');
        }

        $clientData['id'] = $clientId;
        $clients          = $xuiService->getClients($primaryId);
        $client           = collect($clients)->firstWhere('id', $clientId)
            ?? collect($clients)->firstWhere('email', $uniqueUsername);

        if (! $client) {
            Log::warning('Client not found for renewal (single), creating new.', compact('uniqueUsername', 'clientId'));
            $addResp = $xuiService->addClient($primaryId, $clientData);
            if (! ($addResp['success'] ?? false)) {
                throw new \Exception('خطا در تمدید سرویس.');
            }
            $config = $this->buildVlessLink($clientId, $primaryData, $settings->get('xui_host', ''), $uniqueUsername);
            return [true, $config];
        }

        $resp = $xuiService->updateClient($primaryId, $clientId, $clientData);
        if (! ($resp['success'] ?? false)) {
            throw new \Exception('خطا در تمدید سرویس.');
        }

        return [true, $originalConfig];
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * پیدا کردن Inbound از دیتابیس بر اساس panel inbound ID.
     */
    private function findInbound(int $panelInboundId): Inbound
    {
        // جستجو با where معمولی — سازگار با MySQL و SQLite
        $inbound = Inbound::query()
            ->where('inbound_data->id', $panelInboundId)
            ->first();

        // fallback: اگر driver از JSON path پشتیبانی نکرد، دستی فیلتر کن
        if (! $inbound) {
            $inbound = Inbound::all()->first(function (Inbound $i) use ($panelInboundId): bool {
                $data = $i->inbound_data;
                return is_array($data) && isset($data['id']) && (int) $data['id'] === $panelInboundId;
            });
        }

        if (! $inbound) {
            throw new \Exception("Inbound با شناسه پنل {$panelInboundId} در دیتابیس یافت نشد. لطفاً سرور را دوباره Sync کنید.");
        }

        return $inbound;
    }

    /**
     * ساخت لینک VLESS از اطلاعات inbound.
     *
     * @param  array<string, mixed>  $inboundData
     */
    private function buildVlessLink(string $uuid, array $inboundData, string $xuiHost, string $remark): string
    {
        $streamSettings = $inboundData['streamSettings'] ?? [];
        if (is_string($streamSettings)) {
            $streamSettings = json_decode($streamSettings, true) ?? [];
        }

        $parsedUrl         = parse_url($xuiHost);
        $serverIpOrDomain  = ! empty($inboundData['listen']) ? $inboundData['listen'] : ($parsedUrl['host'] ?? '');
        $port              = $inboundData['port'] ?? 443;
        $inboundRemark     = $inboundData['remark'] ?? '';

        $paramsArray = array_filter([
            'type'     => $streamSettings['network'] ?? null,
            'security' => $streamSettings['security'] ?? null,
            'path'     => $streamSettings['wsSettings']['path']
                ?? $streamSettings['grpcSettings']['serviceName']
                ?? null,
            'sni'      => $streamSettings['tlsSettings']['serverName'] ?? null,
            'host'     => $streamSettings['wsSettings']['headers']['Host'] ?? null,
        ]);

        $params     = http_build_query($paramsArray);
        $fullRemark = $remark . '|' . $inboundRemark;

        return "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#" . urlencode($fullRemark);
    }

    public function processCryptoPayment(Order $order): RedirectResponse
    {
        $order->update(['payment_method' => 'crypto']);

        Auth::user()->notifications()->create([
            'type'    => 'crypto_payment_info',
            'title'   => 'پرداخت با ارز دیجیتال',
            'message' => "اطلاعات پرداخت با ارز دیجیتال برای سفارش #{$order->id} ثبت شد.",
            'link'    => route('order.show', $order->id),
        ]);

        return redirect()->back()
            ->with('status', '💡 پرداخت با ارز دیجیتال به زودی فعال می‌شود. لطفاً از روش کارت به کارت استفاده کنید.');
    }
}
