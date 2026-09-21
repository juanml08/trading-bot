<?php

use App\Actions\Strategy\RunAutomaticSearchAction;
use App\Models\AutomaticSearchCycleAsset;
use App\Strategy\StrategyEvaluation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per strategy evaluated for an
     * {@see AutomaticSearchCycleAsset} — including the ones
     * discarded in Discovery or Validation, not only the one Selector picked
     * (see {@see RunAutomaticSearchAction::strategyDiagnostics()}).
     *
     * `train_*` columns hold the TRAIN {@see StrategyEvaluation}
     * metrics (always present, since Discovery always runs one). `validation_*`
     * columns hold the VALIDATION metrics and stay null for strategies that
     * never reached Validation. Keeping both stages separate — rather than a
     * single flat set of metric columns — avoids conflating TRAIN and
     * VALIDATION win rates/drawdowns/etc. under the same column, which would
     * make the SQL analysis this table exists for misleading.
     */
    public function up(): void
    {
        Schema::create('automatic_search_cycle_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automatic_search_cycle_asset_id')
                ->constrained(indexName: 'asc_strategies_asset_id_foreign')
                ->cascadeOnDelete();
            $table->string('strategy_name', 100);
            $table->string('status', 30);
            $table->boolean('discovery_passed');
            $table->boolean('validation_passed')->nullable();
            $table->json('failed_criteria');

            $table->unsignedInteger('train_total_trades');
            $table->unsignedInteger('train_winning_trades');
            $table->unsignedInteger('train_losing_trades');
            $table->decimal('train_win_rate', 28, 8);
            $table->decimal('train_profit_loss', 28, 8);
            $table->decimal('train_profit_loss_percentage', 28, 8);
            $table->decimal('train_max_drawdown_percentage', 28, 8);
            $table->string('train_profit_factor', 20);
            $table->decimal('train_total_costs', 28, 8);
            $table->decimal('train_gross_profit_loss', 28, 8);

            $table->unsignedInteger('validation_total_trades')->nullable();
            $table->unsignedInteger('validation_winning_trades')->nullable();
            $table->unsignedInteger('validation_losing_trades')->nullable();
            $table->decimal('validation_win_rate', 28, 8)->nullable();
            $table->decimal('validation_profit_loss', 28, 8)->nullable();
            $table->decimal('validation_profit_loss_percentage', 28, 8)->nullable();
            $table->decimal('validation_max_drawdown_percentage', 28, 8)->nullable();
            $table->string('validation_profit_factor', 20)->nullable();
            $table->decimal('validation_total_costs', 28, 8)->nullable();
            $table->decimal('validation_gross_profit_loss', 28, 8)->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automatic_search_cycle_strategies');
    }
};
