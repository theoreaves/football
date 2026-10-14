<?php

use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionBoxScore;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    $world = World::create(['name' => 'Presentation']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
    app(CurrentWorld::class)->id = $world->id;
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    app(CurrentWorld::class)->id = $world->id;
});

test('player proportions are snapshotted and logos are served from the active local save', function () {
    Storage::fake('team_art');
    $team = Team::firstOrFail();
    $player = $team->players()->firstOrFail();
    $player->update(['height_inches' => 79, 'weight_pounds' => 315, 'skin_tone' => '#593b2c']);
    $snapshot = app(RosterBuilder::class)->build($team);
    $person = collect($snapshot['players'])->firstWhere('id', $player->id);
    expect($person['height_inches'])->toBe(79)->and($person['weight_pounds'])->toBe(315)->and($person['skin_tone'])->toBe('#593b2c');
    Storage::disk('team_art')->put('teams/'.$team->id.'/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6hNwAAAAASUVORK5CYII='));
    $team->update(['team_logo' => 'teams/'.$team->id.'/logo.png', 'endzone_transparent' => true, 'uniform_home_pants_stripe_enabled' => true, 'uniform_home_pants_stripe' => '#123456']);
    $this->get(route('practice', ['home' => $team->id]))->assertOk()->assertViewHas('appearance', fn ($a) => $a['home']['endzone_transparent'] && $a['home']['uniform']['pants_stripe_enabled'] && $a['home']['midfield_logo'] === route('teams.art', [$team, 'team_logo']));
    $this->get(route('teams.art', [$team, 'team_logo']))->assertOk();
    $this->get(route('teams.art', [$team, 'not_an_asset']))->assertNotFound();
    $this->get(route('teams.editor.teams.players.edit', [$team, $player]))->assertOk()->assertSee('Height (inches)')->assertSee('Skin tone');
    $this->put(route('teams.editor.teams.players.update', [$team, $player]), ['height_inches' => 999, 'weight_pounds' => -1, 'skin_tone' => 'blue'])->assertSessionHasErrors(['height_inches', 'weight_pounds', 'skin_tone']);
    $other = World::create(['name' => 'Other']);
    LocalSetting::find(1)->update(['current_world_id' => $other->id]);
    openFootballSave($other);
    $this->get(route('teams.art', [$team, 'team_logo']))->assertNotFound();
});

test('box score reconciles scores and offensive player totals from a complete CPU game', function () {
    $teams = Team::all();
    $rosters = ['home' => app(RosterBuilder::class)->build($teams[0]), 'away' => app(RosterBuilder::class)->build($teams[1])];
    $engine = app(ExhibitionEngine::class);
    $coach = app(CpuCoach::class);
    $state = $engine->initial(180, 91);
    $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
    $history = [];
    for ($i = 0; $i < 1000 && $state['status'] !== 'final'; $i++) {
        if ($timeout = $coach->timeoutTeam($state)) {
            $result = $engine->timeout($state, $rosters, $timeout);
        } else {
            $off = $coach->offense($state, $rosters);
            $def = $coach->defense($state, $off['call']);
            $clock = $coach->management($state);
            $result = $engine->resolve($state, $rosters, $off['call'], $def['call'], $off['formation'], $def['formation'], $clock['tempo'], $clock['clock_strategy']);
        }
        $state = $result['state'];
        $history[] = $result['play'];
    }
    expect($state['status'])->toBe('final');
    $game = Exhibition::create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id, 'state' => $state, 'rosters' => $rosters, 'history' => $history]);
    $score = app(ExhibitionBoxScore::class)->build($game);
    foreach (['home', 'away'] as $side) {
        expect(array_sum($score['quarters'][$side]))->toBe($state[$side.'_score']);
        $players = collect($score['players'][$side]);
        expect($players->sum('completions'))->toBe($players->sum('receptions'));
        expect($players->sum('passing_yards'))->toBe($players->sum('receiving_yards'));
        expect($score['teams'][$side]['yards'])->toBe($score['teams'][$side]['rushing_yards'] + $score['teams'][$side]['passing_yards']);
        expect($score['teams'][$side]['plays'])->toBe($players->sum('pass_attempts') + $players->sum('sacks') + $players->sum('rushes'));
        expect($score['teams'][$side]['penalties'])->toBe($state['stats'][$side]['penalties']);
        $other = $side === 'home' ? 'away' : 'home';
        expect(collect($score['players'][$other])->sum('defensive_interceptions'))->toBe($players->sum('interceptions'));
        expect(collect($score['players'][$other])->sum('defensive_sacks'))->toBe($players->sum('sacks'));
    }
    expect(array_sum(array_column($score['teams'], 'possession_seconds')))->toBe(720);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('game-field')->assertSee('data-open-stats', false)->assertSee('data-stats-dialog', false)->assertSee('data-stats-tab="team"', false)->assertSee('data-stats-tab="lineups"', false)->assertSee('data-stats-tab="box"', false)->assertSee('data-stats-tab="log"', false)->assertSee('Scoring summary')->assertSee('data-stats-panel="log"', false)->assertSee('OK · Continue')->assertSee('Box score');
});

test('helmet sides are independent and end zone artwork is save scoped', function () {
    Storage::fake('team_art');
    $team = Team::firstOrFail();
    Storage::disk('team_art')->put('teams/left.png', 'test');
    $team->update(['helmet_logo_left' => 'teams/left.png', 'helmet_logo_right' => null, 'team_logo' => 'teams/left.png', 'endzone_logo_left' => 'teams/left.png']);
    $this->get(route('practice', ['home' => $team->id]))->assertOk()->assertViewHas('appearance', fn ($a) => $a['home']['helmet_logo_left'] === route('teams.art', [$team, 'helmet_logo_left']) && $a['home']['helmet_logo_right'] === null && $a['home']['endzone_logo_left'] === route('teams.art', [$team, 'endzone_logo_left']) && $a['home']['endzone_logo_right'] === null);
    $this->get(route('teams.art', [$team, 'endzone_logo_left']))->assertOk();
});

test('new snapshots field genuine linebacker and nickel personnel', function () {
    $team = Team::firstOrFail();
    $roster = app(RosterBuilder::class)->build($team);
    $engine = app(ExhibitionEngine::class);
    foreach (['base_3_5' => ['LB4', 'LB5'], 'nickel' => ['CB3']] as $formation => $roles) {
        $result = $engine->resolve($engine->initial(180, 42, false), ['home' => $roster, 'away' => $roster], 'inside_run', 'man_to_man', 'shotgun', $formation);
        $players = collect($result['play']['animation']['players'])->where('team', 'defense');
        expect($players)->toHaveCount(11);
        foreach ($roles as $role) {
            expect($players->firstWhere('role', $role)['id'])->toBe($roster['players'][$role]['id']);
        }
        expect($players->pluck('id')->unique())->toHaveCount(11);
    }
});

test('possession changes expose snapshotted incoming personnel and sideline rosters without changing game data', function () {
    $teams = Team::all();
    $rosters = ['home' => app(RosterBuilder::class)->build($teams[0]), 'away' => app(RosterBuilder::class)->build($teams[1])];
    $engine = app(ExhibitionEngine::class);
    $state = $engine->initial(900, 42);
    $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
    $state['phase'] = 'scrimmage';
    $result = $engine->resolve($state, $rosters, 'punt', 'punt_return', 'punt', 'base_4_3');
    $game = Exhibition::create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'state' => $result['state'], 'rosters' => $rosters, 'history' => [$result['play']]]);
    $saved = $game->only(['state', 'history', 'rosters']);
    $this->get(route('exhibitions.show', $game))->assertOk()
        ->assertSee('data-sideline-roster', false)->assertViewHas('unitChange', true)
        ->assertViewHas('nextAnimation', function ($animation) use ($result, $rosters) {
            if ($animation['possession'] !== $result['state']['possession']) {
                return false;
            }
            foreach ($animation['players'] as $player) {
                if (! collect($rosters[$player['side']]['pool'] ?? $rosters[$player['side']]['players'])->contains('id', $player['id'])) {
                    return false;
                }
            }

            return true;
        })
        ->assertViewHas('sidelineRoster', fn ($roster) => count($roster['home']) > 11 && ! isset($roster['home'][0]['ratings']));
    expect($game->fresh()->only(['state', 'history', 'rosters']))->toBe($saved);
    $this->get(route('exhibitions.show', [$game, 'replay' => $result['play']['number']]))
        ->assertOk()->assertViewHas('unitChange', false)->assertViewHas('nextAnimation', null);
});

test('individual injury fatigue and manual substitutions expose only presentation changes and preserve replays', function () {
    $teams = Team::all();
    $rosters = ['home' => app(RosterBuilder::class)->build($teams[0]), 'away' => app(RosterBuilder::class)->build($teams[1])];
    $engine = app(ExhibitionEngine::class);
    $state = $engine->initial(900, 42, false);
    $state['rules'] = ['penalties' => false, 'injuries' => false];
    $result = $engine->resolve($state, $rosters, 'inside_run', 'run_stop');
    $rb = $rosters['home']['players']['RB']['id'];
    $backup = collect($rosters['home']['pool'])->first(fn ($p) => $p['position'] === 'RB' && $p['id'] !== $rb)['id'];
    foreach ([['injuries' => ['home' => [$rb => ['return_snap' => null]]]], ['fatigue' => ['home' => [$rb => 80]]], ['game_lineup' => ['home' => ['RB' => $backup]]]] as $change) {
        $changed = array_replace_recursive($result['state'], $change);
        $game = Exhibition::create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id, 'state' => $changed, 'rosters' => $rosters, 'history' => [$result['play']]]);
        $saved = $game->only(['state', 'history', 'rosters']);
        $this->get(route('exhibitions.show', $game))->assertOk()->assertViewHas('unitChange', false)->assertViewHas('substitutionChange', true)
            ->assertViewHas('nextAnimation', fn ($a) => collect($a['players'])->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'RB')['id'] === $backup);
        expect($game->fresh()->only(['state', 'history', 'rosters']))->toBe($saved);
        $this->get(route('exhibitions.show', [$game, 'replay' => $result['play']['number']]))->assertOk()->assertViewHas('substitutionChange', false)->assertViewHas('nextAnimation', null);
        app(CurrentWorld::class)->id = (int) $teams[0]->world_id;
    }
});

test('special play options render and CPU onside previews preserve the saved game', function () {
    $teams = Team::all();
    $rosters = ['home' => app(RosterBuilder::class)->build($teams[0]), 'away' => app(RosterBuilder::class)->build($teams[1])];
    $state = array_replace(app(ExhibitionEngine::class)->initial(900, 42, false), [
        'phase' => 'kickoff', 'quarter' => 4, 'clock' => 82, 'home_score' => 20, 'away_score' => 22,
        'controls' => ['home' => 'cpu', 'away' => 'human'], 'rules' => ['penalties' => false, 'injuries' => false],
    ]);
    $game = Exhibition::create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id, 'state' => $state, 'rosters' => $rosters, 'history' => []]);
    $saved = $game->only(['state', 'history', 'rosters']);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertViewHas('cpuPlan', fn ($plan) => $plan['call'] === 'onside_kick')
        ->assertViewHas('nextAnimation', fn ($a) => $a['call'] === 'onside_kick' && $a['declared_onside'])
        ->assertSee('Onside hands team');
    expect($game->fresh()->only(['state', 'history', 'rosters']))->toBe($saved);
    app(CurrentWorld::class)->id = (int) $teams[0]->world_id;
    $state['controls'] = ['home' => 'human', 'away' => 'cpu'];
    $game->update(['state' => $state]);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Declare onside kick');
    app(CurrentWorld::class)->id = (int) $teams[0]->world_id;
    $state = array_replace($state, ['phase' => 'scrimmage', 'down' => 4, 'spot' => 70, 'distance' => 3]);
    $game->update(['state' => $state]);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('fake_punt_run')->assertSee('fake_field_goal_pass');
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'call' => 'fake_field_goal_pass', 'offense_formation' => 'field_goal'])->assertRedirect()->assertSessionHasNoErrors();
    $play = $game->fresh()->history[0];
    expect($play['design'])->toBe('fake_field_goal_pass');
    expect(collect($play['animation']['players'])->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'QB')['id'])->toBe($rosters['home']['players']['P']['id']);
});
