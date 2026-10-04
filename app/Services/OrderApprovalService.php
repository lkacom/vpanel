<?php

namespace App\Services;

use App\Models\Order;
use App\Traits\CompletesOrder;
use RuntimeException;

/**
 * تایید دستی پرداخت کارت به کارت سفارش‌های پکیج توسط مدیر.
 *
 * ساخت/تمدید سرویس در پنل، ثبت تراکنش، اعلان کاربر و رویداد OrderPaid دقیقاً از همان
 * مسیر مشترک پرداخت‌های دیگر (زرین‌پال و ...) یعنی CompletesOrder انجام می‌شود.
 */
class OrderApprovalService
{
    use CompletesOrder;

    /**
     * @throws RuntimeException|\Throwable اگر سفارش قابل تایید نباشد یا ساخت سرویس در پنل ناموفق باشد
     */
    public function approveCardPayment(Order $order): void
    {
        // وضعیت را از دیتابیس دوباره بخوان تا تایید تکراری انجام نشود
        $fresh = Order::query()->with(['user', 'plan'])->findOrFail($order->getKey());

        if ($fresh->status !== 'pending') {
            throw new RuntimeException('این سفارش قبلاً بررسی شده است.');
        }

        if (! $fresh->plan_id || ! $fresh->plan) {
            throw new RuntimeException('پکیج این سفارش یافت نشد. درخواست‌های شارژ از منوی «مدیریت شارژ» بررسی می‌شوند.');
        }

        $this->completeOrder($fresh, 'card', 'پرداخت کارت به کارت (تایید دستی مدیر)');

        $order->refresh();
    }
}
