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
        Schema::create('bot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('trading_accounts')->nullOnDelete();
            $table->string('event_type', 50)->index('idx_bot_events_type');
            $table->string('asset', 30)->nullable();
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent()->index('idx_bot_events_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_events');
    }
};
