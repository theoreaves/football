<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NUMERIC = ['players' => ['pass_evade', 'pass_accuracy', 'pass_deep', 'pass_control', 'rush', 'rush_power', 'receive', 'receive_deep', 'fumble', 'speed', 'tackle', 'sack', 'cover', 'interception', 'strip', 'kick30', 'kick39', 'kick49', 'kick50', 'punt_distance', 'punt_pooch_yard', 'punt_pooch', 'punt_block', 'return_yards', 'return_speed', 'return_fumble'], 'teams' => ['playcalling_behind', 'playcalling_tied', 'playcalling_ahead', 'ol_rush', 'ol_power', 'ol_pass', 'ol_protect'], 'team_players' => ['catch_from', 'catch_to', 'catch_plus_from', 'catch_plus_to', 'rush_from', 'rush_to', 'sack_from', 'sack_to', 'interception_from', 'interception_to', 'tackle_from', 'tackle_to', 'kick_from', 'kick_to', 'punt_from', 'punt_to']];

    private const STRINGS = ['players' => ['sleeper_id', 'espn_id', 'college', 'high_school'], 'teams' => ['jersey_dark_primary', 'jersey_dark_outline', 'jersey_dark_font', 'jersey_white_primary', 'jersey_white_outline', 'jersey_white_font', 'jersey_image_dark', 'jersey_image_white', 'game_field_image'], 'team_players' => ['kick_return_depth_chart_position', 'punt_return_depth_chart_position']];

    private const TABLES = ['plays', 'offense_play_rolls', 'offense_plays', 'defense_play_rolls', 'defense_plays', 'player_season_stats', 'games'];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::TABLES as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['world_id', 'sleeper_id']);
            foreach (['espn_id', 'college', 'high_school'] as $column) {
                $table->dropIndex([$column]);
            }
        });
        foreach (self::NUMERIC as $name => $columns) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(array_merge($columns, self::STRINGS[$name])));
        }
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('wear_white_at_home'));
    }

    public function down(): void
    {
        // Restore the previous schema for rollback; discarded legacy data is not recreated.
        foreach (self::NUMERIC as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns, $name) {
                foreach ($columns as $column) {
                    $table->integer($column)->default(0);
                } foreach (self::STRINGS[$name] as $column) {
                    $table->string($column)->nullable();
                }
            });
        }
        Schema::table('teams', fn (Blueprint $table) => $table->boolean('wear_white_at_home')->default(false));
        Schema::table('players', function (Blueprint $table) {
            $table->unique(['world_id', 'sleeper_id']);
            foreach (['espn_id', 'college', 'high_school'] as $column) {
                $table->index($column);
            }
        });
        (require database_path('migrations/2025_12_31_031944_create_games_table.php'))->up();
        (require database_path('migrations/2025_12_31_031954_create_plays_table.php'))->up();
        (require database_path('migrations/2025_12_31_033450_fix_nullable_defaults_on_games_table.php'))->up();
        (require database_path('migrations/2025_12_31_034746_add_scoring_phase_to_games_table.php'))->up();
        (require database_path('migrations/2025_12_31_034849_add_scoring_fields_to_plays_table.php'))->up();
        (require database_path('migrations/2025_12_31_044413_add_kickoff_phase_to_games_table.php'))->up();
        (require database_path('migrations/2025_12_31_051857_add_punt_team_to_games_table.php'))->up();
        (require database_path('migrations/2025_12_31_054740_add_turnover_phase_fields_to_games_table.php'))->up();
        (require database_path('migrations/2025_12_31_064436_add_series_start_to_games_table.php'))->up();
        (require database_path('migrations/2025_12_31_065251_add_series_abs_to_games_table.php'))->up();
        (require database_path('migrations/2025_12_31_143138_add_clock_and_quarter_scoring_to_games_table.php'))->up();
        (require database_path('migrations/2026_01_01_040021_add_team_ids_to_games_table.php'))->up();
        (require database_path('migrations/2026_01_01_062132_add_player_info_to_plays_table.php'))->up();
        (require database_path('migrations/2026_01_03_012120_create_offense_plays_tables.php'))->up();
        (require database_path('migrations/2026_01_03_031233_create_defensive_plays_table.php'))->up();
        (require database_path('migrations/2026_01_07_032458_add_die_gives_result_to_games_table.php'))->up();
        (require database_path('migrations/2026_01_07_034633_add_field_bg_url_to_games_table.php'))->up();
        (require database_path('migrations/2026_01_08_060917_add_home_team_white_to_games_table.php'))->up();
        (require database_path('migrations/2026_01_11_232300_create_player_season_stats_table.php'))->up();
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('world_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('week')->nullable();
        });
    }
};
