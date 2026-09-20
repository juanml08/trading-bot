<?php

use App\Actions\Strategy\RunAutomaticSearchAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holds a snapshot of the most recent "Modo Automático" search cycle
     * only — every asset the Opportunity Scanner handed to the Strategy
     * Pipeline, its evaluation outcome, and a small summary. This is
     * deliberately overwritten on every cycle (see
     * {@see RunAutomaticSearchAction}), not a
     * permanent history table.
     */
    public function up(): void
    {
        Schema::table('automatic_search_states', function (Blueprint $table) {
            $table->json('last_cycle')->nullable()->after('next_search_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('automatic_search_states', function (Blueprint $table) {
            $table->dropColumn('last_cycle');
        });
    }
};
