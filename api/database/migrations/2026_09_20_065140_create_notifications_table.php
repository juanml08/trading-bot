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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_id')->nullable()->constrained('trades')->nullOnDelete();
            $table->string('type', 50);
            $table->enum('channel', ['telegram', 'email', 'system']);
            $table->text('message');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending')->index('idx_notifications_status');
            $table->dateTime('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
