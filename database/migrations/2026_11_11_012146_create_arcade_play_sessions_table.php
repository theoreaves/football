<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arcade_play_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('exhibition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('active_slot')->nullable()->unique();
            $table->uuid('result_id')->unique();
            $table->string('status')->default('active');
            $table->string('snapshot_hash', 64);
            $table->string('accepted_hash', 64)->nullable();
            $table->json('binding');
            $table->json('snapshot');
            $table->json('setup');
            $table->json('receipt')->nullable();
            $table->json('recording')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arcade_play_sessions');
    }
};
