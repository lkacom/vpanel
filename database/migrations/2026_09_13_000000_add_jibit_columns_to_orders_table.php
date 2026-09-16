<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('jibit_authority')->nullable()->after('zarinpal_ref_id');
            $table->string('jibit_ref_id')->nullable()->after('jibit_authority');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['jibit_authority', 'jibit_ref_id']);
        });
    }
};
