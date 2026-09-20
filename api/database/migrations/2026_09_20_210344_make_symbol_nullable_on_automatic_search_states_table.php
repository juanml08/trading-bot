<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Iniciar automático" no longer takes a fixed symbol from the user: the
     * Opportunity Scanner now selects which symbols each search attempt
     * evaluates. The column stays to record whichever symbol the search
     * ends up selecting a strategy for, but it starts out unknown.
     */
    public function up(): void
    {
        Schema::table('automatic_search_states', function (Blueprint $table) {
            $table->string('symbol', 30)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('automatic_search_states', function (Blueprint $table) {
            $table->string('symbol', 30)->nullable(false)->change();
        });
    }
};
