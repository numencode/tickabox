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
        Schema::create('todos', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Optional: if you want remote numeric ID too
            $table->unsignedBigInteger('remote_id')->nullable()->unique();

            $table->string('title');
            $table->boolean('is_completed')->default(false);

            // local sync state
            $table->string('sync_status')->default('pending'); // pending|synced|failed

            // separate from Laravel timestamps because we use this in sync decisions
            $table->timestamp('last_modified_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'sync_status']);
            $table->index(['user_id', 'last_modified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
