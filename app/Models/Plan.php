<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'features',
        'is_popular',
        'is_active',
        'volume_gb',
        'duration_days',
        'inbound_id',   // قدیمی — برای backward compatibility نگه داشته می‌شود
        'inbound_ids',  // جدید — آرایه JSON از panel inbound IDs
    ];

    protected $casts = [
        'features'    => 'array',
        'is_popular'  => 'boolean',
        'is_active'   => 'boolean',
        'inbound_id'  => 'string',
        'inbound_ids' => 'array',   // JSON array of string IDs
    ];

    /**
     * لیست inbound ID های انتخاب‌شده برای این پکیج.
     * از inbound_ids استفاده می‌کند، اگر خالی بود از inbound_id قدیمی fallback می‌کند.
     *
     * @return array<int, string>
     */
    public function getEffectiveInboundIdsAttribute(): array
    {
        $ids = $this->inbound_ids;

        if (! empty($ids) && is_array($ids)) {
            return array_values(array_filter(array_map('strval', $ids)));
        }

        // fallback به مقدار قدیمی
        if (! empty($this->inbound_id)) {
            return [(string) $this->inbound_id];
        }

        return [];
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function getDurationLabelAttribute(): string
    {
        $days = $this->duration_days;

        return match (true) {
            $days == 30  => '۱ ماهه',
            $days == 60  => '۲ ماهه',
            $days == 90  => '۳ ماهه',
            $days == 180 => '۶ ماهه',
            $days == 365 => '۱ ساله',
            $days == 730 => '۲ ساله',
            default      => "{$days} روزه",
        };
    }

    public function getDurationGroupAttribute(): string
    {
        return match (true) {
            $this->duration_days <= 90  => 'ماهانه',
            $this->duration_days <= 365 => 'سه‌ماهه تا سالانه',
            $this->duration_days > 365  => 'سالانه+',
            default                     => 'سایر',
        };
    }

    public function getMonthlyPriceAttribute(): float|int
    {
        if ($this->duration_days == 0) {
            return $this->price;
        }

        return round($this->price / ($this->duration_days / 30), 0);
    }
}
