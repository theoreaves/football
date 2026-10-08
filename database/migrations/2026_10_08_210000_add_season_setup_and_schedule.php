<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->json('settings')->nullable();
        });
        Schema::create('season_fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('week');
            $table->foreignId('home_team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('away_team_id')->constrained('teams')->restrictOnDelete();
            $table->string('status')->default('scheduled');
            $table->timestamps();
            $table->index(['world_id', 'season_id', 'week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_fixtures');
        Schema::table('seasons', fn (Blueprint $table) => $table->dropColumn(['name', 'settings']));
    }
};
