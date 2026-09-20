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
        Schema::create('risk_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique('uq_risk_settings_account')->constrained('trading_accounts')->cascadeOnDelete();
            $table->decimal('max_risk_per_trade', 8, 4)->default('1.0000');
            $table->decimal('max_daily_loss', 8, 4)->default('2.0000');
            $table->unsignedInteger('max_open_trades')->default(1);
            $table->decimal('stop_loss_percent', 8, 4)->default('1.0000');
            $table->decimal('take_profit_percent', 8, 4)->default('2.0000');
            $table->decimal('max_capital_per_trade', 18, 8)->default('20.00000000');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_settings');
    }
};
