<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('player_season_stats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season_year');

            $table->string('espn_id', 30)->nullable()->index();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->unsignedSmallInteger('games')->default(0);
            $table->unsignedSmallInteger('games_started')->default(0);

            // Passing
            $table->unsignedSmallInteger('pass_completions')->default(0);
            $table->unsignedSmallInteger('pass_attempts')->default(0);
            $table->unsignedInteger('pass_yards')->default(0);
            $table->unsignedSmallInteger('pass_tds')->default(0);
            $table->unsignedSmallInteger('interceptions_thrown')->default(0);
            $table->unsignedSmallInteger('sacks_taken')->default(0);
            $table->unsignedInteger('sack_yards_lost')->default(0);

            // Rushing
            $table->unsignedSmallInteger('rush_attempts')->default(0);
            $table->unsignedInteger('rush_yards')->default(0);
            $table->unsignedSmallInteger('rush_tds')->default(0);

            // Receiving
            $table->unsignedSmallInteger('receptions')->default(0);
            $table->unsignedInteger('receiving_yards')->default(0);
            $table->unsignedSmallInteger('receiving_tds')->default(0);
            $table->unsignedSmallInteger('targets')->default(0);

            // Defense
            $table->unsignedSmallInteger('tackles_total')->default(0);
            $table->unsignedSmallInteger('tackles_solo')->default(0);
            $table->unsignedSmallInteger('tackles_assist')->default(0);
            $table->decimal('sacks', 6, 1)->default(0);
            $table->decimal('tfl', 6, 1)->default(0);
            $table->unsignedSmallInteger('qb_hits')->default(0);

            $table->unsignedSmallInteger('def_interceptions')->default(0);
            $table->unsignedSmallInteger('passes_defended')->default(0);
            $table->unsignedSmallInteger('forced_fumbles')->default(0);
            $table->unsignedSmallInteger('fumble_recoveries')->default(0);
            $table->unsignedSmallInteger('def_tds')->default(0);

            // Special Teams
            $table->unsignedSmallInteger('fg_made')->default(0);
            $table->unsignedSmallInteger('fg_attempts')->default(0);
            $table->unsignedSmallInteger('xp_made')->default(0);
            $table->unsignedSmallInteger('xp_attempts')->default(0);

            $table->unsignedSmallInteger('punts')->default(0);
            $table->unsignedInteger('punt_yards')->default(0);
            $table->unsignedSmallInteger('punts_inside_20')->default(0);
            $table->unsignedSmallInteger('punt_touchbacks')->default(0);
            $table->unsignedSmallInteger('punt_blocked')->default(0);

            $table->unsignedSmallInteger('kick_returns')->default(0);
            $table->unsignedInteger('kick_return_yards')->default(0);
            $table->unsignedSmallInteger('kick_return_tds')->default(0);

            $table->unsignedSmallInteger('punt_returns')->default(0);
            $table->unsignedInteger('punt_return_yards')->default(0);
            $table->unsignedSmallInteger('punt_return_tds')->default(0);

            $table->unsignedSmallInteger('fumbles')->default(0);
            $table->unsignedSmallInteger('fumbles_lost')->default(0);

            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'season_year']);
            $table->index(['season_year', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_season_stats');
    }
};
