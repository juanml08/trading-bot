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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $table->string('exchange_order_id', 100)->nullable()->index('idx_orders_exchange_id');
            $table->enum('type', ['market', 'limit', 'stop_loss', 'take_profit']);
            $table->enum('side', ['buy', 'sell']);
            $table->decimal('price', 30, 12)->nullable();
            $table->decimal('quantity', 30, 12);
            $table->enum('status', ['pending', 'open', 'filled', 'cancelled', 'rejected'])->default('pending')->index('idx_orders_status');
            $table->timestamp('created_at')->useCurrent();
            $table->dateTime('executed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
