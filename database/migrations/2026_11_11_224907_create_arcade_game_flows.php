<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arcade_game_flows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exhibition_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('human_side');
            $t->string('defense_mode');
            $t->unsignedInteger('revision')->default(0);
            $t->boolean('intro_seen')->default(true);
            $t->boolean('opening_enabled')->default(false);
            $t->timestamps();
        });
        Schema::create('arcade_flow_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exhibition_id')->constrained()->cascadeOnDelete();
            $t->uuid('command_id');
            $t->char('request_hash', 64);
            $t->json('result');
            $t->timestamps();
            $t->unique(['exhibition_id', 'command_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arcade_flow_receipts');
        Schema::dropIfExists('arcade_game_flows');
    }
};
