<?php

use App\Models\AutomaticSearchState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per completed "Modo Automático" search cycle — unlike
     * {@see AutomaticSearchState::$last_cycle} (a snapshot of the
     * most recent cycle only, overwritten every time), this is an
     * append-only history kept for later SQL analysis.
     */
    public function up(): void
    {
        Schema::create('automatic_search_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('trading_accounts')->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('assets_reviewed')->default(0);
            $table->unsignedInteger('candidates_found')->default(0);
            $table->timestamp('created_at')->useCurrent()->index('idx_automatic_search_cycles_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automatic_search_cycles');
    }
};
