<?php

use App\Strategy\Strategy;
use App\Strategy\StrategyCatalog;
use App\Strategy\StrategyFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `strategies` did not previously need to identify which PHP class
 * implements a strategy, or with which parameters — "Buscar estrategia"
 * only ever built {@see Strategy} instances in memory
 * (see {@see StrategyCatalog}). "Aplicar" needs to persist
 * *which* strategy was applied and later reconstruct it (see
 * {@see StrategyFactory}), so these columns are added here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->string('class')->nullable()->after('name');
            $table->json('parameters')->nullable()->after('class');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn(['class', 'parameters']);
        });
    }
};
