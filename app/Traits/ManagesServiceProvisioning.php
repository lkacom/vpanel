<?php

namespace App\Traits;

use App\Models\Inbound;
use App\Models\Order;
use App\Services\MarzbanService;
use App\Services\XUIServiceFactory;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

trait ManagesServiceProvisioning
{
    /**
     * سرویس کاربر را در پنل مربوطه (Marzban، Sanaei یا TX-UI) ایجاد یا تمدید می‌کند.
     *
     * @param  string  $panelType  نوع پنل (marzban، sanaei یا txui)
     * @param  Collection  $settings  تنظیمات برنامه
     * @param  Order  $order  سفارش
     * @return array|false آرایه‌ای شامل ['config' => $config, 'expires_at' => $expires_at] در صورت موفقیت، یا false در صورت شکست
     */
    public function provisionService(string $panelType, $settings, Order $order)
    {
        $user = $order->user;
        $plan = $order->plan;
        if (! $plan) {
            $this->handleProvisioningError("سفارش {$order->id} فاقد پلن است.");

            return false;
        }

        $isRenewal = (bool) $order->renews_order_id;
        $originalOrder = null;

        if ($isRenewal) {
            $originalOrder = Order::find($order->renews_order_id);
            if (! $originalOrder) {
                $this->handleProvisioningError('سفارش اصلی جهت تمدید یافت نشد.');

                return false;
            }
        }

        // نام کاربری بر اساس سفارش اصلی (در صورت تمدید) یا سفارش فعلی (در صورت خرید جدید)
        $uniqueUsername = "user-{$user->id}-order-".($isRenewal ? $originalOrder->id : $order->id);

        // محاسبه تاریخ انقضای جدید
        $baseDate = now();
        if ($isRenewal) {
            $baseDate = (new \DateTime($originalOrder->expires_at));
            // اگر سرویس منقضی شده، تمدید از امروز حساب شود
            if ($baseDate < now()) {
                $baseDate = now();
            }
        }

        // $newExpiresAt به یک آبجکت DateTime تبدیل می‌شود
        $newExpiresAt = $baseDate->modify("+{$plan->duration_days} days");

        $finalConfig = null;
        $success = false;

        try {
            if ($panelType === 'marzban') {
                $marzbanService = new MarzbanService($settings->get('marzban_host'), $settings->get('marzban_sudo_username'), $settings->get('marzban_sudo_password'), $settings->get('marzban_node_hostname'));

                $userData = ['expire' => $newExpiresAt->getTimestamp(), 'data_limit' => $plan->volume_gb * 1024 * 1024 * 1024];

                $response = $isRenewal
                    ? $marzbanService->updateUser($uniqueUsername, $userData)
                    : $marzbanService->createUser(array_merge($userData, ['username' => $uniqueUsername]));

                if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                    $finalConfig = $marzbanService->generateSubscriptionLink($response);
                    $success = true;
                } else {
                    $error = $response['detail'] ?? 'پاسخ نامعتبر از مرزبان.';
                    $this->handleProvisioningError($error, ['response' => $response]);

                    return false;
                }

            } elseif (in_array($panelType, ['sanaei', 'txui', 'xui'], true)) {
                $inboundId = $plan->inbound_id;
                if (! $inboundId) {
                    $this->handleProvisioningError('برای این پکیج Inbound انتخاب نشده است.');

                    return false;
                }
                $xuiService = XUIServiceFactory::make(
                    $panelType,
                    (string) $settings->get('xui_host'),
                    (string) $settings->get('xui_user'),
                    (string) $settings->get('xui_pass')
                );
                if (! $xuiService->login()) {
                    $this->handleProvisioningError('خطا در لاگین به پنل X-UI.');

                    return false;
                }
                $inbound = Inbound::where('inbound_data->id', $inboundId)->first();
                if (! $inbound || ! $inbound->inbound_data) {
                    $this->handleProvisioningError('اطلاعات Inbound انتخاب‌شده برای این پکیج یافت نشد.');

                    return false;
                }

                $inboundData = is_array($inbound->inbound_data)
                    ? $inbound->inbound_data
                    : json_decode((string) $inbound->inbound_data, true);
                $clientData = ['email' => $uniqueUsername, 'total' => $plan->volume_gb * 1024 * 1024 * 1024, 'expiryTime' => $newExpiresAt->getTimestamp() * 1000];

                if ($isRenewal) {
                    // TODO: منطق تمدید کاربر در XUI (یافتن کاربر و آپدیت)
                    $this->handleProvisioningError('تمدید خودکار برای پنل XUI هنوز پیاده‌سازی نشده است.');

                    return false;
                }

                $response = $xuiService->addClient($inboundData['id'], $clientData);

                if ($response && isset($response['success']) && $response['success']) {
                    $linkType = $settings->get('xui_link_type', 'single');
                    if ($linkType === 'subscription') {
                        $subId = $response['generated_subId'] ?? null;
                        $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                        if ($subBaseUrl && $subId) {
                            $finalConfig = $subBaseUrl.'/sub/'.$subId;
                            $success = true;
                        } else {
                            $this->handleProvisioningError('آدرس پایه اشتراک XUI یا ID اشتراک ست نشده.');

                            return false;
                        }
                    } else { // single link
                        $uuid = $response['generated_uuid'] ?? null;
                        if (! $uuid) {
                            $this->handleProvisioningError('UUID از پنل XUI دریافت نشد.');

                            return false;
                        }

                        $streamSettings = json_decode($inboundData['streamSettings'], true);
                        $parsedUrl = parse_url($settings->get('xui_host'));
                        $serverAddress = ! empty($inboundData['listen']) ? $inboundData['listen'] : $parsedUrl['host'];
                        $port = $inboundData['port'];
                        $remark = $inboundData['remark'];
                        $paramsArray = [
                            'type' => $streamSettings['network'] ?? null,
                            'security' => $streamSettings['security'] ?? null,
                            'path' => $streamSettings['wsSettings']['path'] ?? ($streamSettings['grpcSettings']['serviceName'] ?? null),
                            'sni' => $streamSettings['tlsSettings']['serverName'] ?? null,
                            'host' => $streamSettings['wsSettings']['headers']['Host'] ?? null,
                        ];
                        $params = http_build_query(array_filter($paramsArray));
                        $fullRemark = $uniqueUsername.'|'.$remark;
                        $finalConfig = "vless://{$uuid}@{$serverAddress}:{$port}?{$params}#".urlencode($fullRemark);
                        $success = true;
                    }
                } else {
                    $this->handleProvisioningError($response['msg'] ?? 'پاسخ نامعتبر از XUI', ['response' => $response]);

                    return false;
                }
            }

            if ($success) {
                return ['config' => $finalConfig, 'expires_at' => $newExpiresAt];
            } else {
                $this->handleProvisioningError('موفقیت‌آمیز نبود (Success=false) اما خطایی رخ نداد.');

                return false;
            }

        } catch (\Exception $e) {
            $this->handleProvisioningError('خطای سیستمی: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return false;
        }
    }

    /**
     * مدیریت خطاها در Trait
     */
    protected function handleProvisioningError(string $message, array $context = [])
    {
        Log::error($message, $context);
        Notification::make()->title('خطا در ساخت سرویس')->body($message)->danger()->send();
    }
}
