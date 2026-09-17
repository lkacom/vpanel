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

        // مبلغ در دیتابیس به تومان ذخیره شده، تبدیل به ریال برای جیبیت
        $amountToman = $order->plan_id
            ? (int) optional($order->plan)->price
            : (int) $order->amount;

        if ($amountToman <= 0) {
            return redirect()->route('order.show', $order->id)
                ->with('error', 'مبلغ سفارش نامعتبر است.');
        }

        $amountRial = $amountToman * 10; // جیبیت با ریال کار می‌کند

        $description = $order->plan_id
            ? 'پرداخت سفارش #' . $order->id . ' — ' . optional($order->plan)->name
            : 'شارژ کیف پول — سفارش #' . $order->id;

        try {
            $result = $this->jibit->request(
                amount:      $amountRial,
                description: $description,
                nationalCode: auth()->user()->national_code ?? null,
                mobile:       auth()->user()->phone ?? null,
                email:       auth()->user()->email ?? null,
                callbackUrl: $request->getSchemeAndHttpHost() . route('payment.jibit.callback', absolute: false),
            );

            // purchaseId را در jibit_authority ذخیره می‌کنیم
            $order->update([
                'jibit_authority' => $result['purchaseId'],
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
     * Callback از جیبیت (POST) — تأیید، ذخیره ref_id و تکمیل سفارش.
     *
     * پارامترهای POST از جیبیت:
     * - purchaseId: شناسه خرید
     * - status: SUCCESSFUL | FAILED | UNKNOWN
     * - clientReferenceNumber: مرجع کلاینت
     * - amount, wage, currency, pspReferenceNumber, pspRRN, payerMaskedCardNumber, pspName, pspTerminalId, pspHashedCardNumber, failReason
     */
    public function callback(Request $request): RedirectResponse
    {
        // جیبیت callback را با POST و application/x-www-form-urlencoded ارسال می‌کند
        $purchaseId = $request->input('purchaseId');
        $status     = $request->input('status', 'FAILED');
        $clientRef  = $request->input('clientReferenceNumber');

        Log::info('Jibit callback received', [
            'purchaseId' => $purchaseId,
            'status'     => $status,
            'clientRef'  => $clientRef,
            'all'        => $request->all(),
        ]);

        if (! $purchaseId) {
            Log::error('Jibit callback: purchaseId missing', ['request' => $request->all()]);
            return view('payment.jibit-cancelled', [
                'order' => null,
                'amount' => 0,
                'error' => 'اطلاعات بازگشت از جیبیت ناقص است؛ پرداخت تکمیل نشد.',
            ]);
        }

        // یافتن سفارش بر اساس purchaseId (که در jibit_authority ذخیره شده)
        $order = Order::where('jibit_authority', $purchaseId)->first();

        if (! $order) {
            Log::error('Jibit callback: order not found', ['purchaseId' => $purchaseId]);
            return view('payment.jibit-cancelled', [
                'order' => null,
                'amount' => 0,
                'error' => 'سفارش مربوط به این پرداخت یافت نشد.',
            ]);
        }

        if ($order->status === 'paid') {
            return redirect()->route('dashboard')
                ->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }

        // callback لغو/ناموفق ممکن است بدون session کاربر و با GET برگردد.
        // مانند زرین‌پال، برای هر وضعیتی غیر از موفق مستقیماً رسید عمومی نشان بده.
        if (strtoupper($status) !== 'SUCCESSFUL') {
            $failReason = $request->input('failReason', 'UNKNOWN');
            Log::warning('Jibit payment failed', [
                'purchaseId' => $purchaseId,
                'failReason' => $failReason,
                'status'     => $status,
                'order_id'   => $order->id,
            ]);
            $order->update(['status' => 'failed']);
            return view('payment.jibit-cancelled', [
                'order'     => $order,
                'amount'    => $order->plan_id ? (int) optional($order->plan)->price : (int) $order->amount,
                'authority' => $purchaseId,
                'error'     => $failReason !== 'UNKNOWN'
                    ? "پرداخت لغو یا ناموفق شد: {$failReason}"
                    : 'پرداخت توسط کاربر لغو شد یا درگاه تراکنش را تکمیل نکرد.',
            ]);
        }

        // مبلغ به ریال برای verify
        $amountToman = $order->plan_id
            ? (int) optional($order->plan)->price
            : (int) $order->amount;
        $amountRial = $amountToman * 10;

        try {
            $result = $this->jibit->verify($purchaseId, $amountRial);

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
                'order'     => $order,
                'ref_id'    => $result['ref_id'],
                'amount'    => $amountToman,
                'authority' => $purchaseId,
                'code'      => $result['code'],
                'status'    => $result['status'],
            ]);

        } catch (RuntimeException $e) {
            Log::error('Jibit verify failed', [
                'purchaseId' => $purchaseId,
                'order_id'   => $order->id,
                'error'      => $e->getMessage(),
            ]);
            $order->update(['status' => 'failed']);
            return view('payment.jibit-receipt', [
                'order'     => $order,
                'amount'    => $amountToman,
                'authority' => $purchaseId,
                'error'     => $e->getMessage(),
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
                'order'     => $order,
                'amount'    => $amountToman,
                'authority' => $purchaseId,
                'error'     => 'پرداخت موفق بود ولی در فعال‌سازی سرویس خطایی رخ داد. پشتیبانی در جریان قرار گرفت.',
            ]);
        }
    }
}
