<?php

use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\World;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(\App\Models\User::factory()->create());
});
function footballSave(string $name = 'Solo'): World
{
    $world = World::create(['name' => $name]);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
    app(CurrentWorld::class)->id = $world->id;

    return $world;
}
test('browser routes require login and accounts own new saves', function () {
    \Illuminate\Support\Facades\Auth::logout();
    $this->get('/')->assertOk()->assertSee('Call the play.')->assertSee(route('register'));
    $this->get('/worlds')->assertRedirect(route('login'));
    $this->get('/login')->assertOk();
    $this->get('/register')->assertOk();
    $this->get('/practice')->assertRedirect(route('login'));
});
test('browser saves create leagues and seasons for their owner', function () {
    $this->post('/worlds', ['name' => 'Theo Save', 'league_name' => 'Solo League', 'year' => 2030])->assertRedirect(route('home'));
    $this->assertDatabaseHas('seasons', ['year' => 2030, 'phase' => 'preseason']);
    $this->assertDatabaseHas('worlds', ['name' => 'Theo Save', 'owner_user_id' => auth()->id()]);
    $this->get('/')->assertOk();
});
test('separate saves cannot mix bound teams or exhibition records', function () {
    footballSave('First');
    $team = Team::create(['city' => 'Memphis', 'name' => 'Tigers']);
    $game = Exhibition::create(['home_team_id' => $team->id, 'away_team_id' => $team->id, 'state' => [], 'rosters' => [], 'history' => []]);
    footballSave('Second');
    $this->get(route('teams.editor.edit', $team))->assertNotFound();
    $this->get(route('exhibitions.show', $game))->assertNotFound();
    $this->get('/')->assertOk()->assertDontSee('Tigers');
    expect(fn () => $game->update(['history' => []]))->toThrow(LogicException::class);
    expect(fn () => $game->delete())->toThrow(LogicException::class);
});
test('save selection persists and closing restores the picker', function () {
    $first = footballSave('First');
    footballSave('Second');
    $this->post('/worlds/'.$first->id.'/select')->assertRedirect(route('home'))->assertSessionHas('current_world_id', $first->id);
    $this->post('/worlds/9999/select')->assertNotFound();
    $this->post('/worlds/close')->assertRedirect(route('worlds.index'));
    $this->get('/')->assertRedirect(route('worlds.index'));
});
test('no current save exposes no football records and rejects writes', function () {
    footballSave();
    $team = Team::create(['city' => 'One', 'name' => 'Team']);
    app(CurrentWorld::class)->id = null;
    expect(Team::count())->toBe(0)->and((new Team)->newQueryForRestoration($team->id)->exists())->toBeFalse();
    expect(fn () => Team::create(['city' => 'No', 'name' => 'Save']))->toThrow(LogicException::class);
});
test('roster records cannot reference players from a different save', function () {
    footballSave();
    $player = Player::create(['firstname' => 'John', 'lastname' => 'Smith', 'age' => 22, 'position' => 'QB']);
    footballSave('Other');
    $team = Team::create(['city' => 'Two', 'name' => 'Team']);
    expect(fn () => TeamPlayer::create(['team_id' => $team->id, 'player_id' => $player->id, 'team_year' => '2030', 'position' => 'QB', 'depth_chart_position' => 'QB1']))->toThrow(LogicException::class);
});
test('multiple demo saves keep independent teams rosters and exhibitions', function () {
    foreach (['First Demo', 'Second Demo'] as $name) {
        $this->post('/worlds', ['name' => $name, 'league_name' => 'League', 'year' => 2026, 'demo' => 1])->assertRedirect(route('home'));
    }
    $this->assertDatabaseCount('teams', 8);
    $this->assertDatabaseCount('players', 424);
    $this->assertDatabaseCount('exhibitions', 12);
});
