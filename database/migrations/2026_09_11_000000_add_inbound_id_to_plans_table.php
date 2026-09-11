<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            // Store the panel inbound ID rather than the local row ID because
            // inbound rows are replaced during every panel synchronization.
            $table->string('inbound_id')->nullable()->after('duration_days');
        });

        // Keep existing packages working when upgrading from the former
        // single global default inbound configuration.
        $defaultInboundId = DB::table('settings')
            ->where('key', 'xui_default_inbound_id')
            ->value('value');

        if ($defaultInboundId !== null && $defaultInboundId !== '') {
            DB::table('plans')
                ->whereNull('inbound_id')
                ->update(['inbound_id' => (string) $defaultInboundId]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('inbound_id');
        });
    }
};
