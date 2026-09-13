<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Transaction;
use App\Services\ZarinpalService;
use App\Traits\CompletesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ZarinpalController extends Controller
{
    use CompletesOrder;

    public function __construct(private readonly ZarinpalService $zarinpal) {}

    /**
     * شروع پرداخت — ساخت authority و redirect به درگاه
     */
    public function initiate(Request $request, Order $order): RedirectResponse
    {
        if (auth()->id() !== $order->user_id) {
            abort(403);
        }

        if ($order->status === 'paid') {
            return redirect()->route('dashboard')
                ->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }

        $amount = $order->plan_id
            ? (int) optional($order->plan)->price
            : (int) $order->amount;

        if ($amount <= 0) {
            return redirect()->route('order.show', $order->id)
                ->with('error', 'مبلغ سفارش نامعتبر است.');
        }

        $description = $order->plan_id
            ? 'پرداخت سفارش #' . $order->id . ' — ' . optional($order->plan)->name
            : 'شارژ کیف پول — سفارش #' . $order->id;

        try {
            $result = $this->zarinpal->request(
                amount:      $amount,
                description: $description,
                mobile:      auth()->user()->phone ?? null,
                email:       auth()->user()->email ?? null,
            );

            $order->update(['zarinpal_authority' => $result['authority']]);

            return redirect()->away($result['redirect_url']);

        } catch (RuntimeException $e) {
            Log::error('ZarinPal initiate failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return redirect()->route('order.show', $order->id)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Callback از زرین‌پال — تأیید، ساخت سرویس، و redirect
     */
    public function callback(Request $request): RedirectResponse
    {
        $authority = $request->query('Authority');
        $status    = $request->query('Status', 'NOK');

        if (! $authority || strtoupper($status) !== 'OK') {
            return redirect()->route('dashboard')
                ->with('error', 'پرداخت لغو شد یا با خطا مواجه شد.');
        }

        $order = Order::where('zarinpal_authority', $authority)->first();

        if (! $order) {
            Log::error('ZarinPal callback: order not found', ['authority' => $authority]);
            return redirect()->route('dashboard')->with('error', 'سفارش یافت نشد.');
        }

        if ($order->status === 'paid') {
            return redirect()->route('dashboard')
                ->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }

        $amount = $order->plan_id
            ? (int) optional($order->plan)->price
            : (int) $order->amount;

        try {
            // ── تأیید پرداخت با زرین‌پال ─────────────────────────────
            $result = $this->zarinpal->verify($authority, $amount);

            // ذخیره ref_id
            $order->update([
                'zarinpal_ref_id' => $result['ref_id'],
            ]);

            // ── تکمیل سفارش: ساخت سرویس یا شارژ کیف پول ────────────
            $this->completeOrder(
                order:          $order,
                paymentMethod:  'zarinpal',
                transactionNote: 'پرداخت آنلاین زرین‌پال — کد رهگیری: ' . $result['ref_id'],
            );

            return redirect()->route('dashboard')
                ->with('status', 'پرداخت موفق بود. کد رهگیری: ' . $result['ref_id']);

        } catch (RuntimeException $e) {
            Log::error('ZarinPal verify failed', ['authority' => $authority, 'error' => $e->getMessage()]);
            $order->update(['status' => 'failed']);
            return redirect()->route('order.show', $order->id)->with('error', $e->getMessage());

        } catch (\Exception $e) {
            // پرداخت تأیید شد ولی ساخت سرویس خطا داشت
            Log::error('ZarinPal service provision failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            $order->user->notifications()->create([
                'type'    => 'payment_failed',
                'title'   => 'خطا در فعال‌سازی سرویس',
                'message' => 'پرداخت موفق بود (کد: ' . ($order->zarinpal_ref_id ?? '') . ') ولی ساخت سرویس با خطا مواجه شد. تیم پشتیبانی بررسی خواهد کرد.',
                'link'    => route('dashboard'),
            ]);
            return redirect()->route('dashboard')
                ->with('error', 'پرداخت موفق بود ولی در فعال‌سازی سرویس خطایی رخ داد. پشتیبانی در جریان قرار گرفت.');
        }
    }
}
