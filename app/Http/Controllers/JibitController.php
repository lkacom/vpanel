<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Transaction;
use App\Services\JibitService;
use App\Traits\CompletesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class JibitController extends Controller
{
    use CompletesOrder;

    public function __construct(private readonly JibitService $jibit)
    {
    }

    /**
     * شروع پرداخت — ساخت authority و redirect به درگاه جیبیت.
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
            $result = $this->jibit->request(
                amount:      $amount,
                description: $description,
                nationalCode: auth()->user()->national_code ?? null,
                mobile:      auth()->user()->phone ?? null,
                email:       auth()->user()->email ?? null,
            );

            $order->update([
                'jibit_authority' => $result['authority'],
            ]);

            return redirect()->away($result['redirect_url']);

        } catch (RuntimeException $e) {
            Log::error('Jibit initiate failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return redirect()->route('order.show', $order->id)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Callback از جیبیت — تأیید، ذخیره ref_id و تکمیل سفارش.
     */
    public function callback(Request $request): RedirectResponse
    {
        $authority = $request->query('Authority');
        $status    = $request->query('Status', 'NOK');

        if (! $authority || strtoupper($status) !== 'OK') {
            return redirect()->route('dashboard')
                ->with('error', 'پرداخت لغو شد یا با خطا مواجه شد.');
        }

        $order = Order::where('jibit_authority', $authority)->first();

        if (! $order) {
            Log::error('Jibit callback: order not found', ['authority' => $authority]);
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
            $result = $this::jibit->verify($authority, $amount);

            // ذخیره ref_id
            $order->update([
                'jibit_ref_id' => $result['ref_id'],
            ]);

            // ── تکمیل سفارش: ساخت سرویس یا شارژ کیف پول ──────────────────────
            $this->completeOrder(
                order:          $order,
                paymentMethod:  'jibit',
                transactionNote: 'پرداخت آنلاین جیبیت — کد رهگیری: ' . $result['ref_id'],
            );

            return view('payment.jibit-receipt', [
                'order' => $order,
                'ref_id' => $result['ref_id'],
                'amount' => $amount,
                'authority' => $result['authority'],
                'code'    => $result['code'],
            ]);

        } catch (RuntimeException $e) {
            Log::error('Jibit verify failed', [
                'authority' => $authority,
                'order_id'  => $order->id,
                'error'     => $e->getMessage(),
            ]);
            $order->update(['status' => 'failed']);
            return view('payment.jibit-receipt', [
                'order'      => $order,
                'amount'     => $amount,
                'authority'  => $authority,
                'error'      => $e->getMessage(),
            ]);

        } catch (\Exception $e) {
            Log::error('Jibit service provision failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            $order->user->notifications()->create([
                'type'    => 'payment_failed',
                'title'   => 'خطا در فعال‌سازی سرویس',
                'message' => 'پرداخت موفق بود (کد: ' . ($order->jibit_ref_id ?? '') . ') ولی ساخت سرویس با خطا مواجه شد. تیم پشتیبانی بررسی خواهد کرد.',
                'link'    => route('dashboard'),
            ]);
            $order->update(['status' => 'failed']);
            return view('payment.jibit-receipt', [
                'order'      => $order,
                'amount'     => $amount,
                'authority'  => $authority,
                'error'      => 'پرداخت موفق بود ولی در فعال‌سازی سرویس خطایی رخ داد. پشتیبانی در جریان قرار گرفت.',
            ]);
        }
    }
}
