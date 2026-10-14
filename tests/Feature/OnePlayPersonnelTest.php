<?php

use App\Models\Exhibition;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\ExhibitionBoxScore;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GamePersonnel;
use App\Services\Simulation\OnePlayPersonnel;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
    $this->withoutMiddleware(\App\Http\Middleware\SetCurrentWorld::class);
    $world = World::create(['name' => 'One play']);
    openFootballSave($world);
    app(CurrentWorld::class)->id = $world->id;
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    $teams = Team::all();
    $this->teams = $teams;
    $this->rosters = ['home' => app(RosterBuilder::class)->build($teams[0]), 'away' => app(RosterBuilder::class)->build($teams[1])];
    $this->state = app(ExhibitionEngine::class)->initial(900, 42, false);
    $this->state['rules'] = ['penalties' => false, 'injuries' => false];
    $this->state['controls'] = ['home' => 'human', 'away' => 'human'];
    $this->game = Exhibition::create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'rosters' => $this->rosters, 'state' => $this->state, 'history' => []]);
});

test('one play personnel preview follows formation rotation and is read only', function () {
    $state = $this->state;
    $state['personnel_usage']['home']['rb_snaps'] = 4;
    $this->game->update(['state' => $state]);
    $before = $this->game->fresh()->toArray();
    $response = $this->postJson(route('exhibitions.personnel', $this->game), ['version' => 0, 'call' => 'inside_run', 'offense_formation' => 'singleback', 'defense_formation' => 'nickel'])->assertOk();
    $home = $response->json('teams.home.players');
    $away = $response->json('teams.away.players');
    expect($home)->toHaveCount(11)->toHaveKey('TE2')->not->toHaveKey('WR3');
    expect($away)->toHaveCount(11)->toHaveKey('CB3')->not->toHaveKey('LB3');
    expect($home['RB']['id'])->toBe(app(GamePersonnel::class)->active($this->rosters, $state, 'inside_run', 'singleback')['home']['players']['RB']['id']);
    expect($this->game->fresh()->toArray())->toBe($before);
    $this->get(route('exhibitions.show', $this->game))->assertOk()->assertSee('data-personnel-url', false);
});

test('one play overrides move players across positions preserve stats identity and expire after the snap', function () {
    $lineman = $this->rosters['home']['players']['DT1'];
    $receiver = $this->rosters['away']['players']['WR1'];
    $state = $this->state;
    $state['game_lineup']['home']['RB'] = $this->rosters['home']['players']['RB']['id'];
    $state = app(OnePlayPersonnel::class)->apply($state, $this->rosters, ['home' => ['RB' => $lineman['id']], 'away' => ['S1' => $receiver['id']]], 'inside_run', 'shotgun', 'base_4_3');
    $active = app(GamePersonnel::class)->active($this->rosters, $state, 'inside_run', 'shotgun');
    expect($active['home']['players']['RB']['ratings'])->toBe($lineman['ratings']);
    expect($active['away']['players']['S1']['id'])->toBe($receiver['id']);
    foreach ($active as $unit) {
        $ids = array_column($unit['players'], 'id');
        expect(count(array_unique($ids)))->toBe(count($ids));
    }
    $engine = app(ExhibitionEngine::class);
    $result = $engine->resolve($state, $this->rosters, 'inside_run', 'zone');
    expect($result)->toBe($engine->resolve($state, $this->rosters, 'inside_run', 'zone'));
    expect($result['state'])->not->toHaveKey('_snap_lineup');
    expect($result['play']['after'])->not->toHaveKey('_snap_lineup');
    expect($result['play']['before']['_snap_lineup']['home']['RB'])->toBe($lineman['id']);
    expect(collect($result['play']['animation']['players'])->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'RB')['id'])->toBe($lineman['id']);
    expect(app(GamePersonnel::class)->active($this->rosters, $result['state'])['home']['players']['RB']['id'])->toBe($state['game_lineup']['home']['RB']);
    $this->game->update(['state' => $result['state'], 'history' => [$result['play']]]);
    $score = app(ExhibitionBoxScore::class)->build($this->game);
    $rows = collect($score['players']['home']);
    expect($rows->firstWhere('id', $lineman['id'])['rushes'])->toBe(1);
});

test('one play validation rejects duplicate inactive injured tired foreign and CPU assignments', function () {
    $service = app(OnePlayPersonnel::class);
    $rb = $this->rosters['home']['players']['RB']['id'];
    foreach ([['home' => ['RB' => $rb, 'WR1' => $rb]], ['home' => ['WR4' => $rb]], ['home' => ['RB' => $this->rosters['away']['players']['RB']['id']]]] as $choices) {
        expect(fn () => $service->apply($this->state, $this->rosters, $choices, 'inside_run', 'shotgun', 'base_4_3'))->toThrow(\Illuminate\Validation\ValidationException::class);
    }
    foreach (['injured', 'tired', 'cpu'] as $case) {
        $state = $this->state;
        if ($case === 'injured') {
            $state['injuries']['home'][$rb] = ['return_snap' => null];
        } elseif ($case === 'tired') {
            $state['fatigue']['home'][$rb] = 65;
        } else {
            $state['controls']['home'] = 'cpu';
        }
        expect(fn () => $service->apply($state, $this->rosters, ['home' => ['RB' => $rb]], 'inside_run', 'shotgun', 'base_4_3'))->toThrow(\Illuminate\Validation\ValidationException::class);
    }
    $this->postJson(route('exhibitions.play', $this->game), ['version' => 0, 'call' => 'inside_run', 'defense' => 'zone', 'one_play' => ['home' => ['nonsense' => $rb]]])->assertUnprocessable();
    $this->postJson(route('exhibitions.personnel', $this->game), ['version' => 1])->assertConflict();
    expect($this->game->fresh()->state['version'])->toBe(0);
});

test('HTTP play captures replacements while next play and penalty alternatives discard them', function () {
    $rb = collect($this->rosters['home']['pool'])->first(fn ($p) => $p['position'] === 'RB' && $p['id'] !== $this->rosters['home']['players']['RB']['id']);
    $this->post(route('exhibitions.play', $this->game), ['version' => 0, 'call' => 'inside_run', 'defense' => 'zone', 'one_play' => ['home' => ['RB' => $rb['id']]]])->assertRedirect();
    $game = $this->game->fresh();
    expect($game->state)->not->toHaveKey('_snap_lineup');
    expect(collect($game->history[0]['animation']['players'])->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'RB')['id'])->toBe($rb['id']);
    $state = $this->state;
    $state['rules']['penalties'] = true;
    $state = app(OnePlayPersonnel::class)->apply($state, $this->rosters, ['home' => ['RB' => $rb['id']]], 'inside_run', 'shotgun', 'base_4_3');
    config(['app.debug' => true]);
    app()->detectEnvironment(fn () => 'local');
    $flag = app(ExhibitionEngine::class)->resolve($state, $this->rosters, 'inside_run', 'zone', devForceResult: 'flag');
    expect($flag['state'])->not->toHaveKey('_snap_lineup');
    expect($flag['play']['penalty_options'])->toHaveKeys(['accept', 'decline']);
    foreach ($flag['play']['penalty_options'] ?? [] as $option) {
        expect($option['state'])->not->toHaveKey('_snap_lineup');
        expect($option['play']['after'])->not->toHaveKey('_snap_lineup');
    }
});
