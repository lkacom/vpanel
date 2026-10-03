<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * بررسی (تایید / رد) درخواست‌های شارژ کیف پول که با کارت به کارت ثبت شده‌اند.
 */
class WalletChargeService
{
    /**
     * تایید فیش و شارژ کیف پول کاربر.
     *
     * @throws RuntimeException
     */
    public function approve(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $locked = $this->lockPendingCharge($order);

            if (blank($locked->card_payment_receipt)) {
                throw new RuntimeException('برای این درخواست هنوز فیشی ارسال نشده است.');
            }

            $user   = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $amount = (int) $locked->amount;

            $locked->update(['status' => 'paid']);
            $user->increment('balance', $amount);

            Transaction::create([
                'user_id'     => $user->id,
                'order_id'    => $locked->id,
                'amount'      => $amount,
                'type'        => Transaction::TYPE_DEPOSIT,
                'status'      => Transaction::STATUS_COMPLETED,
                'description' => 'شارژ کیف پول (تایید دستی فیش)',
            ]);

            $user->notifications()->create([
                'type'    => 'wallet_charged_approved',
                'title'   => 'کیف پول شما شارژ شد!',
                'message' => 'مبلغ ' . number_format($amount) . ' تومان با موفقیت به کیف پول شما اضافه شد.',
                'link'    => route('dashboard', ['tab' => 'order_history']),
            ]);
        });

        $order->refresh();
    }

    /**
     * رد فیش ارسال‌شده.
     *
     * @throws RuntimeException
     */
    public function reject(Order $order, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $reason): void {
            $locked = $this->lockPendingCharge($order);

            $locked->update(['status' => 'failed']);

            $message = 'درخواست شارژ کیف پول به مبلغ ' . number_format((int) $locked->amount) . ' تومان تایید نشد.';
            if (filled($reason)) {
                $message .= ' دلیل: ' . $reason;
            }

            $locked->user->notifications()->create([
                'type'    => 'wallet_charge_rejected',
                'title'   => 'درخواست شارژ رد شد',
                'message' => $message,
                'link'    => route('dashboard', ['tab' => 'order_history']),
            ]);
        });

        $order->refresh();
    }

    /**
     * سفارش را با قفل می‌خواند و مطمئن می‌شود هنوز یک درخواست شارژ در انتظار است
     * (جلوگیری از تایید دوباره / شارژ تکراری).
     */
    private function lockPendingCharge(Order $order): Order
    {
        $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->plan_id !== null) {
            throw new RuntimeException('این سفارش از نوع شارژ کیف پول نیست.');
        }

        if ($locked->status !== 'pending') {
            throw new RuntimeException('این درخواست قبلاً بررسی شده است.');
        }

        return $locked;
    }
}
