<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حذف کامل درگاه جیبیت: ستون‌های جیبیت در جدول orders + تنظیمات ذخیره‌شده در جدول settings.
 * (با hasColumn محافظت شده تا روی نصب‌های تازه که ستون‌ها ساخته نشده‌اند هم بدون خطا اجرا شود.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            foreach (['jibit_authority', 'jibit_ref_id'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    Schema::table('orders', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'like', 'jibit%')->delete();
        }

        try {
            Cache::forget('settings');
            Cache::forget('jibit_access_token');
            Cache::forget('jibit_refresh_token');
        } catch (\Throwable) {
            // کش در دسترس نیست؛ مشکلی نیست
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'jibit_authority')) {
                    $table->string('jibit_authority')->nullable();
                }
                if (! Schema::hasColumn('orders', 'jibit_ref_id')) {
                    $table->string('jibit_ref_id')->nullable();
                }
            });
        }
    }
};
