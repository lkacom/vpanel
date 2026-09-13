<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('zarinpal_authority')->nullable()->after('payment_method');
            $table->string('zarinpal_ref_id')->nullable()->after('zarinpal_authority');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['zarinpal_authority', 'zarinpal_ref_id']);
        });
    }
};
