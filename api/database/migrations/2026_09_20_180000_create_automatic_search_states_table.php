<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('automatic_search_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('trading_accounts')->cascadeOnDelete();
            $table->string('symbol', 30);
            $table->string('timeframe', 10);
            $table->decimal('capital', 18, 8);
            $table->string('mode', 20)->default('trial');
            $table->enum('status', ['running', 'stopped'])->default('stopped')->index('idx_automatic_search_states_status');
            $table->dateTime('last_searched_at')->nullable();
            $table->dateTime('next_search_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automatic_search_states');
    }
};
