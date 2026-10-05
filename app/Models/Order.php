<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /**
     * آدرس نمایش فیش کارت به کارت از طریق route احرازهویت‌شده (بدون نیاز به symlink پوشه storage).
     */
    public function getReceiptUrlAttribute(): ?string
    {
        return filled($this->card_payment_receipt)
            ? route('order.receipt', $this)
            : null;
    }

    /**
     * رکوردهای مالی (تراکنش‌ها) کاربر: پرداخت‌های موفق، ناموفق، و فیش کارت به کارتِ منتظر تایید.
     * سفارش‌هایی که هنوز روش پرداخت ندارند تراکنش محسوب نمی‌شوند.
     */
    public function scopeFinancialRecords(Builder $query): Builder
    {
        return $query->whereNotNull('payment_method')->where('payment_method', '!=', self::PAYMENT_TRIAL)->where(function (Builder $q): void {
            $q->whereIn('status', ['paid', 'failed'])
                ->orWhere(fn (Builder $p) => $p
                    ->where('status', 'pending')
                    ->where('payment_method', 'card')
                    ->whereNotNull('card_payment_receipt'));
        });
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return match ($this->payment_method) {
            'wallet'   => 'کیف پول',
            'card'     => 'کارت به کارت',
            'zarinpal' => 'درگاه زرین‌پال',
            'crypto'   => 'ارز دیجیتال',
            self::PAYMENT_TRIAL => 'اکانت تست رایگان',
            null, ''   => 'نامشخص',
            default    => (string) $this->payment_method,
        };
    }

    /** بابت چه منظوری پرداخت انجام شده: شارژ کیف پول / خرید سرویس / تمدید سرویس */
    public function getPurposeLabelAttribute(): string
    {
        if ($this->plan_id === null) {
            return 'شارژ کیف پول';
        }

        $planName = $this->plan?->name;
        $prefix   = $this->renews_order_id ? 'تمدید سرویس' : 'خرید سرویس';

        return $planName ? "{$prefix} {$planName}" : $prefix;
    }

    /**
     * شماره رهگیری: کد مرجع درگاه (زرین‌پال / ارز دیجیتال)؛
     * برای کیف پول و کارت به کارت شماره سفارش.
     */
    public function getTrackingCodeAttribute(): string
    {
        return (string) ($this->zarinpal_ref_id ?: $this->nowpayments_payment_id ?: ('#' . $this->id));
    }

    /**
     * اکانت‌های تست رایگان (با دریافت موفق، یک سفارش با این روش پرداخت برای نمایش در لیست سفارشات مدیر ثبت می‌شود).
     */
    public const PAYMENT_TRIAL = 'trial';

    public function scopeTrialAccounts(Builder $query): Builder
    {
        return $query->where('payment_method', self::PAYMENT_TRIAL);
    }

    /** بدون اکانت‌های تست (برای آمار فروش) */
    public function scopeExcludingTrial(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('payment_method')->orWhere('payment_method', '!=', self::PAYMENT_TRIAL));
    }

    public function trialAccount(): HasOne
    {
        return $this->hasOne(TrialAccount::class, 'panel_username', 'panel_username');
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
