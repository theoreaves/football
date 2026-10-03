<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

function footballWorld(User $user, string $name = 'Solo'): World
{
    $world = World::create(['name' => $name, 'owner_user_id' => $user->id]);
    $world->users()->attach($user->id, ['role' => 'owner']);
    $user->forceFill(['current_world_id' => $world->id])->save();
    app(CurrentWorld::class)->id = $world->id;

    return $world;
}

test('football pages require authentication', function () {
    foreach (['/', '/teams', '/games/new', '/football/1', '/gameplay/test', '/pdf-library'] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

test('registration creates an account and a world has a league and first season', function () {
    $this->post('/register', ['name' => 'Theo', 'email' => 'theo@example.com',
        'password' => 'password123', 'password_confirmation' => 'password123'])
        ->assertRedirect(route('worlds.index'));
    $this->assertAuthenticated();
    $this->post('/worlds', ['name' => 'Theo World', 'league_name' => 'Solo League', 'year' => 2030])
        ->assertRedirect(route('home'));
    $this->assertDatabaseHas('leagues', ['name' => 'Solo League']);
    $this->assertDatabaseHas('seasons', ['year' => 2030, 'phase' => 'preseason']);
    $this->get('/')->assertOk();
});

test('users cannot select another world or access its bound team player or game', function () {
    $owner = User::factory()->create();
    footballWorld($owner);
    $team = Team::create(['city' => 'Memphis', 'name' => 'Tigers']);
    $player = Player::create(['firstname' => 'John', 'lastname' => 'Smith', 'age' => 22, 'position' => 'QB']);
    $game = Game::create(['home_team_id' => $team->id]);
    $visitor = User::factory()->create();
    footballWorld($visitor);
    $this->actingAs($visitor)->post('/worlds/'.$owner->current_world_id.'/select')->assertNotFound();
    $this->get('/teams/editor/'.$team->id.'/edit')->assertNotFound();
    $this->get('/players/'.$player->id)->assertNotFound();
    $this->get('/games/'.$game->id.'/boxscore')->assertNotFound();
    $this->get('/')->assertOk()->assertDontSee('Tigers');
});

test('game setup rejects teams from another world', function () {
    $other = User::factory()->create();
    footballWorld($other);
    $foreign = Team::create(['city' => 'Foreign', 'name' => 'Team']);
    $user = User::factory()->create();
    footballWorld($user);
    $local = Team::create(['city' => 'Local', 'name' => 'Team']);
    $this->actingAs($user)->post('/games/new', ['home_team_id' => $local->id, 'away_team_id' => $foreign->id])
        ->assertSessionHasErrors('away_team_id');
    $this->assertDatabaseCount('games', 0);
});

test('no world context fails closed and restoration stays scoped', function () {
    $user = User::factory()->create();
    footballWorld($user);
    $team = Team::create(['city' => 'One', 'name' => 'Team']);
    app(CurrentWorld::class)->id = null;
    expect(Team::count())->toBe(0);
    expect((new Team)->newQueryForRestoration($team->id)->exists())->toBeFalse();
    expect(fn () => Team::create(['city' => 'No', 'name' => 'World']))->toThrow(LogicException::class);
});

test('membership is checked even when current world id is forged', function () {
    $owner = User::factory()->create();
    $world = footballWorld($owner);
    $visitor = User::factory()->create();
    $visitor->forceFill(['current_world_id' => $world->id])->save();
    $this->actingAs($visitor)->get('/')->assertRedirect(route('worlds.index'));
});

test('legacy data is hidden until explicitly adopted and adoption is repeatable', function () {
    $user = User::factory()->create();
    $world = footballWorld($user);
    DB::table('teams')->insert(['city' => 'Legacy', 'name' => 'Team']);
    expect(Team::count())->toBe(0);
    $this->artisan('world:adopt-legacy', ['user' => $user->email, 'world' => $world->id])->assertSuccessful();
    $this->artisan('world:adopt-legacy', ['user' => $user->email, 'world' => $world->id])->assertSuccessful();
    expect(Team::count())->toBe(1);
});

test('login regenerates authenticated session and logout removes access', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $user->email, 'password' => 'password123'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('world switching prevents restoring or saving an old game', function () {
    $user = User::factory()->create();
    footballWorld($user, 'First');
    $game = Game::create([]);
    footballWorld($user, 'Second');
    expect((new Game)->newQueryForRestoration($game->id)->exists())->toBeFalse();
    expect(fn () => $game->update(['home_score' => 7]))->toThrow(LogicException::class);
    expect(fn () => $game->delete())->toThrow(LogicException::class);
});

test('games can be created with local teams and appear only in their world', function () {
    $user = User::factory()->create();
    $world = footballWorld($user);
    $home = Team::create(['city' => 'Memphis', 'name' => 'Tigers']);
    $away = Team::create(['city' => 'Jackson', 'name' => 'Bears']);
    $this->actingAs($user)->post('/games/new', ['home_team_id' => $home->id, 'away_team_id' => $away->id])
        ->assertRedirect();
    $this->assertDatabaseHas('games', ['world_id' => $world->id, 'home_team_id' => $home->id, 'phase' => 'KICKOFF']);
});

test('child records cannot reference another worlds players or games', function () {
    $user = User::factory()->create();
    footballWorld($user);
    $game = Game::create([]);
    $player = Player::create(['firstname' => 'John', 'lastname' => 'Smith', 'age' => 22, 'position' => 'QB']);
    footballWorld($user, 'Other');
    expect(fn () => App\Models\PlayerSeasonStat::create(['player_id' => $player->id, 'season_year' => 2030]))
        ->toThrow(LogicException::class);
    expect(fn () => App\Models\Play::create(['game_id' => $game->id]))->toThrow(LogicException::class);
});

test('livewire game updates reject a snapshot from a previously selected world', function () {
    $user = User::factory()->create();
    footballWorld($user);
    $home = Team::create(['city' => 'Memphis', 'name' => 'Tigers']);
    $away = Team::create(['city' => 'Jackson', 'name' => 'Bears']);
    $game = Game::create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'home_q' => [0, 0, 0, 0, 0], 'away_q' => [0, 0, 0, 0, 0]]);
    $component = Livewire\Livewire::actingAs($user)->test(App\Livewire\GameCompanion::class, ['gameId' => $game->id]);
    footballWorld($user, 'Other');
    expect(fn () => $component->call('setDownAndDistance'))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('the world migration rolls back and reapplies cleanly', function () {
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();
    expect(Illuminate\Support\Facades\Schema::hasTable('worlds'))->toBeFalse();
    expect(Illuminate\Support\Facades\Schema::hasColumn('games', 'world_id'))->toBeFalse();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(Illuminate\Support\Facades\Schema::hasTable('worlds'))->toBeTrue();
});
