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
