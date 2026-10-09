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
    $this->post(route('seasons.game', [$this->season, $this->fixture]), ['quick_sim' => 1])->assertRedirect(route('seasons.show', ['season' => $this->season, 'tab' => 'overview']));
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
    $this->get(route('seasons.box-score', [$this->season, $fixture]))->assertOk()->assertSee('Team statistics')->assertDontSee('data-practice', false);
    $this->get(route('seasons.show', ['season' => $this->season, 'tab' => 'schedule']))->assertOk()->assertSee('data-season-box', false);
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'summary' => 1]))->assertOk()->assertSee('Box score');
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'replay' => $game->history[0]['number']]))->assertOk();
    $service = app(SeasonGames::class);
    $rows = $service->standings($this->season);
    $service->record($game);
    $this->post(route('seasons.game', [$this->season, $this->fixture]), ['quick_sim' => 1, 'return_tab' => 'schedule'])->assertRedirect(route('seasons.show', ['season' => $this->season, 'tab' => 'schedule']));
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

test('finish with quick sim keeps existing plays and highlights and records the season final once', function () {
    $this->post(route('seasons.game', [$this->season, $this->fixture]))->assertRedirect();
    $game = $this->fixture->fresh()->exhibition;
    $state = $game->state;
    $state['coin_toss']['pending'] = false;
    $state['rules']['penalties'] = false;
    $game->update(['state' => $state]);
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'call' => 'kickoff', 'defense' => 'kickoff_return'])->assertRedirect();
    $game->refresh();
    $history = $game->history;
    $history[0]['saved_highlight'] = true;
    $game->update(['history' => $history]);
    $controls = $game->state['controls'];
    $version = $game->state['version'];
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Finish with Quick Sim');
    $this->post(route('exhibitions.finish', $game), ['version' => $version])->assertRedirect(route('exhibitions.show', ['exhibition' => $game, 'summary' => 1]));
    $game->refresh();
    expect($game->state['status'])->toBe('final')->and($game->state['controls'])->toBe($controls);
    expect($game->history[0])->toBe($history[0])->and(count($game->history))->toBeGreaterThan(count($history));
    expect(array_column($game->history, 'number'))->toBe(range(1, count($game->history)));
    expect($this->fixture->fresh()->status)->toBe('final');
    $snapshot = $game->history;
    $this->post(route('exhibitions.finish', $game), ['version' => $version])->assertStatus(409);
    $this->post(route('exhibitions.finish', $game), ['version' => $game->state['version']])->assertRedirect();
    expect($game->fresh()->history)->toBe($snapshot);
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'replay' => 1]))->assertOk()->assertDontSee('Finish with Quick Sim');
});

test('finish with quick sim lets CPU resolve a pending opening coin choice', function () {
    $this->post(route('seasons.game', [$this->season, $this->fixture]))->assertRedirect();
    $game = $this->fixture->fresh()->exhibition;
    $state = $game->state;
    $state['coin_toss']['winner'] = 'home';
    $state['coin_toss']['pending'] = true;
    $state['controls'] = ['home' => 'human', 'away' => 'human'];
    $state['quarter'] = 4;
    $state['clock'] = 0;
    $state['home_score'] = 14;
    $state['away_score'] = 0;
    $game->update(['state' => $state]);
    $this->post(route('exhibitions.finish', $game), ['version' => 0])->assertRedirect();
    $game->refresh();
    expect($game->state['status'])->toBe('final')->and($game->state['coin_toss']['pending'])->toBeFalse();
    expect($game->state['coin_toss']['choice'])->toBeIn(['kick', 'receive']);
});

test('finish with quick sim resolves saved penalty options before counting the final result', function () {
    $this->post(route('seasons.game', [$this->season, $this->fixture]))->assertRedirect();
    $game = $this->fixture->fresh()->exhibition;
    $state = $game->state;
    $state['coin_toss']['pending'] = false;
    $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
    $state['rules']['penalties'] = false;
    $game->update(['state' => $state]);
    $this->post(route('exhibitions.play', $game), ['version' => 0])->assertRedirect();
    $game->refresh();
    $state = $game->state;
    $state['quarter'] = 4;
    $state['clock'] = 0;
    $state['home_score'] = 14;
    $state['away_score'] = 0;
    $state['controls'] = ['home' => 'human', 'away' => 'human'];
    $history = $game->history;
    $accepted = $history[0];
    $accepted['summary'] = 'Accepted saved holding penalty';
    $accepted['penalty'] = ['accepted' => true, 'beneficiary' => 'home'];
    $history[0]['penalty'] = $accepted['penalty'];
    $history[0]['penalty_options'] = ['accept' => ['state' => $state, 'play' => $accepted]];
    $game->update(['state' => array_merge($state, ['penalty_pending' => true]), 'history' => $history]);
    $this->post(route('exhibitions.finish', $game), ['version' => $state['version']])->assertRedirect();
    $game->refresh();
    expect($game->state['status'])->toBe('final')->and($game->state['penalty_pending'] ?? false)->toBeFalse();
    expect($game->history[0]['summary'])->toBe('Accepted saved holding penalty')->and($game->history[0]['penalty']['decided'])->toBeTrue();
    expect($this->fixture->fresh()->status)->toBe('final');
});

test('finish with quick sim resolves a pending overtime toss', function () {
    $this->post(route('seasons.game', [$this->season, $this->fixture]))->assertRedirect();
    $game = $this->fixture->fresh()->exhibition;
    $state = $game->state;
    $state['coin_toss']['pending'] = false;
    $state['controls'] = ['home' => 'human', 'away' => 'human'];
    $state['quarter'] = 4;
    $state['clock'] = 0;
    $state = app(\App\Services\Simulation\Overtime::class)->begin($state);
    $state['clock'] = 1;
    expect(app(\App\Services\Simulation\Overtime::class)->pending($state))->toBeTrue();
    $game->update(['state' => $state]);
    $this->post(route('exhibitions.finish', $game), ['version' => $state['version']])->assertRedirect();
    $game->refresh();
    expect($game->state['status'])->toBe('final')->and(app(\App\Services\Simulation\Overtime::class)->pending($game->state))->toBeFalse();
});

test('box score routes require a started fixture from the requested season and world', function () {
    $this->get(route('seasons.box-score', [$this->season, $this->fixture]))->assertNotFound();
    $this->post(route('seasons.game', [$this->season, $this->fixture]))->assertRedirect();
    $this->get(route('seasons.box-score', [$this->season, $this->fixture]))->assertOk()->assertSee('Box score')->assertDontSee('data-practice', false);
    $other = Season::create(['league_id' => $this->season->league_id, 'year' => 2027, 'settings' => $this->season->settings]);
    $this->get(route('seasons.box-score', [$other, $this->fixture]))->assertNotFound();
    openFootballSave(World::create(['name' => 'Foreign box score']));
    $this->get(route('seasons.box-score', [$this->season, $this->fixture]))->assertNotFound();
});

test('bulk CPU sim finishes only unstarted CPU games in the current week and is repeatable', function () {
    $season = $this->season;
    $members = $season->settings['members'];
    $current = $season->fixtures()->where('week', 1)->get();
    $cpu = $current->first(fn ($game) => $members[$game->home_team_id]['control'] === 'cpu' && $members[$game->away_team_id]['control'] === 'cpu');
    $human = $current->first(fn ($game) => $game->id !== $cpu->id);
    $this->get(route('seasons.show', $season))->assertOk()->assertSee('season-human-game', false)->assertSee('Human team')->assertSee('Sim all CPU vs CPU Games (1)');
    $this->post(route('seasons.sim-cpu', $season), ['week' => 1, 'return_tab' => 'schedule'])
        ->assertRedirect(route('seasons.show', ['season' => $season, 'tab' => 'schedule']))->assertSessionHasNoErrors();
    expect($cpu->fresh()->status)->toBe('final')->and($human->fresh()->exhibition_id)->toBeNull();
    expect($season->fixtures()->where('week', '>', 1)->whereNotNull('exhibition_id')->count())->toBe(0);
    $this->assertDatabaseCount('exhibitions', 1);
    $this->post(route('seasons.sim-cpu', $season), ['week' => 1])->assertRedirect();
    $this->assertDatabaseCount('exhibitions', 1);
    $this->post(route('seasons.sim-cpu', $season), ['week' => 2])->assertStatus(409);
});

test('bulk CPU sim skips games already underway and respects control changes', function () {
    $season = $this->season;
    $members = $season->settings['members'];
    $cpu = $season->fixtures()->where('week', 1)->get()->first(fn ($game) => $members[$game->home_team_id]['control'] === 'cpu' && $members[$game->away_team_id]['control'] === 'cpu');
    $game = app(SeasonGames::class)->start($season, $cpu, false);
    $original = $game->state;
    $this->post(route('seasons.sim-cpu', $season), ['week' => 1])->assertRedirect();
    expect($game->fresh()->state)->toBe($original)->and($cpu->fresh()->status)->toBe('playing');
    $settings = $season->settings;
    foreach ($settings['members'] as &$member) {
        $member['control'] = 'human';
    }
    unset($member);
    $season->update(['settings' => $settings]);
    $this->post(route('seasons.sim-cpu', $season), ['week' => 1])->assertRedirect()->assertSessionHas('status', 'No unstarted CPU vs CPU games remain this week.');
    $this->assertDatabaseCount('exhibitions', 1);
});
