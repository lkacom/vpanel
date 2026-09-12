<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تبدیل inbound_id (string) به inbound_ids (JSON array)
 * برای پشتیبانی از Attached Inbounds پنل ثنایی v3+
 *
 * مثال قدیمی: inbound_id = "3"
 * مثال جدید:  inbound_ids = ["3"]   (یا ["1","2","3"] برای multi)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->json('inbound_ids')->nullable()->after('inbound_id');
        });

        // مهاجرت داده‌های قدیمی: هر inbound_id را به آرایه JSON تبدیل کن
        DB::table('plans')
            ->whereNotNull('inbound_id')
            ->where('inbound_id', '!=', '')
            ->chunkById(100, function ($plans) {
                foreach ($plans as $plan) {
                    DB::table('plans')
                        ->where('id', $plan->id)
                        ->update([
                            'inbound_ids' => json_encode([$plan->inbound_id]),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('inbound_ids');
        });
    }
};
