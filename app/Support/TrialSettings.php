<?php

namespace App\Support;

use App\Models\Inbound;
use App\Models\Setting;
use Illuminate\Support\Arr;

/**
 * تنظیمات اکانت تست رایگان (خوانده‌شده از جدول settings).
 */
final class TrialSettings
{
    /**
     * @param  array<int, string>  $inboundIds
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly float $volumeGb,
        public readonly float $durationDays,
        public readonly int $limitPerUser,
        public readonly array $inboundIds,
        public readonly string $panelType,
    ) {}

    public static function load(): self
    {
        $settings  = Setting::all()->pluck('value', 'key');
        $panelType = (string) $settings->get('panel_type', '');

        if ($panelType === 'sanaei') {
            // پنل ثنایی: کلاینت تست به همه‌ی Inbound های فعال وصل می‌شود (نیازی به انتخاب نیست)
            $inboundIds = Inbound::query()
                ->whereNotNull('inbound_data')
                ->get()
                ->filter(fn (Inbound $inbound): bool => $inbound->is_active && $inbound->panel_id !== null)
                ->map(fn (Inbound $inbound): string => (string) $inbound->panel_id)
                ->values()
                ->all();
        } else {
            // پنل علیرضا: فقط یک Inbound که مدیر انتخاب کرده است
            $inboundIds = collect(Arr::wrap($settings->get('trial_inbound_ids')))
                ->filter(fn ($id): bool => $id !== null && $id !== '')
                ->map(fn ($id): string => (string) $id)
                ->values()
                ->take(1)
                ->all();
        }

        // کلیدهای قدیمی (trial_volume_mb / trial_duration_hours) فقط به‌عنوان fallback خوانده می‌شوند
        $volume   = $settings->get('trial_volume_gb', $settings->get('trial_volume_mb', 1));
        $duration = $settings->get('trial_duration_days', $settings->get('trial_duration_hours', 1));
        $limit    = $settings->get('trial_limit_per_user', 1);

        return new self(
            enabled: filter_var($settings->get('trial_enabled', false), FILTER_VALIDATE_BOOLEAN),
            volumeGb: max(0.01, (float) $volume),
            durationDays: max(0.01, (float) $duration),
            limitPerUser: max(1, (int) $limit),
            inboundIds: $inboundIds,
            panelType: $panelType,
        );
    }

    /** آیا تنظیمات پنل برای ساخت اکانت کامل است؟ (مرزبان Inbound نمی‌خواهد) */
    public function isProvisionable(): bool
    {
        return match ($this->panelType) {
            'marzban'               => true,
            'sanaei', 'txui', 'xui' => ! empty($this->inboundIds),
            default                 => false,
        };
    }

    public function volumeLabel(): string
    {
        if ($this->volumeGb < 1) {
            return (int) round($this->volumeGb * 1024) . ' مگابایت';
        }

        return rtrim(rtrim(number_format($this->volumeGb, 2, '.', ''), '0'), '.') . ' گیگابایت';
    }

    public function durationLabel(): string
    {
        if ($this->durationDays < 1) {
            return max(1, (int) round($this->durationDays * 24)) . ' ساعت';
        }

        return rtrim(rtrim(number_format($this->durationDays, 2, '.', ''), '0'), '.') . ' روز';
    }
}
