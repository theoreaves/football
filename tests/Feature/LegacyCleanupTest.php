<?php

use App\Models\Exhibition;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\World;
use App\Services\Simulation\PlayerRatings;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->withoutVite();
    $world = World::create(['name' => 'Cleanup']);
    app(CurrentWorld::class)->id = $world->id;
    App\Models\LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
});
test('legacy endpoints commands tables and card fields are gone', function () {
    foreach (['/football', '/games', '/games/new', '/gameplay/test', '/pdf-library', '/game-cards/offense', '/dice'] as $path) {
        $this->get($path)->assertNotFound();
    }
    foreach (['games', 'plays', 'offense_plays', 'defense_plays', 'player_season_stats'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasColumn('players', 'pass_accuracy'))->toBeFalse()->and(Schema::hasColumn('team_players', 'catch_from'))->toBeFalse()->and(Schema::hasColumn('teams', 'ol_rush'))->toBeFalse();
    $commands = Artisan::all();
    expect(isset($commands['world:adopt-legacy']))->toBeFalse()->and(isset($commands['world:seed-demo']))->toBeTrue()->and(isset($commands['football:seed-middle-earth']))->toBeTrue();
});
test('team and player editors save current engine fields without legacy inputs', function () {
    $this->post(route('teams.editor.store'), ['city' => 'Shire', 'name' => 'Hobbits', 'abbr' => 'HOB', 'conference' => 'West', 'division' => 'North'])->assertRedirect();
    $team = Team::withoutGlobalScopes()->firstOrFail();
    $this->get(route('teams.editor.edit', $team))->assertOk()->assertDontSee('Playcalling')->assertDontSee('Import Team Card')->assertSee('Home uniform');
    $data = ['firstname' => 'Theo', 'lastname' => 'Test', 'age' => 22, 'position' => 'QB', 'depth_chart_position' => 'QB1', 'jersey_number' => 12, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70)];
    $this->post(route('teams.editor.teams.players.store', [$team, 'year' => 2026]), $data)->assertRedirect();
    $player = Player::withoutGlobalScopes()->firstOrFail();
    expect($player->simulation_ratings['throwing'])->toBe(70);
    $page = $this->get(route('teams.editor.teams.players.edit', [$team, $player, 'year' => 2026]));
    $page->assertOk()->assertSee('Engine ratings')->assertDontSee('Sleeper')->assertDontSee('Catch From');
    app(CurrentWorld::class)->id = App\Models\LocalSetting::find(1)->current_world_id;
    TeamPlayer::create(['team_id' => $team->id, 'player_id' => $player->id, 'team_year' => '2027', 'position' => 'QB', 'depth_chart_position' => 'QB2', 'jersey_number' => 99]);
    $data['jersey_number'] = 7;
    $data['ratings']['throwing'] = 90;
    $this->put(route('teams.editor.teams.players.update', [$team, $player, 'year' => 2026]), $data)->assertRedirect();
    app(CurrentWorld::class)->id = App\Models\LocalSetting::find(1)->current_world_id;
    expect($player->fresh()->simulation_ratings['throwing'])->toBe(90)->and(TeamPlayer::where('team_year', '2026')->first()->jersey_number)->toBe(7)->and(TeamPlayer::where('team_year', '2027')->first()->jersey_number)->toBe(99);
    $data['ratings']['throwing'] = 101;
    $this->put(route('teams.editor.teams.players.update', [$team, $player, 'year' => 2026]), $data)->assertSessionHasErrors('ratings.throwing');
});
test('cleanup upgrades old schemas while preserving current teams players and exhibitions', function () {
    $team = Team::create(['city' => 'One', 'name' => 'Team', 'uniform_home_facemask' => '#abcdef']);
    $player = Player::create(['firstname' => 'One', 'lastname' => 'Player', 'age' => 22, 'position' => 'QB', 'simulation_ratings' => array_fill_keys(PlayerRatings::FIELDS, 80)]);
    $game = Exhibition::create(['home_team_id' => $team->id, 'away_team_id' => $team->id, 'state' => ['home_score' => 7], 'rosters' => [], 'history' => []]);
    $migration = require database_path('migrations/2026_10_04_220000_remove_legacy_football.php');
    $migration->down();
    expect(Schema::hasTable('games'))->toBeTrue();
    DB::table('games')->insert(['home_team_id' => $team->id]);
    $migration->up();
    expect(Schema::hasTable('games'))->toBeFalse()->and($team->fresh()->uniform_home_facemask)->toBe('#abcdef')->and($player->fresh()->simulation_ratings['throwing'])->toBe(80)->and($game->fresh()->state['home_score'])->toBe(7);
});
