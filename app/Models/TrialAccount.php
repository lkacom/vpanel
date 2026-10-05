<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'panel_username',
        'config_details',
        'volume_gb',
        'duration_days',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'volume_gb'     => 'float',
            'duration_days' => 'float',
            'expires_at'    => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->expires_at?->isFuture() ?? false;
    }

    /**
     * کانفیگ را به آرایه‌ای از لینک‌ها تبدیل می‌کند
     * (سابسکریپشن / یک کانفیگ / چند کانفیگ ذخیره‌شده به صورت JSON).
     *
     * @return array<int, string>
     */
    public function getConfigItemsAttribute(): array
    {
        $config = trim((string) $this->config_details);

        if ($config === '') {
            return [];
        }

        $decoded = json_decode($config, true);

        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return [$config];
    }
}
