<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Open time of the closed candle on which a bearish crossover was
     * detected and is still waiting for its one-candle SELL confirmation
     * (see AutomaticTradingCycle::process()). Null when nothing is pending.
     */
    public function up(): void
    {
        Schema::table('active_strategies', function (Blueprint $table) {
            $table->dateTime('pending_sell_candle_at')->nullable()->after('last_evaluated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('active_strategies', function (Blueprint $table) {
            $table->dropColumn('pending_sell_candle_at');
        });
    }
};
