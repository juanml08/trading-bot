<?php

use App\Strategy\StrategyEvaluator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `train_profit_factor`/`validation_profit_factor` were created as
 * `string(20)`, sized for small ratios like `'1.50000000000000000'` or the
 * `'INF'` sentinel (see {@see StrategyEvaluator}, which computes this value
 * with `bcdiv(..., 18)` — always 18 decimal digits). That length silently
 * truncates any profit factor whose integer part reaches two digits (e.g.
 * `15.428057177357793581`, 21 characters), which MySQL then rejects outright
 * on insert (`Data too long for column`) instead of truncating.
 *
 * Profit factor stays a string column — unlike the other `decimal(28,8)`
 * metric columns — because `'INF'` is a valid, non-numeric value for it (no
 * losing trades yet at least one winning trade). Widening to 40 mirrors
 * those columns' 20-integer-digit headroom while preserving the 18-decimal
 * scale the domain actually produces, so the exact string
 * {@see StrategyEvaluator} returns is stored without rounding or truncation.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('automatic_search_cycle_strategies', function (Blueprint $table) {
            $table->string('train_profit_factor', 40)->change();
            $table->string('validation_profit_factor', 40)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('automatic_search_cycle_strategies', function (Blueprint $table) {
            $table->string('train_profit_factor', 20)->change();
            $table->string('validation_profit_factor', 20)->nullable()->change();
        });
    }
};
