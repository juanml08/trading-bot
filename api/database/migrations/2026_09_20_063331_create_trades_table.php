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
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('trading_accounts');
            $table->foreignId('strategy_id')->constrained('strategies');
            $table->foreignId('asset_id')->constrained('assets');
            $table->decimal('entry_price', 30, 12)->nullable();
            $table->decimal('exit_price', 30, 12)->nullable();
            $table->decimal('quantity', 30, 12);
            $table->decimal('capital_used', 18, 8);
            $table->decimal('stop_loss', 30, 12)->nullable();
            $table->decimal('take_profit', 30, 12)->nullable();
            $table->decimal('profit_loss', 18, 8)->nullable();
            $table->decimal('profit_loss_percent', 10, 4)->nullable();
            $table->enum('status', ['pending', 'open', 'closed', 'cancelled'])->default('pending')->index('idx_trades_status');
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamp('created_at')->useCurrent()->index('idx_trades_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
