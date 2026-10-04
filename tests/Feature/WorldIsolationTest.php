<?php

use App\Models\Game;
use App\Models\LocalSetting;
use App\Models\Player;
use App\Models\Team;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

function footballSave(string $name = 'Solo'): World
{
    $world = World::create(['name' => $name]);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    app(CurrentWorld::class)->id = $world->id;

    return $world;
}

test('new installs open saved games without login', function () {
    $this->get('/')->assertRedirect(route('worlds.index'));
    $this->get('/worlds')->assertOk()->assertSee('New saved game');
    $this->get('/login')->assertNotFound();
    $this->get('/register')->assertNotFound();
    $this->get('/practice')->assertOk()->assertSee('Scripted practice plays');
});

test('a local save has a league and season without creating a user account', function () {
    $this->post('/worlds', ['name' => 'Theo Save', 'league_name' => 'Solo League', 'year' => 2030])
        ->assertRedirect(route('home'));
    $this->assertDatabaseHas('leagues', ['name' => 'Solo League']);
    $this->assertDatabaseHas('seasons', ['year' => 2030, 'phase' => 'preseason']);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('world_user', 0);
    $this->get('/')->assertOk();
});

test('new saves can populate a demo league in one step', function () {
    $this->post('/worlds', ['name' => 'Demo', 'league_name' => 'Demo League', 'year' => 2032, 'demo' => 1])
        ->assertRedirect(route('home'));
    $this->assertDatabaseCount('teams', 4);
    $this->assertDatabaseCount('players', 212);
    $this->assertDatabaseCount('games', 6);
});

test('separate saves cannot mix bound teams players or games', function () {
    footballSave('First');
    $team = Team::create(['city' => 'Memphis', 'name' => 'Tigers']);
    $player = Player::create(['firstname' => 'John', 'lastname' => 'Smith', 'age' => 22, 'position' => 'QB']);
    $game = Game::create(['home_team_id' => $team->id]);
    footballSave('Second');
    $this->get('/teams/editor/'.$team->id.'/edit')->assertNotFound();
    $this->get('/players/'.$player->id)->assertNotFound();
    $this->get('/games/'.$game->id.'/boxscore')->assertNotFound();
    $this->get('/')->assertOk()->assertDontSee('Tigers');
});

test('any local save can be opened and the selection persists without a session', function () {
    $first = footballSave('First');
    footballSave('Second');
    $this->post('/worlds/'.$first->id.'/select')->assertRedirect(route('home'));
    $this->assertDatabaseHas('local_settings', ['id' => 1, 'current_world_id' => $first->id]);
    session()->forget('current_world_id');
    $this->get('/')->assertOk();
    $this->post('/worlds/9999/select')->assertNotFound();
    $this->post('/worlds/close')->assertRedirect(route('worlds.index'));
    $this->get('/')->assertRedirect(route('worlds.index'));
});

test('game setup rejects teams from a different save', function () {
    footballSave('Foreign');
    $foreign = Team::create(['city' => 'Foreign', 'name' => 'Team']);
    footballSave('Local');
    $local = Team::create(['city' => 'Local', 'name' => 'Team']);
    $this->post('/games/new', ['home_team_id' => $local->id, 'away_team_id' => $foreign->id])
        ->assertSessionHasErrors('away_team_id');
    $this->assertDatabaseCount('games', 0);
});

test('no save context returns no records and restoration stays scoped', function () {
    footballSave();
    $team = Team::create(['city' => 'One', 'name' => 'Team']);
    app(CurrentWorld::class)->id = null;
    expect(Team::count())->toBe(0);
    expect((new Team)->newQueryForRestoration($team->id)->exists())->toBeFalse();
    expect(fn () => Team::create(['city' => 'No', 'name' => 'Save']))->toThrow(LogicException::class);
});

test('legacy adoption works without an account and remains repeatable', function () {
    $save = footballSave();
    DB::table('teams')->insert(['city' => 'Legacy', 'name' => 'Team']);
    expect(Team::count())->toBe(0);
    $this->artisan('world:adopt-legacy', ['world' => $save->id])->assertSuccessful();
    $this->artisan('world:adopt-legacy', ['world' => $save->id])->assertSuccessful();
    expect(Team::count())->toBe(1);
});

test('switching saves prevents restoring saving or deleting an old game', function () {
    footballSave('First');
    $game = Game::create([]);
    footballSave('Second');
    expect((new Game)->newQueryForRestoration($game->id)->exists())->toBeFalse();
    expect(fn () => $game->update(['home_score' => 7]))->toThrow(LogicException::class);
    expect(fn () => $game->delete())->toThrow(LogicException::class);
});

test('games can be created with teams from the open save', function () {
    $save = footballSave();
    $home = Team::create(['city' => 'Memphis', 'name' => 'Tigers']);
    $away = Team::create(['city' => 'Jackson', 'name' => 'Bears']);
    $this->post('/games/new', ['home_team_id' => $home->id, 'away_team_id' => $away->id])->assertRedirect();
    $this->assertDatabaseHas('games', ['world_id' => $save->id, 'home_team_id' => $home->id, 'phase' => 'KICKOFF']);
});

test('child records cannot reference players or games from a different save', function () {
    footballSave();
    $game = Game::create([]);
    $player = Player::create(['firstname' => 'John', 'lastname' => 'Smith', 'age' => 22, 'position' => 'QB']);
    footballSave('Other');
    expect(fn () => App\Models\PlayerSeasonStat::create(['player_id' => $player->id, 'season_year' => 2030]))
        ->toThrow(LogicException::class);
    expect(fn () => App\Models\Play::create(['game_id' => $game->id]))->toThrow(LogicException::class);
});

test('livewire updates reject a snapshot from a previously selected save', function () {
    footballSave();
    $home = Team::create(['city' => 'Memphis', 'name' => 'Tigers']);
    $away = Team::create(['city' => 'Jackson', 'name' => 'Bears']);
    $game = Game::create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'home_q' => [0, 0, 0, 0, 0], 'away_q' => [0, 0, 0, 0, 0]]);
    $component = Livewire\Livewire::test(App\Livewire\GameCompanion::class, ['gameId' => $game->id]);
    footballSave('Other');
    expect(fn () => $component->call('setDownAndDistance'))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('local migration rolls back and reapplies without losing existing saves', function () {
    $save = footballSave();
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();
    expect(Illuminate\Support\Facades\Schema::hasTable('local_settings'))->toBeFalse();
    $this->assertDatabaseHas('worlds', ['id' => $save->id]);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(Illuminate\Support\Facades\Schema::hasTable('local_settings'))->toBeTrue();
});

test('multiple demo saves can reuse team abbreviations and external player identifiers', function () {
    foreach (['First Demo', 'Second Demo'] as $name) {
        $this->post('/worlds', ['name' => $name, 'league_name' => 'League', 'year' => 2026, 'demo' => 1])
            ->assertRedirect(route('home'));
    }
    $this->assertDatabaseCount('teams', 8);
    $this->assertDatabaseCount('players', 424);
    $this->assertDatabaseCount('games', 12);
    foreach (World::all() as $save) {
        app(CurrentWorld::class)->id = $save->id;
        Player::create(['firstname' => 'John', 'lastname' => 'Smith', 'age' => 22, 'position' => 'QB', 'sleeper_id' => 'same-reference']);
    }
    expect(DB::table('players')->where('sleeper_id', 'same-reference')->count())->toBe(2);
});
