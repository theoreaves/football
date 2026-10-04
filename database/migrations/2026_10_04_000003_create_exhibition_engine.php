<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->json('simulation_ratings')->nullable());
        Schema::create('exhibitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('home_team_id')->constrained('teams');
            $table->foreignId('away_team_id')->constrained('teams');
            $table->json('state');
            $table->json('rosters');
            $table->json('history');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exhibitions');
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn('simulation_ratings'));
    }
};
