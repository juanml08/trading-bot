<?php

use App\Models\ActiveStrategy;
use App\Models\AutomaticSearchCycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The live trading cycle for one selected (account, asset, strategy)
     * opportunity — distinct from {@see AutomaticSearchCycle}, which is an
     * append-only history of search attempts. `active_strategy_id` is
     * nullable because a cycle is a domain concept in its own right; it may
     * exist before (or, for cycles created before this phase, without) an
     * {@see ActiveStrategy} row actually executing it.
     *
     * No DB-level uniqueness is enforced yet for "one non-terminal cycle per
     * account+asset" — see the ActiveTradingCycle model docblock for why.
     */
    public function up(): void
    {
        Schema::create('active_trading_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('trading_accounts')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('strategy_id')->constrained('strategies');
            $table->foreignId('active_strategy_id')->nullable()->constrained('active_strategies')->nullOnDelete();
            $table->string('state', 20)->default('HOLD');
            $table->dateTime('started_at');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'asset_id'], 'idx_active_trading_cycles_account_asset');
            $table->index('state', 'idx_active_trading_cycles_state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_trading_cycles');
    }
};
