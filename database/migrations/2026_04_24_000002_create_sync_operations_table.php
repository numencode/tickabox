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
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type');   // todo
            $table->uuid('entity_uuid');
            $table->string('operation');     // created|updated|deleted

            $table->json('payload')->nullable();

            $table->string('status')->default('pending'); // pending|processing|done|failed|cancelled
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['entity_type', 'entity_uuid']);
            $table->index(['user_id', 'status', 'available_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
    }
};
