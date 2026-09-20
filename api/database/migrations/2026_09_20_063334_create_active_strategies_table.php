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
        Schema::create('active_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('trading_accounts')->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained('strategies');
            $table->string('symbol', 30);
            $table->string('timeframe', 10);
            $table->decimal('capital', 18, 8);
            $table->string('mode', 20)->default('trial');
            $table->enum('status', ['applied', 'running', 'stopped'])->index('idx_active_strategies_status');
            $table->dateTime('started_at');
            $table->dateTime('stopped_at')->nullable();
            $table->dateTime('last_evaluated_at')->nullable();
            $table->timestamps();

            $table->index('account_id', 'idx_active_strategies_account');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_strategies');
    }
};
