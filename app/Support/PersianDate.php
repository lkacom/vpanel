<?php

namespace App\Support;

use DateTimeInterface;
use Morilog\Jalali\Jalalian;

final class PersianDate
{
    public static function format(DateTimeInterface|string|null $value, string $format = 'Y/m/d H:i'): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $date = $value instanceof DateTimeInterface
            ? $value
            : new \DateTimeImmutable($value);

        return Jalalian::fromDateTime($date)->format($format);
    }
}
