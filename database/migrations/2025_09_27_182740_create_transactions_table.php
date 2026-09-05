<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('amount', 15, 0); // برای تومان بهتر است بدون اعشار باشد
            $table->enum('type', ['deposit', 'purchase', 'refund', 'withdrawal'])->comment('واریز، خرید، بازگشت وجه، برداشت');
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending')->comment('در انتظار، موفق، ناموفق');
            $table->string('description');
            $table->json('metadata')->nullable()->comment('اطلاعات اضافی مثل کد پیگیری');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
