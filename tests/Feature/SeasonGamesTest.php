<?php

use App\Models\Exhibition;
use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\World;
use App\Services\Seasons\SeasonGames;
use App\Services\Simulation\GamePersonnel;

beforeEach(function () {
    $this->withoutVite();
    $this->withoutMiddleware(\App\Http\Middleware\SetCurrentWorld::class);
    $world = World::create(['name' => 'Season games']);
    openFootballSave($world);
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 4])->assertSuccessful();
    Exhibition::query()->delete();
    $teams = Team::all();
    $league = League::create(['name' => 'Game league']);
    $setup = ['name' => 'Test season', 'year' => 2026, 'league_id' => $league->id, 'team_count' => 4,
        'games' => 3, 'bye' => 1, 'layout' => 'divisions', 'playoffs' => 'none',
        'teams' => $teams->pluck('id')->all(), 'human' => [$teams[0]->id], 'quarter_length' => 180];
    $this->post(route('seasons.store'), ['setup' => json_encode($setup)])->assertSessionHasNoErrors()->assertRedirect();
    $this->season = Season::where('league_id', $league->id)->firstOrFail();
    $this->fixture = $this->season->fixtures()->where('week', 1)->firstOrFail();
});

test('season games capture controls year quarter length and depth without changing permanent roster', function () {
    $team = $this->fixture->homeTeam;
    $qbs = $team->players()->wherePivot('position', 'QB')->orderBy('team_players.depth_chart_position')->get();
    expect($qbs->count())->toBeGreaterThan(1);
    $backup = $qbs[1];
    $pivotDepth = $backup->pivot->depth_chart_position;
    $settings = $this->season->settings;
    $settings['depth_charts'][$team->id]['QB'] = [$backup->id, $qbs[0]->id];
    $this->season->update(['settings' => $settings]);
    // A newer roster year must not replace the season's roster.
    TeamPlayer::create(['team_id' => $team->id, 'player_id' => $backup->id, 'team_year' => '2027', 'position' => 'QB', 'depth_chart_position' => 'QB1']);
    $this->post(route('seasons.game', [$this->season, $this->fixture]))->assertRedirect();
    $game = $this->fixture->fresh()->exhibition;
    expect($game->state['quarter_length'])->toBe(180);
    expect($game->rosters['home']['year'])->toBe('2026');
    expect($game->rosters['home']['players']['QB']['id'])->toBe($backup->id);
    expect(app(GamePersonnel::class)->active($game->rosters, $game->state)['home']['players']['QB']['id'])->toBe($backup->id);
    expect(TeamPlayer::where('team_id', $team->id)->where('player_id', $backup->id)->where('team_year', '2026')->first()->depth_chart_position)->toBe($pivotDepth);
    expect($game->state['controls']['home'])->toBe($settings['members'][$team->id]['control']);
    $original = $game->state;
    $this->put(route('seasons.rules', $this->season), ['quarter_length' => 600])->assertRedirect();
    $this->post(route('seasons.game', [$this->season, $this->fixture]), ['quick_sim' => 1])->assertRedirect(route('exhibitions.show', ['exhibition' => $game, 'summary' => 0]));
    expect($game->fresh()->state)->toBe($original);
    $this->assertDatabaseCount('exhibitions', 1);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Season · Week 1');
    $this->delete(route('exhibitions.destroy', $game))->assertStatus(409);
});

test('quick sim records one final result with box score and saved replays and cannot double count', function () {
    $this->post(route('seasons.game', [$this->season, $this->fixture]), ['quick_sim' => 1])->assertRedirect();
    $fixture = $this->fixture->fresh();
    $game = $fixture->exhibition;
    expect($fixture->status)->toBe('final')->and($game->state['status'])->toBe('final');
    expect((int) $fixture->home_score)->toBe($game->state['home_score']);
    expect($game->history)->not->toBeEmpty();
    expect($game->history[0]['animation'])->not->toBeEmpty();
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'summary' => 1]))->assertOk()->assertSee('Box score');
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'replay' => $game->history[0]['number']]))->assertOk();
    $service = app(SeasonGames::class);
    $rows = $service->standings($this->season);
    $service->record($game);
    $this->post(route('seasons.game', [$this->season, $this->fixture]), ['quick_sim' => 1])->assertRedirect();
    expect($service->standings($this->season))->toBe($rows);
    expect($rows[$fixture->home_team_id]['wins'] + $rows[$fixture->home_team_id]['losses'] + $rows[$fixture->home_team_id]['ties'])->toBe(1);
    $this->assertDatabaseCount('exhibitions', 1);
    $this->get(route('exhibitions.index'))->assertOk()->assertViewHas('games', fn ($games) => $games->isEmpty());
});

test('season final play records its result and provisional penalties do not finalize', function () {
    $this->post(route('seasons.game', [$this->season, $this->fixture]))->assertRedirect();
    $game = $this->fixture->fresh()->exhibition;
    $state = $game->state;
    $game->update(['state' => array_merge($state, ['status' => 'final', 'penalty_pending' => true])]);
    app(SeasonGames::class)->record($game);
    expect($this->fixture->fresh()->status)->toBe('playing');
    $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
    $state['coin_toss']['pending'] = false;
    $state['quarter'] = 4;
    $state['clock'] = 0;
    $state['home_score'] = 14;
    $state['away_score'] = 0;
    $state['rules']['overtime'] = 'none';
    $game->update(['state' => $state]);
    $this->post(route('exhibitions.play', $game), ['version' => 0])->assertRedirect();
    expect($game->fresh()->state['status'])->toBe('final');
    expect($this->fixture->fresh()->status)->toBe('final');
    $this->assertDatabaseHas('season_fixtures', ['id' => $this->fixture->id, 'home_score' => 14, 'away_score' => 0]);
});

test('advance requires every game final rejects stale weeks and completes only the regular season', function () {
    $this->post(route('seasons.advance', $this->season), ['week' => 1])->assertSessionHasErrors('week');
    $future = $this->season->fixtures()->where('week', '>', 1)->first();
    $this->post(route('seasons.game', [$this->season, $future]))->assertStatus(409);
    foreach ($this->season->fixtures()->where('week', 1)->get() as $fixture) {
        $fixture->update(['status' => 'final', 'home_score' => 7, 'away_score' => 7]);
    }
    $this->post(route('seasons.advance', $this->season), ['week' => 1])->assertRedirect();
    expect((int) $this->season->fresh()->current_week)->toBe(2);
    $this->post(route('seasons.advance', $this->season), ['week' => 1])->assertStatus(409);
    $rows = app(SeasonGames::class)->standings($this->season);
    expect($rows[$this->fixture->home_team_id]['ties'])->toBe(1)->and($rows[$this->fixture->home_team_id]['pct'])->toBe(.5);
    $this->season->fixtures()->update(['status' => 'final', 'home_score' => 21, 'away_score' => 7]);
    $last = (int) $this->season->fixtures()->max('week');
    $this->season->update(['current_week' => $last]);
    $this->post(route('seasons.advance', $this->season), ['week' => $last])->assertRedirect();
    expect($this->season->fresh()->phase)->toBe('completed');
});

test('season fixture routes reject another season and another world', function () {
    $other = Season::create(['league_id' => $this->season->league_id, 'year' => 2027, 'settings' => $this->season->settings]);
    $this->post(route('seasons.game', [$other, $this->fixture]))->assertNotFound();
    $world = World::create(['name' => 'Other']);
    openFootballSave($world);
    $this->post(route('seasons.game', [$this->season, $this->fixture]))->assertNotFound();
    $this->post(route('seasons.advance', $this->season), ['week' => 1])->assertNotFound();
});

test('incomplete season rosters leave no partially created game or fixture link', function () {
    $team = $this->fixture->homeTeam;
    TeamPlayer::where('team_id', $team->id)->where('position', 'QB')->delete();
    $this->post(route('seasons.game', [$this->season, $this->fixture]))->assertSessionHasErrors('teams');
    expect($this->fixture->fresh()->status)->toBe('scheduled')->and($this->fixture->fresh()->exhibition_id)->toBeNull();
    $this->assertDatabaseCount('exhibitions', 0);
});
