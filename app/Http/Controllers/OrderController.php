<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MarzbanService;
use App\Services\XUIServiceFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Notification;

class OrderController extends Controller
{
    /**
     * Create a new pending order for a specific plan.
     */
    public function store(Plan $plan)
    {
        $order = Auth::user()->orders()->create([
            'plan_id' => $plan->id,
            'status' => 'pending',
            'source' => 'web',
        ]);

        Auth::user()->notifications()->create([
            'type' => 'new_order_created',
            'title' => 'سفارش جدید شما ثبت شد!',
            'message' => "سفارش #{$order->id} برای پلن {$plan->name} با موفقیت ثبت شد و در انتظار پرداخت است.",
            'link' => route('order.show', $order->id),
        ]);

        return redirect()->route('order.show', $order->id);
    }

    /**
     * Show the payment method selection page for an order.
     */
    public function show(Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403, 'شما به این صفحه دسترسی ندارید.');
        }

        if ($order->status === 'paid') {
            return redirect()->route('dashboard')->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }

        return view('payment.show', ['order' => $order]);
    }

    /**
     * Show the bank card details and receipt upload form.
     */
    public function processCardPayment(Order $order)
    {
        $order->update(['payment_method' => 'card']);
        $settings = Setting::all()->pluck('value', 'key');

        return view('payment.card-receipt', [
            'order' => $order,
            'settings' => $settings,
        ]);
    }

    /**
     * Show the form to enter the wallet charge amount.
     */
    public function showChargeForm()
    {
        return view('wallet.charge');
    }

    /**
     * Create a new pending order for charging the wallet.
     */
    public function createChargeOrder(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:10000']);
        $order = Auth::user()->orders()->create([
            'plan_id' => null,
            'amount' => $request->amount,
            'status' => 'pending',
            'source' => 'web',
        ]);

        Auth::user()->notifications()->create([
            'type' => 'wallet_charge_pending',
            'title' => 'درخواست شارژ کیف پول ثبت شد!',
            'message' => "سفارش شارژ کیف پول به مبلغ " . number_format($request->amount) . " تومان در انتظار پرداخت شماست.",
            'link' => route('order.show', $order->id),
        ]);

        return redirect()->route('order.show', $order->id);
    }

    /**
     * Create a new pending order to renew an existing service.
     */
    public function renew(Order $order)
    {
        if (Auth::id() !== $order->user_id || $order->status !== 'paid') {
            abort(403);
        }

        $newOrder = $order->replicate();
        $newOrder->created_at = now();
        $newOrder->status = 'pending';
        $newOrder->source = 'web';
        $newOrder->config_details = null;
        $newOrder->expires_at = null;
        $newOrder->renews_order_id = $order->id;
        $newOrder->save();

        Auth::user()->notifications()->create([
            'type' => 'renewal_order_created',
            'title' => 'درخواست تمدید سرویس ثبت شد!',
            'message' => "سفارش تمدید سرویس {$order->plan->name} با موفقیت ثبت شد و در انتظار پرداخت است.",
            'link' => route('order.show', $newOrder->id),
        ]);

        return redirect()->route('order.show', $newOrder->id)->with('status', 'سفارش تمدید شما ایجاد شد. لطفاً هزینه را پرداخت کنید.');
    }

    /**
     * Handle the submission of the payment receipt file.
     */
    public function submitCardReceipt(Request $request, Order $order)
    {
        $request->validate(['receipt' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048']);
        $path = $request->file('receipt')->store('receipts', 'public');
        $order->update(['card_payment_receipt' => $path]);

        Auth::user()->notifications()->create([
            'type' => 'card_receipt_submitted',
            'title' => 'رسید پرداخت شما ارسال شد!',
            'message' => "رسید پرداخت سفارش #{$order->id} با موفقیت دریافت شد و در انتظار تایید مدیر است.",
            'link' => route('order.show', $order->id),
        ]);
        return redirect()->route('dashboard')->with('status', 'رسید شما با موفقیت ارسال شد. پس از تایید توسط مدیر، سرویس شما فعال خواهد شد.');
    }

    /**
     * Process instant payment from the user's wallet balance.
     */
    public function processWalletPayment(Order $order)
    {
        if (auth()->id() !== $order->user_id) {
            abort(403);
        }
        if (!$order->plan) {
            return redirect()->back()->with('error', 'این عملیات برای شارژ کیف پول مجاز نیست.');
        }

        $user = auth()->user();
        $plan = $order->plan;
        $price = $plan->price;

        if ($user->balance < $price) {
            return redirect()->back()->with('error', 'موجودی کیف پول شما برای انجام این عملیات کافی نیست.');
        }

        try {
            DB::transaction(function () use ($order, $user, $plan, $price) {
                $user->decrement('balance', $price);

                $user->notifications()->create([
                    'type' => 'wallet_deducted',
                    'title' => 'کسر از کیف پول شما',
                    'message' => "مبلغ " . number_format($price) . " تومان برای سفارش #{$order->id} از کیف پول شما کسر شد.",
                    'link' => route('dashboard', ['tab' => 'order_history']),
                ]);

                $settings = Setting::all()->pluck('value', 'key');
                $success = false;
                $finalConfig = '';
                $panelType = $settings->get('panel_type');
                $isRenewal = (bool)$order->renews_order_id;

                // Username برای کلاینت X-UI/Marzban
                $uniqueUsername = $isRenewal
                    ? "user-{$user->id}-order-" . $order->renews_order_id
                    : "user-{$user->id}-order-" . $order->id;

                // محاسبه تاریخ انقضا
                if ($isRenewal && $order->renews_order_id) {
                    $originalOrder = Order::find($order->renews_order_id);
                    if ($originalOrder && $originalOrder->expires_at) {
                        $baseDate = new \DateTime($originalOrder->expires_at);
                    } else {
                        $baseDate = now();
                    }
                } else {
                    $baseDate = now();
                }
                $newExpiresAt = $baseDate->modify("+{$plan->duration_days} days");
                $timestamp = $newExpiresAt->getTimestamp();

                if ($panelType === 'marzban') {
                    // کد Marzban
                    $marzbanService = new MarzbanService(
                        $settings->get('marzban_host'),
                        $settings->get('marzban_sudo_username'),
                        $settings->get('marzban_sudo_password'),
                        $settings->get('marzban_node_hostname')
                    );

                    $userData = [
                        'expire' => $timestamp,
                        'data_limit' => $plan->volume_gb * 1073741824
                    ];

                    $response = $isRenewal
                        ? $marzbanService->updateUser($uniqueUsername, $userData)
                        : $marzbanService->createUser(array_merge($userData, ['username' => $uniqueUsername]));

                    if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                        $finalConfig = $marzbanService->generateSubscriptionLink($response);
                        $success = true;
                    }

                } elseif (in_array($panelType, ['sanaei', 'txui', 'xui'], true)) {
                    // پیاده‌سازی تمدید برای X-UI
                    $xuiService = XUIServiceFactory::make(
                        $panelType,
                        (string) $settings->get('xui_host'),
                        (string) $settings->get('xui_user'),
                        (string) $settings->get('xui_pass')
                    );

                    // دریافت اینباند پیش‌فرض
                    $defaultInboundId = $settings->get('xui_default_inbound_id');
                    if (empty($defaultInboundId)) {
                        throw new \Exception('تنظیمات اینباند پیش‌فرض برای X-UI یافت نشد.');
                    }

                    $numericInboundId = (int) $defaultInboundId;
                    $inbound = Inbound::whereJsonContains('inbound_data->id', $numericInboundId)->first();

                    if (!$inbound || !$inbound->inbound_data) {
                        throw new \Exception("اینباند با ID {$defaultInboundId} در دیتابیس یافت نشد.");
                    }

                    $inboundData = $inbound->inbound_data;

                    if (!$xuiService->login()) {
                        throw new \Exception('خطا در لاگین به پنل X-UI.');
                    }

                    $clientData = [
                        'email' => $uniqueUsername,
                        'total' => $plan->volume_gb * 1073741824,
                        'expiryTime' => $timestamp * 1000
                    ];

                    if ($isRenewal) {
                        // تمدید: پیدا کردن کلاینت قبلی و آپدیت آن
                        $originalOrder = Order::find($order->renews_order_id);
                        if (!$originalOrder || !$originalOrder->config_details) {
                            throw new \Exception('اطلاعات سرویس اصلی یافت نشد.');
                        }

                        // تعیین نوع لینک
                        $linkType = $settings->get('xui_link_type', 'single');
                        $originalConfig = $originalOrder->config_details;
                        $clientId = null;
                        $subId = null;

                        if ($linkType === 'subscription') {
                            // استخراج subId از کانفیگ قبلی
                            preg_match('/\/sub\/([a-zA-Z0-9]+)/', $originalConfig, $matches);
                            $subId = $matches[1] ?? null;

                            if (!$subId) {
                                throw new \Exception('شناسه اشتراک (subId) در کانفیگ قبلی یافت نشد.');
                            }

                            $clientData['subId'] = $subId;

                            // دریافت لیست کلاینت‌ها
                            $clients = $xuiService->getClients($inboundData['id']);

                            Log::info('X-UI clients fetched for renewal', [
                                'inbound_id' => $inboundData['id'],
                                'client_count' => count($clients),
                                'search_subId' => $subId,
                                'search_email' => $uniqueUsername
                            ]);

                            if (!empty($clients)) {
                                $client = collect($clients)->firstWhere('subId', $subId);

                                if (!$client) {
                                    $client = collect($clients)->firstWhere('email', $uniqueUsername);
                                }

                                $clientId = $client['id'] ?? null;
                            }

                            // اگر کلاینت پیدا نشد
                            if (!$clientId) {
                                Log::warning('Client not found for renewal, creating new client', [
                                    'inbound_id' => $inboundData['id'],
                                    'email' => $uniqueUsername,
                                    'subId' => $subId,
                                    'reason' => empty($clients) ? 'no_clients_in_inbound' : 'client_not_found'
                                ]);

                                // ایجاد کلاینت جدید
                                $addResponse = $xuiService->addClient($inboundData['id'], array_merge($clientData, ['subId' => $subId]));

                                if ($addResponse && isset($addResponse['success']) && $addResponse['success']) {
                                    $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                                    $newSubId = $addResponse['generated_subId'];
                                    if ($subBaseUrl && $newSubId) {
                                        $finalConfig = $subBaseUrl . '/sub/' . $newSubId;
                                        $success = true;
                                        session()->flash('warning', 'توجه: کلاینت قبلی در X-UI یافت نشد. یک کلاینت جدید ساخته شد.');
                                    } else {
                                        throw new \Exception('خطا در ساخت لینک سابسکریپشن جدید: آدرس پایه یا subId معتبر نیست.');
                                    }
                                } else {
                                    throw new \Exception('خطا در ساخت کلاینت جدید: ' . ($addResponse['msg'] ?? 'خطای نامشخص'));
                                }
                            } else {
                                // کلاینت موجود را آپدیت کن
                                $clientData['id'] = $clientId;
                                $response = $xuiService->updateClient($inboundData['id'], $clientId, $clientData);

                                if ($response && isset($response['success']) && $response['success']) {
                                    $finalConfig = $originalConfig;
                                    $success = true;
                                } else {
                                    $errorMsg = $response['msg'] ?? 'خطای نامشخص';
                                    Log::error('XUI updateClient failed', [
                                        'response' => $response,
                                        'inbound_id' => $inboundData['id'],
                                        'client_id' => $clientId
                                    ]);
                                    throw new \Exception('خطا در بروزرسانی کلاینت: ' . $errorMsg);
                                }
                            }

                        } else {
                            // single link
                            preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $originalConfig, $matches);
                            $clientId = $matches[1] ?? null;

                            if (!$clientId) {
                                throw new \Exception('UUID کلاینت در کانفیگ قبلی یافت نشد.');
                            }

                            $clientData['id'] = $clientId;
                            $clients = $xuiService->getClients($inboundData['id']);

                            $client = null;
                            if (!empty($clients)) {
                                $client = collect($clients)->firstWhere('id', $clientId);
                                if (!$client) {
                                    $client = collect($clients)->firstWhere('email', $uniqueUsername);
                                }
                            }

                            if (empty($clients) || !$client) {
                                Log::warning('Client not found for renewal (single link), creating new client', [
                                    'inbound_id' => $inboundData['id'],
                                    'email' => $uniqueUsername,
                                    'search_client_id' => $clientId
                                ]);

                                $addResponse = $xuiService->addClient($inboundData['id'], $clientData);

                                if ($addResponse && isset($addResponse['success']) && $addResponse['success']) {
                                    $uuid = $clientId;

                                    $streamSettings = $inboundData['streamSettings'] ?? [];
                                    if (is_string($streamSettings)) {
                                        $streamSettings = json_decode($streamSettings, true) ?? [];
                                    }

                                    $parsedUrl = parse_url($settings->get('xui_host'));
                                    $serverIpOrDomain = !empty($inboundData['listen']) ? $inboundData['listen'] : $parsedUrl['host'];
                                    $port = $inboundData['port'];
                                    $remark = $inboundData['remark'];

                                    $paramsArray = [
                                        'type' => $streamSettings['network'] ?? null,
                                        'security' => $streamSettings['security'] ?? null,
                                        'path' => $streamSettings['wsSettings']['path'] ?? ($streamSettings['grpcSettings']['serviceName'] ?? null),
                                        'sni' => $streamSettings['tlsSettings']['serverName'] ?? null,
                                        'host' => $streamSettings['wsSettings']['headers']['Host'] ?? null
                                    ];

                                    $params = http_build_query(array_filter($paramsArray));
                                    $fullRemark = $uniqueUsername . '|' . $remark;
                                    $finalConfig = "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#" . urlencode($fullRemark);
                                    $success = true;
                                    session()->flash('warning', 'توجه: کلاینت قبلی در X-UI یافت نشد. یک کلاینت جدید ساخته شد.');
                                } else {
                                    throw new \Exception('خطا در ساخت کلاینت جدید: ' . ($addResponse['msg'] ?? 'خطای نامشخص'));
                                }
                            } else {
                                $response = $xuiService->updateClient($inboundData['id'], $clientId, $clientData);

                                if ($response && isset($response['success']) && $response['success']) {
                                    $finalConfig = $originalConfig;
                                    $success = true;
                                } else {
                                    $errorMsg = $response['msg'] ?? 'خطای نامشخص';
                                    Log::error('XUI updateClient failed for single link', [
                                        'response' => $response,
                                        'inbound_id' => $inboundData['id'],
                                        'client_id' => $clientId
                                    ]);
                                    throw new \Exception('خطا در بروزرسانی کلاینت: ' . $errorMsg);
                                }
                            }
                        }
                    } else {
                        // سفارش جدید: اضافه کردن کلاینت جدید
                        $response = $xuiService->addClient($inboundData['id'], $clientData);

                        if ($response && isset($response['success']) && $response['success']) {
                            $linkType = $settings->get('xui_link_type', 'single');

                            if ($linkType === 'subscription') {
                                $subId = $response['generated_subId'];
                                $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                                if ($subBaseUrl) {
                                    $finalConfig = $subBaseUrl . '/sub/' . $subId;
                                    $success = true;
                                }
                            } else {
                                $uuid = $response['generated_uuid'];

                                $streamSettings = $inboundData['streamSettings'] ?? [];
                                if (is_string($streamSettings)) {
                                    $streamSettings = json_decode($streamSettings, true) ?? [];
                                }

                                $parsedUrl = parse_url($settings->get('xui_host'));
                                $serverIpOrDomain = !empty($inboundData['listen']) ? $inboundData['listen'] : $parsedUrl['host'];
                                $port = $inboundData['port'];
                                $remark = $inboundData['remark'];

                                $paramsArray = [
                                    'type' => $streamSettings['network'] ?? null,
                                    'security' => $streamSettings['security'] ?? null,
                                    'path' => $streamSettings['wsSettings']['path'] ?? ($streamSettings['grpcSettings']['serviceName'] ?? null),
                                    'sni' => $streamSettings['tlsSettings']['serverName'] ?? null,
                                    'host' => $streamSettings['wsSettings']['headers']['Host'] ?? null
                                ];

                                $params = http_build_query(array_filter($paramsArray));
                                $fullRemark = $uniqueUsername . '|' . $remark;
                                $finalConfig = "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#" . urlencode($fullRemark);
                                $success = true;
                            }
                        } else {
                            throw new \Exception('خطا در ساخت کاربر در پنل سنایی: ' . ($response['msg'] ?? 'پاسخ نامعتبر'));
                        }
                    }
                } // پایان شرط XUI - این آکولاد قبلا جا افتاده بود

                if (!$success) {
                    throw new \Exception('خطا در ارتباط با سرور برای فعال‌سازی سرویس.');
                }

                // آپدیت سفارشات
                if ($isRenewal) {
                    $originalOrder = Order::find($order->renews_order_id);
                    $originalOrder->update([
                        'config_details' => $finalConfig,
                        'expires_at' => $newExpiresAt->format('Y-m-d H:i:s')
                    ]);

                    $user->update(['show_renewal_notification' => true]);

                    $user->notifications()->create([
                        'type' => 'service_renewed',
                        'title' => 'سرویس شما تمدید شد!',
                        'message' => "سرویس {$originalOrder->plan->name} با موفقیت تمدید شد.",
                        'link' => route('dashboard', ['tab' => 'my_services']),
                    ]);
                } else {
                    $order->update([
                        'config_details' => $finalConfig,
                        'expires_at' => $newExpiresAt
                    ]);

                    $user->notifications()->create([
                        'type' => 'service_purchased',
                        'title' => 'سرویس شما فعال شد!',
                        'message' => "سرویس {$plan->name} با موفقیت خریداری و فعال شد.",
                        'link' => route('dashboard', ['tab' => 'my_services']),
                    ]);
                }

                // آپدیت وضعیت سفارش جدید
                $order->update([
                    'status' => 'paid',
                    'payment_method' => 'wallet'
                ]);

                // ثبت تراکنش
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'amount' => $price,
                    'type' => 'purchase',
                    'status' => 'completed',
                    'description' => ($isRenewal ? "تمدید سرویس" : "خرید سرویس") . " {$plan->name} از کیف پول"
                ]);

                OrderPaid::dispatch($order);
            });

        } catch (\Exception $e) {
            Log::error('Wallet Payment Failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            Auth::user()->notifications()->create([
                'type' => 'payment_failed',
                'title' => 'خطا در پرداخت با کیف پول!',
                'message' => "پرداخت سفارش شما با خطا مواجه شد: " . $e->getMessage(),
                'link' => route('dashboard', ['tab' => 'order_history']),
            ]);

            return redirect()->route('dashboard')->with('error', 'پرداخت با خطا مواجه شد: ' . $e->getMessage());
        }

        return redirect()->route('dashboard')->with('status', 'سرویس شما با موفقیت فعال شد.');
    }

    public function processCryptoPayment(Order $order)
    {
        $order->update(['payment_method' => 'crypto']);

        Auth::user()->notifications()->create([
            'type' => 'crypto_payment_info',
            'title' => 'پرداخت با ارز دیجیتال',
            'message' => "اطلاعات پرداخت با ارز دیجیتال برای سفارش #{$order->id} ثبت شد. لطفاً به زودی اقدام به پرداخت کنید.",
            'link' => route('order.show', $order->id),
        ]);

        return redirect()->back()->with('status', '💡 پرداخت با ارز دیجیتال به زودی فعال می‌شود. لطفاً از روش کارت به کارت استفاده کنید.');
    }
}
