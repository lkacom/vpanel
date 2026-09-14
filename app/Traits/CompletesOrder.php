<?php

namespace App\Traits;

use App\Events\OrderPaid;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MarzbanService;
use App\Services\XUIServiceFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * منطق مشترک تکمیل سفارش پس از پرداخت موفق
 * هم ZarinpalController و هم OrderController از این trait استفاده می‌کنند
 */
trait CompletesOrder
{
    protected function completeOrder(Order $order, string $paymentMethod, string $transactionNote = ''): void
    {
        $user   = $order->user;
        $amount = $order->plan_id
            ? (int) optional($order->plan)->price
            : (int) $order->amount;

        if ($order->plan_id) {
            $this->provisionVpnService($order, $user, $amount, $paymentMethod, $transactionNote);
        } else {
            DB::transaction(function () use ($order, $user, $amount, $paymentMethod, $transactionNote) {
                $order->update(['status' => 'paid', 'payment_method' => $paymentMethod]);
                $user->increment('balance', $amount);
                Transaction::create([
                    'user_id'     => $user->id,
                    'order_id'    => $order->id,
                    'amount'      => $amount,
                    'type'        => Transaction::TYPE_DEPOSIT,
                    'status'      => Transaction::STATUS_COMPLETED,
                    'description' => $transactionNote ?: 'شارژ کیف پول',
                ]);
                $user->notifications()->create([
                    'type'    => 'wallet_charged',
                    'title'   => 'کیف پول شما شارژ شد',
                    'message' => 'مبلغ ' . number_format($amount) . ' تومان به کیف پول شما اضافه شد.',
                    'link'    => route('dashboard'),
                ]);
                OrderPaid::dispatch($order);
            });
        }
    }

    private function provisionVpnService(Order $order, $user, int $price, string $paymentMethod, string $transactionNote): void
    {
        $settings  = Setting::all()->pluck('value', 'key');
        $panelType = $settings->get('panel_type');
        $isRenewal = (bool) $order->renews_order_id;

        $uniqueUsername = $isRenewal
            ? "user-{$user->id}-order-" . $order->renews_order_id
            : "user-{$user->id}-order-" . $order->id;

        $plan     = $order->plan;
        $baseDate = now();

        if ($isRenewal && $order->renews_order_id) {
            $originalOrder = Order::find($order->renews_order_id);
            if ($originalOrder && $originalOrder->expires_at) {
                $baseDate = new \DateTime($originalOrder->expires_at);
            }
        }

        $newExpiresAt = (clone $baseDate)->modify("+{$plan->duration_days} days");
        $timestamp    = $newExpiresAt->getTimestamp();

        [$success, $finalConfig] = match ($panelType) {
            'marzban'             => $this->handleMarzban($settings, $plan, $isRenewal, $uniqueUsername, $timestamp),
            'sanaei', 'txui', 'xui' => $this->handleXUI($panelType, $settings, $plan, $order, $isRenewal, $uniqueUsername, $timestamp, $newExpiresAt),
            default               => throw new \Exception('نوع پنل در تنظیمات مشخص نشده است.'),
        };

        if (! $success) {
            throw new \Exception('خطا در ساخت اکانت در پنل.');
        }

        DB::transaction(function () use ($order, $user, $plan, $price, $finalConfig, $newExpiresAt, $isRenewal, $paymentMethod, $transactionNote) {
            if ($isRenewal) {
                $originalOrder = Order::find($order->renews_order_id);
                $originalOrder?->update([
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
                $order->update(['config_details' => $finalConfig, 'expires_at' => $newExpiresAt]);
                $user->notifications()->create([
                    'type'    => 'service_purchased',
                    'title'   => 'سرویس شما فعال شد!',
                    'message' => "سرویس {$plan->name} با موفقیت خریداری و فعال شد.",
                    'link'    => route('dashboard', ['tab' => 'my_services']),
                ]);
            }

            $order->update(['status' => 'paid', 'payment_method' => $paymentMethod]);

            Transaction::create([
                'user_id'     => $user->id,
                'order_id'    => $order->id,
                'amount'      => $price,
                'type'        => Transaction::TYPE_PURCHASE,
                'status'      => Transaction::STATUS_COMPLETED,
                'description' => $transactionNote ?: (($isRenewal ? 'تمدید' : 'خرید') . " سرویس {$plan->name}"),
            ]);

            OrderPaid::dispatch($order);
        });
    }

    // ── Marzban ──────────────────────────────────────────────────────────────

    private function handleMarzban($settings, $plan, bool $isRenewal, string $uniqueUsername, int $timestamp): array
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

        if (! $response || (! isset($response['subscription_url']) && ! isset($response['username']))) {
            throw new \Exception('خطا در ارتباط با مرزبان: ' . ($response['detail'] ?? 'پاسخ نامعتبر'));
        }

        $subEnabled   = filter_var($settings->get('xui_subscription_enabled') ?? true, FILTER_VALIDATE_BOOLEAN);
        $nodeHostname = rtrim($settings->get('marzban_node_hostname', ''), '/');
        $subPath      = ltrim($response['subscription_url'] ?? '', '/');
        $subUrl       = $nodeHostname . '/' . $subPath;

        if ($subEnabled) {
            return [true, $subUrl];
        }

        // Sub غیرفعال — کانفیگ‌های مستقیم fetch کن
        $configs = $this->fetchMarzbanDirectConfigs($subUrl);
        if (! empty($configs)) {
            return [true, count($configs) === 1 ? $configs[0] : json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }

        throw new \Exception('نمی‌توان کانفیگ سرویس را از مرزبان دریافت کرد.');
    }

    private function fetchMarzbanDirectConfigs(string $subUrl): array
    {
        try {
            $response = Http::timeout(15)->get($subUrl);
            if ($response->successful()) {
                $body    = trim($response->body());
                $decoded = base64_decode($body, true);
                $text    = ($decoded !== false && preg_match('/^(vless|vmess|trojan|ss):\/\//', trim($decoded))) ? $decoded : $body;
                $lines   = array_filter(
                    array_map('trim', explode("\n", $text)),
                    fn ($l) => preg_match('/^(vless|vmess|trojan|ss):\/\//', $l)
                );
                return array_values($lines);
            }
        } catch (\Throwable $e) {
            Log::warning('fetchMarzbanDirectConfigs failed', ['url' => $subUrl, 'error' => $e->getMessage()]);
        }
        return [];
    }

    // ── X-UI (Sanaei / TXUI) ─────────────────────────────────────────────────

    private function handleXUI(string $panelType, $settings, $plan, Order $order, bool $isRenewal, string $uniqueUsername, int $timestamp, \DateTimeInterface $newExpiresAt): array
    {
        $xuiService = XUIServiceFactory::make(
            $panelType,
            (string) $settings->get('xui_host'),
            (string) $settings->get('xui_user'),
            (string) $settings->get('xui_pass')
        );

        $inboundIds = $plan->effective_inbound_ids;
        if (empty($inboundIds)) {
            throw new \Exception('برای این پکیج سروری تعریف نشده است.');
        }

        $primaryInbound = $this->findInbound((int) $inboundIds[0]);
        $primaryData    = $primaryInbound->inbound_data;

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

        return $this->createXUIClient($xuiService, $settings, $primaryData, $inboundIds, $clientData, $uniqueUsername);
    }

    private function createXUIClient($xuiService, $settings, array $primaryData, array $inboundIds, array $clientData, string $uniqueUsername): array
    {
        $numericIds = array_map('intval', $inboundIds);
        $subEnabled = filter_var($settings->get('xui_subscription_enabled') ?? false, FILTER_VALIDATE_BOOLEAN);

        $response = $xuiService->addClient($numericIds[0], array_merge($clientData, ['_all_inbound_ids' => $numericIds]));

        if (! ($response['success'] ?? false)) {
            throw new \Exception('خطا در ساخت اکانت در پنل.');
        }

        $inboundId = isset($primaryData['id']) && is_numeric($primaryData['id']) ? (int) $primaryData['id'] : $numericIds[0];
        $uuid      = $response['generated_uuid'] ?? null;
        $subId     = $response['generated_subId'] ?? null;

        // ── حالت Subscription فعال ───────────────────────────────────────────
        if ($subEnabled) {
            $subInfo = $xuiService->getSubscriptionUrl($inboundId);

            if ($subInfo && $subId) {
                return [true, rtrim($subInfo['url'], '/') . '/' . $subId];
            }

            // subId در response نیست — از clients بخوان
            if ($subInfo) {
                $clients = $xuiService->getClients($inboundId);
                $client  = collect($clients)->firstWhere('email', $uniqueUsername);
                $sid     = $client['subId'] ?? $client['id'] ?? null;
                if ($sid) {
                    return [true, rtrim($subInfo['url'], '/') . '/' . $sid];
                }
            }

            // آدرس subscription را از تنظیمات admin بساز
            $subPort = $settings->get('xui_subscription_port', '2096');
            $subPath = rtrim($settings->get('xui_subscription_path', '/sub'), '/');
            $host    = parse_url((string) $settings->get('xui_host', ''), PHP_URL_HOST) ?? '';
            $scheme  = parse_url((string) $settings->get('xui_host', ''), PHP_URL_SCHEME) ?? 'https';
            if ($host && $subId) {
                return [true, "{$scheme}://{$host}:{$subPort}{$subPath}/{$subId}"];
            }
        }

        // ── حالت کانفیگ مستقیم (Sub غیرفعال) ───────────────────────────────
        if (! $uuid) {
            throw new \Exception('UUID کلاینت از پنل دریافت نشد.');
        }

        $configs = [];
        foreach ($numericIds as $ibId) {
            try {
                $inbound   = $this->findInbound($ibId);
                $configs[] = $this->buildVlessLink($uuid, $inbound->inbound_data, (string) $settings->get('xui_host', ''), $uniqueUsername);
            } catch (\Exception $e) {
                Log::warning('CompletesOrder: skip inbound ' . $ibId, ['error' => $e->getMessage()]);
            }
        }

        if (empty($configs)) {
            throw new \Exception('هیچ کانفیگی ساخته نشد.');
        }

        // ۱ inbound → رشته ساده | چند inbound → JSON آرایه
        return [true, count($configs) === 1 ? $configs[0] : json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    private function renewXUIClient($xuiService, $settings, array $primaryData, array $inboundIds, array $clientData, Order $order, string $uniqueUsername): array
    {
        $originalOrder = Order::find($order->renews_order_id);
        if (! $originalOrder?->config_details) {
            throw new \Exception('اطلاعات سرویس اصلی جهت تمدید یافت نشد.');
        }

        $originalConfig = $originalOrder->config_details;
        $primaryId      = (int) $inboundIds[0];

        // تشخیص نوع config قبلی
        $decodedConfigs = json_decode($originalConfig, true);
        $isMultiConfig  = is_array($decodedConfigs);
        $isSubscription = ! $isMultiConfig && str_contains($originalConfig, '/sub/');

        if ($isSubscription) {
            preg_match('/\/sub\/([a-zA-Z0-9]+)/', $originalConfig, $matches);
            $subId = $matches[1] ?? null;
            if (! $subId) throw new \Exception('شناسه اشتراک در کانفیگ قبلی یافت نشد.');

            $clientData['subId'] = $subId;
            $clients  = $xuiService->getClients($primaryId);
            $client   = collect($clients)->firstWhere('subId', $subId) ?? collect($clients)->firstWhere('email', $uniqueUsername);
            $clientId = $client['id'] ?? null;

            if (! $clientId) {
                $addResp = $xuiService->addClient($primaryId, $clientData);
                if (! ($addResp['success'] ?? false)) throw new \Exception('خطا در تمدید سرویس.');
                $subInfo = $xuiService->getSubscriptionUrl($primaryId);
                return [true, rtrim($subInfo['url'] ?? '', '/') . '/' . $addResp['generated_subId']];
            }

            $clientData['id'] = $clientId;
            $resp = $xuiService->updateClient($primaryId, $clientId, $clientData);
            if (! ($resp['success'] ?? false)) throw new \Exception('خطا در تمدید سرویس.');
            return [true, $originalConfig];
        }

        // رشته VLESS یا JSON از چند VLESS — UUID را استخراج کن
        $searchIn = $isMultiConfig ? ($decodedConfigs[0] ?? $originalConfig) : $originalConfig;
        preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $searchIn, $matches);
        $clientId = $matches[1] ?? null;
        if (! $clientId) throw new \Exception('UUID سرویس قبلی یافت نشد.');

        $clientData['id'] = $clientId;
        $clients  = $xuiService->getClients($primaryId);
        $client   = collect($clients)->firstWhere('id', $clientId) ?? collect($clients)->firstWhere('email', $uniqueUsername);

        if (! $client) {
            // کلاینت وجود ندارد — بساز
            $addResp = $xuiService->addClient($primaryId, $clientData);
            if (! ($addResp['success'] ?? false)) throw new \Exception('خطا در تمدید سرویس.');
            // config را مثل ساخت جدید برگردان
            return $this->createXUIClient($xuiService, $settings, $primaryData, $inboundIds,
                array_merge($clientData, ['id' => $addResp['generated_uuid'] ?? $clientId]), $uniqueUsername);
        }

        $resp = $xuiService->updateClient($primaryId, $clientId, $clientData);
        if (! ($resp['success'] ?? false)) throw new \Exception('خطا در تمدید سرویس.');
        return [true, $originalConfig]; // config تغییر نمی‌کند
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findInbound(int $panelInboundId): Inbound
    {
        $inbound = Inbound::query()->where('inbound_data->id', $panelInboundId)->first();

        if (! $inbound) {
            $inbound = Inbound::all()->first(fn (Inbound $i) =>
                is_array($i->inbound_data) && isset($i->inbound_data['id']) && (int) $i->inbound_data['id'] === $panelInboundId
            );
        }

        if (! $inbound) {
            throw new \Exception("Inbound {$panelInboundId} یافت نشد. لطفاً سرور را Sync کنید.");
        }

        return $inbound;
    }

    private function buildVlessLink(string $uuid, array $inboundData, string $xuiHost, string $remark): string
    {
        $streamSettings = $inboundData['streamSettings'] ?? [];
        if (is_string($streamSettings)) {
            $streamSettings = json_decode($streamSettings, true) ?? [];
        }

        $parsedUrl        = parse_url($xuiHost);
        $serverIpOrDomain = ! empty($inboundData['listen']) ? $inboundData['listen'] : ($parsedUrl['host'] ?? '');
        $port             = $inboundData['port'] ?? 443;
        $inboundRemark    = $inboundData['remark'] ?? '';

        $params = http_build_query(array_filter([
            'type'     => $streamSettings['network'] ?? null,
            'security' => $streamSettings['security'] ?? null,
            'path'     => $streamSettings['wsSettings']['path'] ?? $streamSettings['grpcSettings']['serviceName'] ?? null,
            'sni'      => $streamSettings['tlsSettings']['serverName'] ?? null,
            'host'     => $streamSettings['wsSettings']['headers']['Host'] ?? null,
        ]));

        return "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#" . urlencode($remark . '|' . $inboundRemark);
    }
}
