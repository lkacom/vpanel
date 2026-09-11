<?php
/**
 * فایل موقت برای clear کردن OPcache — بعد از استفاده حذف کنید
 */
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared successfully.\n";
} else {
    echo "OPcache is not enabled.\n";
}

// همچنین لاگ را truncate می‌کنیم
$logPath = __DIR__ . '/../storage/logs/laravel.log';
if (file_exists($logPath)) {
    file_put_contents($logPath, '');
    echo "Log file cleared.\n";
}

echo "Done. You can delete this file now.\n";
