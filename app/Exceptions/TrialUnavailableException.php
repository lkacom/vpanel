<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * خطایی که پیامش مستقیماً به کاربر نمایش داده می‌شود (اکانت تست غیرفعال / سقف دریافت پر شده / خطای ساخت).
 */
class TrialUnavailableException extends RuntimeException
{
}
