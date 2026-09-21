<?php

use App\Actions\Strategy\RunAutomaticSearchAction;
use App\Models\AutomaticSearchCycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per asset reviewed within an
     * {@see AutomaticSearchCycle} — mirrors the outcome
     * {@see RunAutomaticSearchAction::reviewFor()}
     * already computes (`no_opportunity`, `candidate_found`, `discarded`).
     */
    public function up(): void
    {
        Schema::create('automatic_search_cycle_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automatic_search_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 30);
            $table->string('status', 20);
            $table->dateTime('evaluated_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automatic_search_cycle_assets');
    }
};
