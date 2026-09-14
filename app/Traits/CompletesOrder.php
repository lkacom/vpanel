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
use Illuminate\Support\Facades\Log;

/**
 * منطق مشترک تکمیل سفارش پس از پرداخت موفق
 * هم ZarinpalController و هم OrderController از این trait استفاده می‌کنند
 */
trait CompletesOrder
{
    /**
     * تکمیل سفارش پس از پرداخت موفق:
     * - اگر plan دارد: سرویس VPN می‌سازد
     * - اگر plan ندارد: کیف پول شارژ می‌کند
     *
     * @throws \Exception
     */
    protected function completeOrder(Order $order, string $paymentMethod, string $transactionNote = ''): void
    {
        $user   = $order->user;
        $amount = $order->plan_id
            ? (int) optional($order->plan)->price
            : (int) $order->amount;

        if ($order->plan_id) {
            // ── خرید / تمدید سرویس VPN ──────────────────────────────
            $this->provisionVpnService($order, $user, $amount, $paymentMethod, $transactionNote);
        } else {
            // ── شارژ کیف پول ─────────────────────────────────────────
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

        $plan = $order->plan;

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
            'marzban' => $this->handleMarzban($settings, $plan, $isRenewal, $uniqueUsername, $timestamp),
            'sanaei', 'txui', 'xui' => $this->handleXUI(
                $panelType, $settings, $plan, $order, $isRenewal,
                $uniqueUsername, $timestamp, $newExpiresAt
            ),
            default => throw new \Exception('نوع پنل در تنظیمات مشخص نشده است.'),
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

    // ── Marzban ──────────────────────────────────────────────────

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

        if ($subEnabled && isset($response['subscription_url'])) {
            // لینک سابسکریپشن خالص
            $subUrl = ltrim($response['subscription_url'], '/');
            return [true, $nodeHostname . '/' . $subUrl];
        }

        // در غیر این صورت کانفیگ‌های مستقیم از API مرزبان بگیر
        // مرزبان API یک subscription_url دارد که شامل همه کانفیگ‌هاست
        // برای direct link باید /sub/username را fetch کرد
        // و خروجی را parse کنیم
        if (isset($response['subscription_url'])) {
            $subUrl  = $nodeHostname . '/' . ltrim($response['subscription_url'], '/');
            $configs = $this->fetchMarzbanDirectConfigs($subUrl);
            if (! empty($configs)) {
                return [true, implode("\n", $configs)];
            }
        }

        throw new \Exception('نمی‌توان کانفیگ سرویس را دریافت کرد.');
    }

    private function fetchMarzbanDirectConfigs(string $subUrl): array
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)->get($subUrl);
            if ($response->successful()) {
                // پاسخ base64 است یا متن مستقیم
                $body    = trim($response->body());
                $decoded = base64_decode($body, true);
                $text    = ($decoded && str_contains($decoded, '://')) ? $decoded : $body;
                // خطوطی که با vless:// ، vmess:// ، trojan:// ، ss:// شروع می‌شوند
                $lines   = array_filter(explode("\n", $text), fn($l) => preg_match('/^(vless|vmess|trojan|ss):/\//', trim($l)));
                return array_values($lines);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('fetchMarzbanDirectConfigs failed', ['url' => $subUrl, 'error' => $e->getMessage()]);
        }
        return [];
    }

    // ── X-UI ─────────────────────────────────────────────────────

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

        return $this->createXUIClient($xuiService, $settings, $panelType, $primaryData, $inboundIds, $clientData, $uniqueUsername);
    }

    private function createXUIClient($xuiService, $settings, string $panelType, array $primaryData, array $inboundIds, array $clientData, string $uniqueUsername): array
    {
        $numericIds = array_map('intval', $inboundIds);
        $response   = $xuiService->addClient($numericIds[0], array_merge($clientData, ['_all_inbound_ids' => $numericIds]));

        if (! ($response['success'] ?? false)) {
            throw new \Exception('خطا در ساخت اکانت در پنل.');
        }

        $inboundId = isset($primaryData['id']) && is_numeric($primaryData['id']) ? (int) $primaryData['id'] : $numericIds[0];
        $subInfo   = $xuiService->getSubscriptionUrl($inboundId);
        $subId     = $response['generated_subId'] ?? null;

        // همیشه subscription URL را ترجیح بده — حتی وقتی subId خالی نیست
        if ($subInfo) {
            if ($subId) {
                return [true, rtrim($subInfo['url'], '/') . '/' . $subId];
            }
            // پنل سابسکریپشن دارد ولی subId در response نیست — subId را از generated_uuid بساز
            // یا با clients دریافت کن
            $clients = $xuiService->getClients($inboundId);
            $client  = collect($clients)->firstWhere('email', $uniqueUsername);
            if ($client && ! empty($client['subId'])) {
                return [true, rtrim($subInfo['url'], '/') . '/' . $client['subId']];
            }
            if ($client && ! empty($client['id'])) {
                return [true, rtrim($subInfo['url'], '/') . '/' . $client['id']];
            }
        }

        // Fallback: VLESS link مستقیم فقط وقتی نه sub داریم
        $uuid   = $response['generated_uuid'];
        $config = $this->buildVlessLink($uuid, $primaryData, $settings->get('xui_host', ''), $uniqueUsername);
        return [true, $config];
    }

    private function renewXUIClient($xuiService, $settings, array $primaryData, array $inboundIds, array $clientData, Order $order, string $uniqueUsername): array
    {
        $originalOrder = Order::find($order->renews_order_id);
        if (! $originalOrder?->config_details) {
            throw new \Exception('اطلاعات سرویس اصلی جهت تمدید یافت نشد.');
        }

        $originalConfig = $originalOrder->config_details;
        $primaryId      = (int) $inboundIds[0];
        $isSubscription = str_contains($originalConfig, '/sub/');

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
                return [true, ($subInfo['url'] ?? '') . '/' . $addResp['generated_subId']];
            }

            $clientData['id'] = $clientId;
            $resp = $xuiService->updateClient($primaryId, $clientId, $clientData);
            if (! ($resp['success'] ?? false)) throw new \Exception('خطا در تمدید سرویس.');
            return [true, $originalConfig];
        }

        preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $originalConfig, $matches);
        $clientId = $matches[1] ?? null;
        if (! $clientId) throw new \Exception('اطلاعات سرویس قبلی نامعتبر است.');

        $clientData['id'] = $clientId;
        $clients = $xuiService->getClients($primaryId);
        $client  = collect($clients)->firstWhere('id', $clientId) ?? collect($clients)->firstWhere('email', $uniqueUsername);

        if (! $client) {
            $addResp = $xuiService->addClient($primaryId, $clientData);
            if (! ($addResp['success'] ?? false)) throw new \Exception('خطا در تمدید سرویس.');
            return [true, $this->buildVlessLink($clientId, $primaryData, $settings->get('xui_host', ''), $uniqueUsername)];
        }

        $resp = $xuiService->updateClient($primaryId, $clientId, $clientData);
        if (! ($resp['success'] ?? false)) throw new \Exception('خطا در تمدید سرویس.');
        return [true, $originalConfig];
    }

    private function findInbound(int $panelInboundId): Inbound
    {
        $inbound = Inbound::query()->where('inbound_data->id', $panelInboundId)->first();

        if (! $inbound) {
            $inbound = Inbound::all()->first(fn(Inbound $i) => is_array($i->inbound_data) && isset($i->inbound_data['id']) && (int) $i->inbound_data['id'] === $panelInboundId);
        }

        if (! $inbound) {
            throw new \Exception("Inbound {$panelInboundId} یافت نشد. لطفاً سرور را Sync کنید.");
        }

        return $inbound;
    }

    private function buildVlessLink(string $uuid, array $inboundData, string $xuiHost, string $remark): string
    {
        $streamSettings = $inboundData['streamSettings'] ?? [];
        if (is_string($streamSettings)) $streamSettings = json_decode($streamSettings, true) ?? [];

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
