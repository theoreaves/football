<?php

use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\ExhibitionBoxScore;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GamePersonnel;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
    $world = World::create(['name' => 'Rotation']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
    app(CurrentWorld::class)->id = $world->id;
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    $teams = Team::all();
    $this->teams = $teams;
    $this->rosters = ['home' => app(RosterBuilder::class)->build($teams[0]), 'away' => app(RosterBuilder::class)->build($teams[1])];
    $this->state = app(ExhibitionEngine::class)->initial(900, 42, false);
    $this->state['rules'] = ['penalties' => false, 'injuries' => false];
});

test('RB workload schedules short stretches with balanced committee and workhorse shares', function () {
    $service = app(GamePersonnel::class);
    $backs = collect($this->rosters['home']['pool'])->where('position', 'RB')->sortBy(fn ($p) => (int) preg_replace('/\D/', '', $p['depth']))->values();
    expect($backs->count())->toBeGreaterThanOrEqual(3);
    foreach ([0 => [10, 9, 1], 50 => [13, 6, 1], 100 => [16, 3, 1]] as $workload => $expected) {
        $state = $this->state;
        $state['coaching']['home']['rb_workload'] = $workload;
        $ids = [];
        for ($snap = 0; $snap < 20; $snap++) {
            $state['personnel_usage']['home']['offensive_snaps'] = $snap;
            $active = $service->active($this->rosters, $state, 'inside_run', 'shotgun');
            $ids[] = $active['home']['players']['RB']['id'];
            expect($active)->toBe($service->active($this->rosters, $state, 'inside_run', 'shotgun'));
            $selected = array_column($active['home']['players'], 'id');
            expect(count(array_unique($selected)))->toBe(count($selected));
        }
        foreach ($expected as $rank => $count) {
            expect(count(array_filter($ids, fn ($id) => $id === $backs[$rank]['id'])))->toBe($count);
        }
        expect(array_slice($ids, 0, 4))->toBe(array_fill(0, 4, $backs[0]['id']));
    }
});

test('manual RB and specialists override rotation and injury or fatigue prevents unsafe selection', function () {
    $rosters = $this->rosters;
    $rb = $rosters['home']['players']['RB']['id'];
    $backs = collect($rosters['home']['pool'])->where('position', 'RB')->sortBy(fn ($p) => (int) preg_replace('/\D/', '', $p['depth']))->values();
    $backup = $backs[1]['id'];
    $state = $this->state;
    $state['personnel_usage']['home']['offensive_snaps'] = 4;
    $service = app(GamePersonnel::class);
    expect($service->active($rosters, $state, 'inside_run', 'shotgun')['home']['players']['RB']['id'])->toBe($backup);
    $manual = $state;
    $manual['game_lineup']['home']['RB'] = $rb;
    expect($service->active($rosters, $manual, 'inside_run', 'shotgun')['home']['players']['RB']['id'])->toBe($rb);
    $rosters['home']['specialists']['third_down_back'] = $backs[2]['id'];
    $state['down'] = 3;
    expect($service->active($rosters, $state, 'inside_run', 'shotgun')['home']['players']['RB']['id'])->toBe($backs[2]['id']);
    $state['down'] = 1;
    $state['injuries']['home'][$backup] = ['return_snap' => null];
    expect($service->active($rosters, $state, 'inside_run', 'shotgun')['home']['players']['RB']['id'])->not->toBe($backup);
    unset($state['injuries']);
    $state['fatigue']['home'][$backup] = 80;
    expect($service->active($rosters, $state, 'inside_run', 'shotgun')['home']['players']['RB']['id'])->not->toBe($backup);
});

test('formation packages put actual TE fullback and receiver personnel on the field with eligible targets', function () {
    $engine = app(ExhibitionEngine::class);
    foreach (['singleback' => ['TE2'], 'i_form' => ['FB'], 'goal_line' => ['FB', 'TE2'], 'empty' => ['WR4']] as $formation => $added) {
        $state = $this->state;
        $result = $engine->resolve($state, $this->rosters, 'short_pass', 'zone', $formation);
        $players = collect($result['play']['animation']['players'])->where('side', 'home')->keyBy('role');
        expect($players)->toHaveCount(11);
        foreach ($added as $role) {
            expect($players->has($role))->toBeTrue();
            $person = collect($this->rosters['home']['pool'])->firstWhere('id', $players[$role]['id']);
            expect($person['position'])->toBeIn(match ($role) {
                'TE2' => ['TE'], 'FB' => ['FB', 'RB'], default => ['WR']
            });
        }
        expect(array_unique($players->pluck('id')->all()))->toHaveCount(11);
        if ($formation === 'empty') {
            expect($players->has('RB'))->toBeFalse();
        }
        if (in_array($formation, ['singleback', 'i_form', 'goal_line'], true)) {
            expect($players->has('WR3'))->toBeFalse();
        }
        if ($result['play']['receiver_role']) {
            expect($players->has($result['play']['receiver_role']))->toBeTrue();
        }
    }
});

test('package receivers get target and reception credit and saved identities survive older roster snapshots', function () {
    $engine = app(ExhibitionEngine::class);
    $result = null;
    for ($seed = 1; $seed < 300; $seed++) {
        $state = array_replace($this->state, ['seed' => $seed]);
        $attempt = $engine->resolve($state, $this->rosters, 'short_pass', 'man_to_man', 'singleback');
        if ($attempt['play']['carrier'] === 'TE2' && $attempt['play']['outcome'] === 'tackle') {
            $result = $attempt;
            break;
        }
    }
    expect($result)->not->toBeNull();
    $oldRoster = $this->rosters;
    unset($oldRoster['home']['players']['TE2']);
    $game = new Exhibition(['state' => $result['state'], 'rosters' => $oldRoster, 'history' => [$result['play']]]);
    $score = app(ExhibitionBoxScore::class)->build($game);
    $receiver = collect($result['play']['animation']['players'])->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'TE2');
    $stats = collect($score['players']['home'])->firstWhere('id', $receiver['id']);
    expect($stats['targets'])->toBe(1)->and($stats['receptions'])->toBe(1)->and($stats['receiving_yards'])->toBe($result['play']['gain']);
});

test('receiver breathers leave manual selections and starting defensive backs stable', function () {
    $state = $this->state;
    $state['personnel_usage']['home']['offensive_snaps'] = 8;
    $service = app(GamePersonnel::class);
    $active = $service->active($this->rosters, $state, 'short_pass', 'shotgun');
    $starter = $this->rosters['home']['players']['WR1']['id'];
    expect($active['home']['players']['WR1']['id'])->not->toBe($starter);
    $state['game_lineup']['home']['WR1'] = $starter;
    expect($service->active($this->rosters, $state, 'short_pass', 'shotgun')['home']['players']['WR1']['id'])->toBe($starter);
    foreach (['CB1', 'CB2', 'S1', 'S2'] as $role) {
        expect($active['away']['players'][$role]['id'])->toBe($this->rosters['away']['players'][$role]['id']);
    }
});

test('missing or unavailable supplemental personnel fall back to eleven distinct available players', function () {
    $rosters = $this->rosters;
    $rosters['home']['pool'] = array_values(array_filter($rosters['home']['pool'], fn ($p) => ! ($p['position'] === 'TE' && $p['depth'] !== 'TE1')));
    unset($rosters['home']['players']['TE2']);
    $result = app(ExhibitionEngine::class)->resolve($this->state, $rosters, 'short_pass', 'zone', 'singleback');
    $players = collect($result['play']['animation']['players'])->where('side', 'home');
    expect($players)->toHaveCount(11)->and($players->pluck('role')->all())->not->toContain('TE2');
    expect(array_unique($players->pluck('id')->all()))->toHaveCount(11);
});

test('empty formations advance offensive usage without consuming an RB rotation slot', function () {
    $state = $this->state;
    $state['personnel_usage']['home'] = ['offensive_snaps' => 100, 'rb_snaps' => 4];
    $result = app(ExhibitionEngine::class)->resolve($state, $this->rosters, 'qb_draw', 'run_stop', 'empty');
    expect($result['state']['personnel_usage']['home']['offensive_snaps'])->toBe(101);
    expect($result['state']['personnel_usage']['home']['rb_snaps'])->toBe(4);
    $next = $state;
    $next['personnel_usage']['home']['offensive_snaps'] = 101;
    expect(app(GamePersonnel::class)->active($this->rosters, $next, 'inside_run', 'shotgun')['home']['players']['RB']['id'])
        ->not->toBe($this->rosters['home']['players']['RB']['id']);
});

test('hurry up retains both formations and identities despite rotation specialist and fatigue changes', function () {
    $engine = app(ExhibitionEngine::class);
    $first = $engine->resolve($this->state, $this->rosters, 'inside_run', 'man_to_man', 'singleback', 'nickel', 'hurry');
    $state = $first['state'];
    expect($state)->toHaveKey('on_field');
    $ids = $state['on_field']['players'];
    $state['fatigue']['home'][$ids['home']['RB']] = 90;
    $state['personnel_usage']['home'] = ['rb_snaps' => 5, 'offensive_snaps' => 8];
    $state['down'] = 3;
    $state['distance'] = 2;
    $second = $engine->resolve($state, $this->rosters, 'inside_run', 'zone', 'singleback', 'base_3_4', 'hurry');
    expect($second['play']['offense_formation'])->toBe('singleback')->and($second['play']['defense_formation'])->toBe('nickel')
        ->and($second['play']['animation']['no_huddle'])->toBeTrue();
    foreach ($second['play']['animation']['players'] as $track) {
        expect($track['id'])->toBe($ids[$track['side']][$track['role']]);
    }
    $timeout = $engine->timeout($first['state'], $this->rosters, 'home');
    expect($timeout['state'])->not->toHaveKey('on_field');
});
