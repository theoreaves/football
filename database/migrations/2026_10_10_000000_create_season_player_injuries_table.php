<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_player_injuries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exhibition_id')->nullable()->constrained('exhibitions')->nullOnDelete();
            $table->unsignedInteger('injured_week');
            $table->unsignedInteger('return_week')->nullable();
            $table->string('type');
            $table->string('severity');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['season_id', 'exhibition_id', 'player_id'], 'season_injury_game_player_unique');
            $table->index(['season_id', 'team_id', 'status', 'return_week'], 'season_injury_availability_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_player_injuries');
    }
};
