<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Order extends Model
{

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'expires_at',
        'payment_method', 'card_payment_receipt', 'nowpayments_payment_id',
        'config_details',
        'amount',
        'source',
        'panel_username',
        'zarinpal_authority',
        'zarinpal_ref_id',
        'renews_order_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function store(Plan $plan)
    {

        return view('payment.choose', ['plan' => $plan]);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * سفارش اصلی (در صورتی که این سفارش یک تمدید باشد).
     */
    public function renewedOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renews_order_id');
    }

    /**
     * سفارشی که کانفیگ و تاریخ انقضای واقعی سرویس روی آن ذخیره شده است.
     * برای سفارش عادی خودش، برای سفارش تمدید همان سفارش اصلی.
     */
    public function getServiceOrderAttribute(): self
    {
        return $this->renews_order_id
            ? ($this->renewedOrder ?? $this)
            : $this;
    }

    /**
     * آیا کانفیگ خریداری‌شده فعال است؟
     * (سفارش پرداخت‌شده + کانفیگ موجود + تاریخ انقضا نگذشته)
     */
    public function getIsConfigActiveAttribute(): bool
    {
        if ($this->status !== 'paid') {
            return false;
        }

        $service = $this->service_order;

        if (blank($service->config_details)) {
            return false;
        }

        return blank($service->expires_at)
            || Carbon::parse($service->expires_at)->isFuture();
    }

    /**
     * فقط سفارش‌های خرید/تمدید پکیج (بدون درخواست‌های شارژ کیف پول).
     */
    public function scopePackageOrders(Builder $query): Builder
    {
        return $query->whereNotNull('plan_id');
    }

    /**
     * فقط درخواست‌های شارژ کیف پول.
     */
    public function scopeChargeRequests(Builder $query): Builder
    {
        return $query->whereNull('plan_id');
    }

    /**
     * سفارش‌های پرداخت‌شده‌ای که کانفیگ آن‌ها هنوز فعال است.
     */
    public function scopeWithActiveConfig(Builder $query): Builder
    {
        $alive = fn (Builder $q): Builder => $q
            ->whereNotNull('config_details')
            ->where('config_details', '!=', '')
            ->where(fn (Builder $e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        return $query->where('status', 'paid')->where(function (Builder $q) use ($alive): void {
            $q->where(fn (Builder $o) => $alive($o->whereNull('renews_order_id')))
                ->orWhereHas('renewedOrder', $alive);
        });
    }
}
