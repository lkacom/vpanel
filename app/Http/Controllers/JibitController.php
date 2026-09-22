<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\JibitService;
use App\Traits\CompletesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class JibitController extends Controller
{
    use CompletesOrder;

    public function __construct(private readonly JibitService $jibit) {}

    /**
     * شروع پرداخت — ساخت purchase و redirect به درگاه جیبیت.
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
                amount:       $amount * 10, // تومان به ریال
                description:  $description,
                nationalCode: auth()->user()->national_code ?? null,
                mobile:       auth()->user()->phone ?? null,
                email:        auth()->user()->email ?? null,
            );

            $order->update(['jibit_authority' => $result['purchaseId']]);

            return redirect()->away($result['redirect_url']);

        } catch (RuntimeException $e) {
            Log::error('Jibit initiate failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return redirect()->route('order.show', $order->id)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Callback از جیبیت — تأیید، ساخت سرویس، و redirect.
     * دقیقاً مانند ZarinpalController::callback — بدون Cache یا پل میانی.
     */
    public function callback(Request $request): RedirectResponse
    {
        $purchaseId = $request->input('purchaseId') ?? $request->query('purchaseId');
        $status     = $request->input('status') ?? $request->query('status', 'FAILED');

        Log::info('Jibit callback received', [
            'purchaseId' => $purchaseId,
            'status'     => $status,
            'all'        => $request->all(),
        ]);

        if (! $purchaseId || strtoupper($status) !== 'SUCCESSFUL') {
            return redirect()->route('dashboard')
                ->with('error', 'پرداخت لغو شد یا با خطا مواجه شد.');
        }

        $order = Order::where('jibit_authority', $purchaseId)->first();

        if (! $order) {
            Log::error('Jibit callback: order not found', ['purchaseId' => $purchaseId]);
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
            $result = $this->jibit->verify($purchaseId, $amount * 10);

            $order->update(['jibit_ref_id' => $result['ref_id']]);

            $this->completeOrder(
                order:           $order,
                paymentMethod:   'jibit',
                transactionNote: 'پرداخت آنلاین جیبیت — کد رهگیری: ' . $result['ref_id'],
            );

            return redirect()->route('dashboard')
                ->with('status', 'پرداخت موفق بود. کد رهگیری: ' . $result['ref_id']);

        } catch (RuntimeException $e) {
            Log::error('Jibit verify failed', ['purchaseId' => $purchaseId, 'error' => $e->getMessage()]);
            $order->update(['status' => 'failed']);
            return redirect()->route('dashboard')->with('error', $e->getMessage());

        } catch (\Exception $e) {
            Log::error('Jibit service provision failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            $order->user->notifications()->create([
                'type'    => 'payment_failed',
                'title'   => 'خطا در فعال‌سازی سرویس',
                'message' => 'پرداخت موفق بود (کد: ' . ($order->jibit_ref_id ?? '') . ') ولی ساخت سرویس با خطا مواجه شد. تیم پشتیبانی بررسی خواهد کرد.',
                'link'    => route('dashboard'),
            ]);
            return redirect()->route('dashboard')
                ->with('error', 'پرداخت موفق بود ولی در فعال‌سازی سرویس خطایی رخ داد. پشتیبانی در جریان قرار گرفت.');
        }
    }
}
