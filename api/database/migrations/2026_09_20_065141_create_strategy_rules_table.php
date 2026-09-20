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
        Schema::create('strategy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained('strategies')->cascadeOnDelete();
            $table->enum('rule_type', ['entry', 'exit', 'risk']);
            $table->string('indicator', 50);
            $table->string('operator', 10);
            $table->string('value', 100);
            $table->enum('logical_operator', ['AND', 'OR'])->nullable();
            $table->integer('rule_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategy_rules');
    }
};
