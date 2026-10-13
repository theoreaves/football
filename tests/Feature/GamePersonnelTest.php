<?php

use App\Models\LocalSetting;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GamePersonnel;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
    $save = World::create(['name' => 'Personnel']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $save->id]);
    openFootballSave($save);
    app(CurrentWorld::class)->id = $save->id;
    $this->artisan('world:seed-demo', ['world' => $save->id, '--teams' => 2])->assertSuccessful();
    $teams = Team::all();
    $this->rosters = ['home' => app(RosterBuilder::class)->build($teams[0]), 'away' => app(RosterBuilder::class)->build($teams[1])];
});

test('depth order selects backups for injuries and fatigue without changing the saved roster', function () {
    $rosters = $this->rosters;
    $qb = $rosters['home']['players']['QB'];
    $backup = collect($rosters['home']['pool'])->first(fn ($p) => $p['position'] === 'QB' && $p['id'] !== $qb['id']);
    expect($rosters['home']['pool'])->toHaveCount(53);
    $state = ['personnel_snaps' => 2, 'injuries' => ['home' => [$qb['id'] => ['return_snap' => 5]]]];
    $active = app(GamePersonnel::class)->active($rosters, $state);
    expect($active['home']['players']['QB']['id'])->toBe($backup['id']);
    $state['personnel_snaps'] = 5;
    expect(app(GamePersonnel::class)->active($rosters, $state)['home']['players']['QB']['id'])->toBe($qb['id']);
    $active = app(GamePersonnel::class)->active($rosters, ['fatigue' => ['home' => [$qb['id'] => 80]]]);
    expect($active['home']['players']['QB']['id'])->toBe($backup['id']);
    expect($rosters['home']['players']['QB'])->toBe($qb);
    $ids = array_column($active['home']['players'], 'id');
    expect(count(array_unique($ids)))->toBe(count($ids));
});

test('stamina governs fatigue and bench recovery while durability governs injury risk', function () {
    $service = app(GamePersonnel::class);
    $rosters = $this->rosters;
    $qb = $rosters['home']['players']['QB'];
    $rosters['home']['pool'] = array_map(function ($p) use ($qb) {
        $p['ratings']['stamina'] = $p['id'] === $qb['id'] ? 1 : 99;

        return $p;
    }, $rosters['home']['pool']);
    $before = app(ExhibitionEngine::class)->initial(900, 42, false);
    $before['fatigue']['home'][$qb['id']] = 20;
    $play = ['call' => 'inside_run', 'animation' => ['players' => [['id' => $qb['id'], 'side' => 'home', 'role' => 'QB', 'name' => $qb['name']]]]];
    $state = $before;
    $state['rules']['injuries'] = false;
    $out = $service->afterPlay($state, $before, $play, $rosters);
    expect($out['state']['fatigue']['home'][$qb['id']])->toBeGreaterThan(28);
    $play['animation']['players'] = [];
    $recovery = $service->afterPlay($state, $before, $play, $rosters);
    expect($recovery['state']['fatigue']['home'][$qb['id']])->toBeLessThan(20);
    $state['rules']['injuries'] = true;
    $play['animation']['players'] = [['id' => $qb['id'], 'side' => 'home', 'role' => 'QB', 'name' => $qb['name']]];
    $low = $rosters;
    $low['home']['players']['QB']['ratings']['durability'] = 1;
    $high = $rosters;
    $high['home']['players']['QB']['ratings']['durability'] = 99;
    $found = null;
    for ($seed = 1; $seed < 10000; $seed++) {
        $before['seed'] = $seed;
        $state['seed'] = $seed;
        $injured = $service->afterPlay($state, $before, $play, $low);
        if (isset($injured['state']['injuries']['home'][$qb['id']]) && ! isset($service->afterPlay($state, $before, $play, $high)['state']['injuries']['home'][$qb['id']])) {
            $found = $injured;
            break;
        }
    }
    expect($found)->not->toBeNull()->and($found['notices'])->not->toBeEmpty();
    expect($service->afterPlay($state, $before, $play, $low))->toBe($found);
    $play['no_snap'] = true;
    expect($service->afterPlay($state, $before, $play, $low)['state'])->toBe($state);
});

test('engine uses replacement identity and ratings in results and replay and persists availability', function () {
    $state = app(ExhibitionEngine::class)->initial(900, 12, false);
    $state['rules'] = ['penalties' => false, 'injuries' => false];
    $qb = $this->rosters['home']['players']['QB'];
    $state['injuries']['home'][$qb['id']] = ['name' => $qb['name'], 'type' => 'Leg injury', 'return_snap' => null, 'occurred_snap' => 0];
    $result = app(ExhibitionEngine::class)->resolve($state, $this->rosters, 'short_pass', 'zone');
    $track = collect($result['play']['animation']['players'])->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'QB');
    expect($track['id'])->not->toBe($qb['id']);
    expect($result['state']['injuries']['home'][$qb['id']]['return_snap'])->toBeNull();
    expect($result['state']['personnel_snaps'])->toBe(1);
    expect($result)->toBe(app(ExhibitionEngine::class)->resolve($state, $this->rosters, 'short_pass', 'zone'));
    $timeoutState = $result['state'];
    $timeoutState['clock_running'] = true;
    $timeout = app(ExhibitionEngine::class)->timeout($timeoutState, $this->rosters, 'home');
    expect($timeout['state']['personnel_snaps'])->toBe(1)->and($timeout['state']['fatigue'])->toBe($timeoutState['fatigue']);
});

test('fatigue reduces performance and halftime restores energy even without a snap', function () {
    $qb = $this->rosters['home']['players']['QB'];
    $state = app(ExhibitionEngine::class)->initial(900, 9, false);
    $state['quarter'] = 2;
    $state['fatigue']['home'][$qb['id']] = 50;
    $active = app(GamePersonnel::class)->active($this->rosters, $state);
    expect($active['home']['players']['QB']['id'])->toBe($qb['id'])->and($active['home']['players']['QB']['ratings']['throwing'])->toBeLessThan($qb['ratings']['throwing']);
    $after = $state;
    $after['quarter'] = 3;
    $result = app(GamePersonnel::class)->afterPlay($after, $state, ['no_snap' => true], $this->rosters);
    expect($result['state']['fatigue']['home'][$qb['id']])->toBe(0);
});

test('two point attempts count one personnel snap and injured players return after their absence', function () {
    $state = app(ExhibitionEngine::class)->initial(900, 51, false);
    $state['rules'] = ['penalties' => false, 'injuries' => false];
    $state['phase'] = 'extra_point';
    $state['personnel_snaps'] = 3;
    $qb = $this->rosters['home']['players']['QB'];
    $state['injuries']['home'][$qb['id']] = ['name' => $qb['name'], 'type' => 'Shaken up', 'return_snap' => 4, 'occurred_snap' => 0];
    $result = app(ExhibitionEngine::class)->resolve($state, $this->rosters, 'two_point_pass', 'zone');
    expect($result['state']['personnel_snaps'])->toBe(4)->and($result['state']['injuries']['home'])->not->toHaveKey($qb['id']);
    expect(implode(' ', $result['play']['personnel_notices']))->toContain('cleared to return');
    expect(app(GamePersonnel::class)->active($this->rosters, $result['state'])['home']['players']['QB']['id'])->toBe($qb['id']);
});

test('availability panel and new player ratings are rendered for captured rosters', function () {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'injuries' => 0])->assertRedirect();
    $game = \App\Models\Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($game->state['rules']['injuries'])->toBeFalse();
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Depth chart and availability')->assertSee('Backup');
    $this->get(route('teams.editor.teams.players.create', $teams[0]))->assertOk()->assertSee('Stamina')->assertSee('Durability');
});

test('running back substitutions credit saved snap identities across a midgame lineup change', function () {
    $engine = app(ExhibitionEngine::class);
    $starter = $this->rosters['home']['players']['RB'];
    $backup = collect($this->rosters['home']['pool'])->first(fn ($p) => $p['position'] === 'RB' && $p['id'] !== $starter['id']);
    $state = $engine->initial(900, 42, false);
    $state['rules']['penalties'] = false;
    $state['rules']['injuries'] = false;
    $first = $engine->resolve($state, $this->rosters, 'inside_run', 'man_to_man', devForceResult: 'big_play');
    $state = $first['state'];
    $state['game_lineup']['home']['RB'] = $backup['id'];
    $second = $engine->resolve($state, $this->rosters, 'inside_run', 'man_to_man', devForceResult: 'big_play');
    $secondTrack = collect($second['play']['animation']['players'])->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'RB');
    expect($secondTrack['id'])->toBe($backup['id']);
    $saved = app(\App\Services\Simulation\SavedPlayPersonnel::class);
    expect($saved->rosters($this->rosters, $first['play'])['home']['players']['RB']['id'])->toBe($starter['id']);
    expect($saved->rosters($this->rosters, $second['play'])['home']['players']['RB']['id'])->toBe($backup['id']);
    $game = new \App\Models\Exhibition(['state' => $second['state'], 'rosters' => $this->rosters, 'history' => [$first['play'], $second['play']]]);
    $score = app(\App\Services\Simulation\ExhibitionBoxScore::class)->build($game);
    $rows = collect($score['players']['home'])->keyBy('id');
    expect($rows[$starter['id']]['rushes'])->toBe(1)->and($rows[$backup['id']]['rushes'])->toBe(1);
    expect($rows[$starter['id']]['rushing_yards'])->toBe($first['play']['gain']);
    expect($rows[$backup['id']]['rushing_yards'])->toBe($second['play']['gain']);
    expect(collect($score['players']['home'])->sum('rushing_yards'))->toBe($score['teams']['home']['rushing_yards']);
    // Historical plays retain credit even after the current lineup changes again.
    $changed = $second['state'];
    $changed['game_lineup']['home']['RB'] = $starter['id'];
    $game->state = $changed;
    expect(app(\App\Services\Simulation\ExhibitionBoxScore::class)->build($game)['players'])->toBe($score['players']);
    $teams = Team::all();
    $game->home_team_id = $teams[0]->id;
    $game->away_team_id = $teams[1]->id;
    $game->save();
    $html = $this->get(route('exhibitions.show', $game))->assertOk()->getContent();
    preg_match('/aria-label="Players involved in this play"(.*?)data-result-ok/s', $html, $match);
    expect($match[1] ?? '')->toContain(e($backup['name']))->not->toContain('Portrait of '.e($starter['name']));

});

test('optional situational back respects down distance overrides availability and saved identities', function () {
    $rosters = $this->rosters;
    $starter = $rosters['home']['players']['RB'];
    $backup = collect($rosters['home']['pool'])->first(fn ($p) => $p['position'] === 'RB' && $p['id'] !== $starter['id']);
    $rosters['home']['specialists']['third_down_back'] = $backup['id'];
    $base = ['possession' => 'home', 'phase' => 'scrimmage', 'down' => 1, 'distance' => 10];
    $personnel = app(GamePersonnel::class);
    expect($personnel->active($rosters, $base)['home']['players']['RB']['id'])->toBe($starter['id']);
    foreach ([[3, 10], [3, 1], [1, 2], [2, 1], [4, 2]] as [$down, $distance]) {
        $state = array_replace($base, compact('down', 'distance'));
        expect($personnel->active($rosters, $state)['home']['players']['RB']['id'])->toBe($backup['id']);
    }
    $state = array_replace($base, ['down' => 3]);
    foreach ([['game_lineup' => ['home' => ['RB' => $starter['id']]]], ['injuries' => ['home' => [$backup['id'] => ['return_snap' => null]]]], ['fatigue' => ['home' => [$backup['id'] => 70]]]] as $override) {
        expect($personnel->active($rosters, $state + $override)['home']['players']['RB']['id'])->toBe($starter['id']);
    }
    expect($personnel->active($rosters, $state, 'punt')['home']['players']['RB']['id'])->toBe($starter['id']);
    expect($personnel->active($rosters, array_replace($state, ['possession' => 'away']))['home']['players']['RB']['id'])->toBe($starter['id']);
    $state = array_replace(app(ExhibitionEngine::class)->initial(900, 42, false), ['down' => 3, 'rules' => ['penalties' => false, 'injuries' => false]]);
    $a = app(ExhibitionEngine::class)->resolve($state, $rosters, 'inside_run', 'run_stop');
    expect($a)->toBe(app(ExhibitionEngine::class)->resolve($state, $rosters, 'inside_run', 'run_stop'));
    expect(collect($a['play']['animation']['players'])->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'RB')['id'])->toBe($backup['id']);
    expect($rosters['home']['players']['RB']['id'])->toBe($starter['id']);
});

test('return specialists use their own identity ratings and animation without duplicate personnel', function () {
    $rosters = $this->rosters;
    $returner = collect($rosters['away']['pool'])->firstWhere('position', 'WR');
    $rosters['away']['specialists'] = ['kick_returner' => $returner['id'], 'punt_returner' => $returner['id']];
    $engine = app(ExhibitionEngine::class);
    foreach (['punt', 'kickoff'] as $call) {
        $state = array_replace($engine->initial(900, 51, false), ['phase' => $call === 'kickoff' ? 'kickoff' : 'scrimmage', 'spot' => 20, 'rules' => ['penalties' => false, 'injuries' => false]]);
        $active = app(GamePersonnel::class)->active($rosters, $state, $call)['away']['players'];
        expect($active['CB1']['id'])->toBe($returner['id']);
        expect(array_unique(array_column($active, 'id')))->toHaveCount(count($active));
        $result = $engine->resolve($state, $rosters, $call, $call === 'punt' ? 'punt_return' : 'kickoff_return');
        $track = collect($result['play']['animation']['players'])->first(fn ($p) => $p['side'] === 'away' && $p['role'] === 'CB1');
        expect($track['id'])->toBe($returner['id'])->and($track['name'])->toBe($returner['name']);
        $state['injuries']['away'][$returner['id']] = ['return_snap' => null];
        expect(app(GamePersonnel::class)->active($rosters, $state, $call)['away']['players']['CB1']['id'])->toBe($rosters['away']['players']['CB1']['id']);
    }
});
