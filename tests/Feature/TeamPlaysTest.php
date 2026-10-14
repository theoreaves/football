<?php

use App\Models\Exhibition;
use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GamePersonnel;
use App\Services\Simulation\RosterBuilder;
use App\Services\Simulation\TeamPlays;

beforeEach(function () {
    $this->withoutVite();
    $this->withoutMiddleware(\App\Http\Middleware\SetCurrentWorld::class);
    $world = World::create(['name' => 'Team plays']);
    openFootballSave($world);
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 4])->assertSuccessful();
    $teams = Team::all();
    $league = League::create(['name' => 'Playbook league']);
    $setup = ['name' => 'Playbook season', 'year' => 2026, 'league_id' => $league->id, 'team_count' => 4,
        'games' => 3, 'bye' => 1, 'layout' => 'divisions', 'playoffs' => 'none',
        'teams' => $teams->pluck('id')->all(), 'human' => $teams->pluck('id')->all(), 'quarter_length' => 180];
    $this->post(route('seasons.store'), ['setup' => json_encode($setup)])->assertSessionHasNoErrors();
    $this->season = Season::where('league_id', $league->id)->firstOrFail();
    $this->fixture = $this->season->fixtures()->where('week', 1)->firstOrFail();
    $this->home = $this->fixture->homeTeam;
    $this->away = $this->fixture->awayTeam;
    $this->homeRoster = app(RosterBuilder::class)->build($this->home, '2026');
    $this->awayRoster = app(RosterBuilder::class)->build($this->away, '2026');
});

function saveHeavyTeamPlay($context): string
{
    $context->post(route('seasons.plays.save', [$context->season, $context->home]), ['name' => 'Heavy Push', 'kind' => 'offense', 'formation' => 'goal_line', 'call' => 'power', 'personnel' => ['FB' => $context->homeRoster['players']['DT1']['id']]])->assertSessionHasNoErrors()->assertRedirect();

    return array_key_first($context->season->fresh()->settings['team_plays'][$context->home->id]);
}

function startTeamPlayGame($context): Exhibition
{
    $context->post(route('seasons.game', [$context->season, $context->fixture]))->assertRedirect();
    $game = $context->fixture->fresh()->exhibition;
    $state = app(ExhibitionEngine::class)->initial(900, 42, false);
    $state['controls'] = ['home' => 'human', 'away' => 'human'];
    $state['rules'] = ['penalties' => false, 'injuries' => false];
    $game->update(['state' => $state]);

    return $game;
}

test('season team play editor saves edits deletes and snapshots named plays without changing other teams', function () {
    $id = saveHeavyTeamPlay($this);
    $this->get(route('seasons.team', [$this->season, $this->home, 'tab' => 'plays']))->assertOk()->assertSee('Heavy Push')->assertSee('Create team play')->assertSee('data-team-play-editor', false);
    $this->get(route('seasons.team', [$this->season, $this->home, 'tab' => 'plays', 'edit' => $id]))->assertOk()->assertSee('Edit team play');
    $game = startTeamPlayGame($this);
    expect($game->rosters['home']['team_plays'][$id]['name'])->toBe('Heavy Push');
    expect($game->rosters['away']['team_plays'])->toBe([]);
    $this->post(route('seasons.plays.save', [$this->season, $this->home]), ['id' => $id, 'name' => 'Heavy Push Revised', 'kind' => 'offense', 'formation' => 'goal_line', 'call' => 'power', 'personnel' => []])->assertSessionHasNoErrors();
    expect($game->fresh()->rosters['home']['team_plays'][$id]['name'])->toBe('Heavy Push');
    expect($this->season->fresh()->settings['team_plays'][$this->home->id][$id]['name'])->toBe('Heavy Push Revised');
    $this->delete(route('seasons.plays.delete', [$this->season, $this->away, $id]))->assertNotFound();
    $this->delete(route('seasons.plays.delete', [$this->season, $this->home, $id]))->assertRedirect();
    expect($this->season->fresh()->settings['team_plays'][$this->home->id])->toBe([]);
    expect($game->fresh()->rosters['home']['team_plays'])->toHaveKey($id);
    expect($this->home->fresh()->team_settings)->not->toHaveKey('team_plays');
});

test('team plays reject illegal formations foreign duplicate and wrong year players', function () {
    $valid = ['name' => 'Test', 'kind' => 'offense', 'formation' => 'goal_line', 'call' => 'power'];
    foreach ([['formation' => 'punt', 'call' => 'fake_punt_run'], ['call' => 'mesh'], ['personnel' => ['WR4' => $this->homeRoster['players']['WR1']['id']]]] as $invalid) {
        $this->post(route('seasons.plays.save', [$this->season, $this->home]), array_replace($valid, $invalid))->assertSessionHasErrors('call');
    }
    $this->post(route('seasons.plays.save', [$this->season, $this->home]), $valid + ['personnel' => ['FB' => $this->awayRoster['players']['RB']['id']]])->assertSessionHasErrors('personnel');
    $future = $this->home->players()->first()->replicate();
    $future->firstname = 'Future';
    $future->save();
    $this->home->players()->attach($future->id, ['team_year' => '2027', 'position' => 'RB', 'depth_chart_position' => 'RB4', 'jersey_number' => 38]);
    $this->post(route('seasons.plays.save', [$this->season, $this->home]), $valid + ['personnel' => ['FB' => $future->id]])->assertSessionHasErrors('personnel');
    $id = $this->homeRoster['players']['RB']['id'];
    $this->post(route('seasons.plays.save', [$this->season, $this->home]), $valid + ['personnel' => ['FB' => $id, 'RB' => $id]])->assertSessionHasErrors('personnel');
    $this->post(route('seasons.plays.save', [$this->season, $this->home]), $valid + ['personnel' => ['FB' => $id]])->assertSessionHasNoErrors();
    $this->post(route('seasons.plays.save', [$this->season, $this->home]), array_replace($valid, ['name' => ' test ']))->assertSessionHasErrors('name');
    $this->get(route('seasons.team', [$this->season, $this->home, 'tab' => 'plays', 'edit' => 'invalid']))->assertSessionHasErrors('edit');
});

test('named offense defense and one play overrides use actual identities and revert after the snap', function () {
    $homeId = saveHeavyTeamPlay($this);
    $wr = $this->awayRoster['players']['WR1']['id'];
    $this->post(route('seasons.plays.save', [$this->season, $this->away]), ['name' => 'Hands Defense', 'kind' => 'defense', 'formation' => 'nickel', 'call' => 'zone', 'expect' => 'pass', 'blitz' => 1, 'personnel' => ['CB3' => $wr]])->assertSessionHasNoErrors();
    $awayId = array_key_first($this->season->fresh()->settings['team_plays'][$this->away->id]);
    $game = startTeamPlayGame($this);
    $call = ['version' => 0, 'call' => 'power', 'offense_formation' => 'goal_line', 'defense' => 'zone', 'defense_formation' => 'nickel', 'expect' => 'pass', 'blitz' => 1, 'team_play' => ['home' => $homeId, 'away' => $awayId]];
    $before = $game->fresh()->toArray();
    $preview = $this->postJson(route('exhibitions.personnel', $game), $call)->assertOk();
    expect($preview->json('teams.home.players.FB.id'))->toBe($this->homeRoster['players']['DT1']['id']);
    expect($preview->json('teams.away.players.CB3.id'))->toBe($wr);
    expect($game->fresh()->toArray())->toBe($before);
    $backup = $this->homeRoster['players']['RB']['id'];
    $call['one_play']['home']['FB'] = $backup;
    $this->postJson(route('exhibitions.personnel', $game), $call)->assertOk()->assertJsonPath('teams.home.players.FB.id', $backup);
    $this->post(route('exhibitions.play', $game), $call)->assertRedirect();
    $game = $game->fresh();
    $play = $game->history[0];
    expect($play['team_plays']['home']['name'])->toBe('Heavy Push')->and($play['team_plays']['away']['name'])->toBe('Hands Defense');
    expect($game->state)->not->toHaveKey('_snap_lineup');
    expect($play['after'])->not->toHaveKey('_snap_lineup');
    $tracks = collect($play['animation']['players']);
    expect($tracks->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'FB')['id'])->toBe($backup);
    expect($tracks->first(fn ($p) => $p['side'] === 'away' && $p['role'] === 'CB3')['id'])->toBe($wr);
    expect(app(GamePersonnel::class)->active($game->rosters, $game->state)['away']['players']['CB3']['id'])->not->toBe($wr);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Hands Defense')->assertSee('data-team-plays', false);
});

test('unavailable named personnel fall back automatically and forged calls leave state untouched', function () {
    $id = saveHeavyTeamPlay($this);
    $game = startTeamPlayGame($this);
    $state = $game->state;
    $lineman = $this->homeRoster['players']['DT1']['id'];
    $state['injuries']['home'][$lineman] = ['return_snap' => null];
    $game->update(['state' => $state]);
    $data = ['version' => 0, 'call' => 'power', 'offense_formation' => 'goal_line', 'defense' => 'zone', 'team_play' => ['home' => $id]];
    $preview = $this->postJson(route('exhibitions.personnel', $game), $data)->assertOk();
    expect($preview->json('teams.home.players.FB.id'))->not->toBe($lineman);
    expect($preview->json('notices.0'))->toContain('Automatic')->toContain('Injured');
    foreach ([['call' => 'inside_run'], ['offense_formation' => 'shotgun'], ['team_play' => ['away' => $id]]] as $invalid) {
        $this->postJson(route('exhibitions.play', $game), array_replace($data, $invalid))->assertUnprocessable();
    }
    expect($game->fresh()->state)->toBe($state);
    $state['controls']['home'] = 'cpu';
    $game->update(['state' => $state]);
    $this->postJson(route('exhibitions.personnel', $game), $data)->assertUnprocessable();
});

test('standard calls retain identical deterministic results and named metadata survives penalty choices', function () {
    $game = startTeamPlayGame($this);
    $state = $game->state;
    $offense = ['call' => 'inside_run', 'formation' => 'shotgun'];
    $defense = ['call' => 'zone', 'formation' => 'base_4_3'];
    $service = app(TeamPlays::class);
    $prepared = $service->prepare($state, $game->rosters, [], [], $offense, $defense);
    expect($prepared['state'])->toBe($state);
    $engine = app(ExhibitionEngine::class);
    $result = $engine->resolve($state, $game->rosters, 'inside_run', 'zone');
    expect($service->annotate($result, $prepared))->toBe($result);
    $prepared['metadata'] = ['home' => ['name' => 'Test']];
    $result['play']['penalty_options'] = ['accept' => $result, 'decline' => $result];
    $annotated = $service->annotate($result, $prepared);
    foreach ($annotated['play']['penalty_options'] as $option) {
        expect($option['play']['team_plays']['home']['name'])->toBe('Test');
    }
});

test('playbook cap prevents new entries while still permitting an existing play to be edited', function () {
    $settings = $this->season->settings;
    $plays = [];
    for ($i = 0; $i < TeamPlays::LIMIT; $i++) {
        $id = (string) \Illuminate\Support\Str::uuid();
        $plays[$id] = ['id' => $id, 'name' => 'Saved '.$i, 'kind' => 'offense', 'formation' => 'goal_line', 'call' => 'power', 'expect' => 'balanced', 'blitz' => false, 'personnel' => []];
    }
    $settings['team_plays'][$this->home->id] = $plays;
    $this->season->update(['settings' => $settings]);
    $data = ['name' => 'Another play', 'kind' => 'offense', 'formation' => 'goal_line', 'call' => 'power'];
    $this->post(route('seasons.plays.save', [$this->season, $this->home]), $data)->assertSessionHasErrors('name');
    $id = array_key_first($plays);
    $this->post(route('seasons.plays.save', [$this->season, $this->home]), $data + ['id' => $id])->assertSessionHasNoErrors();
    expect($this->season->fresh()->settings['team_plays'][$this->home->id])->toHaveCount(TeamPlays::LIMIT);
});
