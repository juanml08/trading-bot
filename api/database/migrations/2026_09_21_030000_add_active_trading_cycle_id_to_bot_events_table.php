<?php

use App\Models\ActiveTradingCycle;
use App\Models\BotEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 4: lets a {@see BotEvent} be attributed to the exact
     * {@see ActiveTradingCycle} it happened during, instead of a
     * fragile (account, asset, time-window) guess — needed because an asset
     * symbol can be reused by a later, unrelated cycle once an earlier one
     * closes/expires (see ActiveTradingCycle's docblock). Nullable: most
     * existing event types are not tied to a single cycle (e.g. Scanner or
     * search-level events), and rows created before this migration have no
     * cycle to backfill.
     */
    public function up(): void
    {
        Schema::table('bot_events', function (Blueprint $table) {
            $table->foreignId('active_trading_cycle_id')->nullable()->after('account_id')->constrained('active_trading_cycles')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_trading_cycle_id');
        });
    }
};
