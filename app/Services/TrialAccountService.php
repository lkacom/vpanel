<?php

namespace App\Services;

use App\Exceptions\TrialUnavailableException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\TrialAccount;
use App\Models\User;
use App\Support\TrialSettings;
use App\Traits\CompletesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * دریافت اکانت تست رایگان توسط کاربر.
 *
 * ساخت کلاینت در پنل (مرزبان / ثنایی / علیرضا) از همان helperهای مشترک سفارش‌ها
 * (CompletesOrder) استفاده می‌کند تا رفتار لینک‌ها (سابسکریپشن یا کانفیگ مستقیم)
 * دقیقاً مثل سرویس‌های خریداری‌شده باشد.
 */
class TrialAccountService
{
    use CompletesOrder;

    /**
     * وضعیت اکانت تست برای نمایش در داشبورد کاربر.
     *
     * @return array<string, mixed>
     */
    public function statusFor(User $user): array
    {
        $config    = TrialSettings::load();
        $taken     = $this->takenCount($user);
        $remaining = max(0, $config->limitPerUser - $taken);

        return [
            'enabled'        => $config->enabled,
            'available'      => $config->isProvisionable(),
            'can_claim'      => $remaining > 0,
            'taken'          => $taken,
            'limit'          => $config->limitPerUser,
            'remaining'      => $remaining,
            'volume_label'   => $config->volumeLabel(),
            'duration_label' => $config->durationLabel(),
        ];
    }

    /**
     * ساخت اکانت تست برای کاربر.
     *
     * @throws TrialUnavailableException پیام این خطا برای نمایش به کاربر مناسب است
     */
    public function claim(User $user): TrialAccount
    {
        $config = TrialSettings::load();

        if (! $config->enabled || ! $config->isProvisionable()) {
            throw new TrialUnavailableException('در حال حاضر امکان دریافت اکانت تست وجود ندارد.');
        }

        try {
            return DB::transaction(function () use ($user, $config): TrialAccount {
                // قفل ردیف کاربر: جلوی دریافت چندباره با کلیک یا درخواست همزمان را می‌گیرد
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                if ($this->takenCount($locked) >= $config->limitPerUser) {
                    throw new TrialUnavailableException('شما مجاز به دریافت اکانت تست نیستید. شما قبلاً اکانت تست رایگان را دریافت کرده‌اید.');
                }

                [$username, $finalConfig, $expiresAt] = $this->provision($locked, $config);

                $account = TrialAccount::create([
                    'user_id'        => $locked->id,
                    'panel_username' => $username,
                    'config_details' => $finalConfig,
                    'volume_gb'      => $config->volumeGb,
                    'duration_days'  => $config->durationDays,
                    'expires_at'     => $expiresAt,
                ]);

                // سفارش بدون مبلغ برای نمایش اکانت تست در لیست سفارشات مدیر (در آمار فروش لحاظ نمی‌شود)
                Order::create([
                    'user_id'        => $locked->id,
                    'plan_id'        => null,
                    'amount'         => 0,
                    'status'         => 'paid',
                    'source'         => 'trial',
                    'payment_method' => Order::PAYMENT_TRIAL,
                    'panel_username' => $username,
                    'config_details' => $finalConfig,
                    'expires_at'     => $expiresAt,
                ]);

                $locked->increment('trial_accounts_taken');

                $locked->notifications()->create([
                    'type'    => 'trial_account_created',
                    'title'   => 'اکانت تست شما فعال شد!',
                    'message' => "اکانت تست رایگان ({$config->volumeLabel()} - {$config->durationLabel()}) با موفقیت ساخته شد.",
                    'link'    => route('dashboard', ['tab' => 'my_services']),
                ]);

                return $account;
            });
        } catch (TrialUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // جزئیات خطای پنل برای کاربر نمایش داده نمی‌شود؛ فقط در لاگ ثبت می‌شود
            Log::error('Trial account provisioning failed: ' . $e->getMessage(), [
                'user_id' => $user->getKey(),
                'trace'   => $e->getTraceAsString(),
            ]);

            throw new TrialUnavailableException(
                'ساخت اکانت تست با خطا مواجه شد. لطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
                0,
                $e
            );
        }
    }

    /**
     * تعداد اکانت‌های تستی که کاربر تاکنون گرفته است.
     */
    private function takenCount(User $user): int
    {
        return max((int) $user->trial_accounts_taken, $user->trialAccounts()->count());
    }

    /**
     * کلاینت را در پنل می‌سازد.
     *
     * @return array{0: string, 1: string, 2: Carbon} [username, config, expires_at]
     */
    private function provision(User $user, TrialSettings $config): array
    {
        $settings  = Setting::all()->pluck('value', 'key');
        $username  = 'trial-' . $user->id . '-' . Str::lower(Str::random(6));
        $expiresAt = now()->addMinutes((int) round($config->durationDays * 1440));
        $timestamp = $expiresAt->getTimestamp();

        // پلن موقت (ذخیره نمی‌شود) — فقط برای استفاده از helperهای مشترک ساخت اکانت
        $plan = new Plan([
            'name'        => 'اکانت تست',
            'volume_gb'   => $config->volumeGb,
            'inbound_ids' => $config->inboundIds,
        ]);

        [$success, $finalConfig] = match ($config->panelType) {
            'marzban'               => $this->handleMarzban($settings, $plan, false, $username, $timestamp),
            'sanaei', 'txui', 'xui' => $this->handleXUI(
                $config->panelType, $settings, $plan, new Order(), false, $username, $timestamp, $expiresAt
            ),
            default                 => throw new \RuntimeException('نوع پنل در تنظیمات مشخص نشده است.'),
        };

        if (! $success || blank($finalConfig)) {
            throw new \RuntimeException('خطا در ساخت اکانت تست در پنل.');
        }

        return [$username, (string) $finalConfig, $expiresAt];
    }
}
