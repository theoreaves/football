<?php

use App\Models\Exhibition;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionBoxScore;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\OffensiveOptions;
use App\Services\Simulation\PlayerRatings;

function offensiveOptionRosters(): array
{
    $rosters = [];
    $roles = ['QB', 'RB', 'WR1', 'WR2', 'WR3', 'TE', 'C', 'LG', 'RG', 'LT', 'RT', 'DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3', 'CB1', 'CB2', 'S1', 'S2', 'K', 'P'];
    foreach (['home', 'away'] as $index => $side) {
        foreach ($roles as $i => $role) {
            $rosters[$side]['players'][$role] = ['id' => $index * 100 + $i + 1, 'name' => $side.' '.$role, 'number' => $i + 1, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70)];
        }
    }

    return $rosters;
}

function offensiveOptionState(int $seed = 42): array
{
    $state = app(ExhibitionEngine::class)->initial(900, $seed, false);
    $state['rules']['penalties'] = false;
    $state['rules']['injuries'] = false;

    return $state;
}

test('every offensive formation concept is accepted by the engine call whitelist', function () {
    $offered = array_values(array_unique(array_merge(...array_values(OffensiveOptions::FORMATIONS))));
    expect(array_values(array_diff($offered, ExhibitionEngine::OFFENSE)))->toBe([]);
    foreach (['hitch', 'curl', 'te_stick', 'drag', 'rb_flat', 'comeback', 'dig', 'out', 'te_seam', 'post', 'corner', 'fade', 'wheel'] as $route) {
        expect(ExhibitionEngine::callsForState(['phase' => 'scrimmage']))->toContain($route);
    }
});

test('new concepts resolve deterministically in every offered formation with bounded ordered animation paths', function () {
    $engine = app(ExhibitionEngine::class);
    foreach (OffensiveOptions::FORMATIONS as $formation => $calls) {
        foreach (array_intersect($calls, array_keys(OffensiveOptions::ALIASES)) as $call) {
            $state = offensiveOptionState();
            $result = $engine->resolve($state, offensiveOptionRosters(), $call, 'man_to_man', $formation);
            expect($result)->toBe($engine->resolve($state, offensiveOptionRosters(), $call, 'man_to_man', $formation));
            expect($result['play']['design'])->toBe($call)->and($result['play']['call'])->toBe(OffensiveOptions::canonical($call));
            foreach (array_merge(array_column($result['play']['animation']['players'], 'path'), [$result['play']['animation']['ball']]) as $path) {
                $last = -1;
                foreach ($path as [$time, $x, $y, $z]) {
                    expect($time)->toBeGreaterThan($last);
                    expect(is_finite($x) && is_finite($y) && is_finite($z) && $x >= 0 && $x <= 120 && $y >= 0 && $z >= 0 && $z <= 53.33)->toBeTrue();
                    $last = $time;
                }
            }
        }
    }
    expect(fn () => $engine->resolve(offensiveOptionState(), offensiveOptionRosters(), 'qb_sneak', 'man_to_man', 'empty'))->toThrow(LogicException::class);
});

test('designed QB runs keep the ball and receive rushing credit rather than sacks or passing attempts', function (string $call, string $formation) {
    $result = app(ExhibitionEngine::class)->resolve(offensiveOptionState(), offensiveOptionRosters(), $call, 'man_to_man', $formation, devForceResult: 'big_play');
    $play = $result['play'];
    expect($play['carrier'])->toBe('QB')->and($play['qb_designed_run'])->toBeTrue()->and($play['scramble'])->toBeFalse();
    expect($play['summary'])->toContain('designed run')->not->toContain('hands off');
    expect(collect($play['animation']['ballHolders'])->contains(fn ($holder) => $holder[2] === 'RB'))->toBeFalse();
    $score = app(ExhibitionBoxScore::class)->build(new Exhibition(['state' => $result['state'], 'rosters' => offensiveOptionRosters(), 'history' => [$play]]));
    expect($score['players']['home']['QB']['rushes'])->toBe(1)->and($score['players']['home']['QB']['rushing_yards'])->toBe($play['gain']);
    expect($score['players']['home']['QB']['pass_attempts'])->toBe(0)->and($score['players']['home']['RB']['rushes'])->toBe(0);
    expect($play['animation']['timing_version'])->toBe('rushing-v1');
})->with([['qb_sneak', 'goal_line'], ['qb_draw', 'empty'], ['qb_power', 'pistol'], ['qb_sweep', 'shotgun']]);

test('CPU selects legal concepts and QB run preference respects quarterback athleticism', function () {
    $coach = app(CpuCoach::class);
    $counts = ['off' => 0, 'athlete' => 0, 'slow' => 0];
    foreach (range(1, 1000) as $seed) {
        foreach (['off' => [0, 95], 'athlete' => [100, 95], 'slow' => [100, 30]] as $label => [$preference, $speed]) {
            $state = offensiveOptionState($seed);
            $state['distance'] = 5;
            $state['coaching']['home']['qb_run'] = $preference;
            $rosters = offensiveOptionRosters();
            $rosters['home']['players']['QB']['ratings']['speed'] = $speed;
            $rosters['home']['players']['QB']['ratings']['acceleration'] = $speed;
            $plan = $coach->offense($state, $rosters);
            expect($plan)->toBe($coach->offense($state, $rosters));
            expect(OffensiveOptions::FORMATIONS[$plan['formation']])->toContain($plan['call']);
            $counts[$label] += (int) OffensiveOptions::quarterbackRun($plan['call']);
        }
    }
    expect($counts['off'])->toBe(0)->and($counts['slow'])->toBe(0)->and($counts['athlete'])->toBeGreaterThan(50);
});

test('human callers see the expanded catalog and cannot submit a QB sneak from empty', function () {
    $this->withoutVite();
    $world = \App\Models\World::create(['name' => 'Expanded offense']);
    openFootballSave($world);
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    $teams = \App\Models\Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 900])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    app(\App\Support\CurrentWorld::class)->id = $world->id;
    $state = array_replace($game->state, offensiveOptionState());
    $state['coin_toss']['pending'] = false;
    $game->update(['state' => $state]);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Empty')->assertSee('Bunch')->assertSee('Goal line')->assertSee('QB draw')->assertSee('data-offensive-options', false);
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'call' => 'qb_sneak', 'offense_formation' => 'empty', 'defense' => 'man_to_man'])->assertSessionHasErrors('call');
    app(\App\Support\CurrentWorld::class)->id = $world->id;
    expect($game->fresh()->state['version'])->toBe(0);
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'call' => 'qb_draw', 'offense_formation' => 'empty', 'defense' => 'man_to_man'])->assertRedirect();
    app(\App\Support\CurrentWorld::class)->id = $world->id;
    $game->refresh();
    expect($game->history[0]['carrier'])->toBe('QB')->and($game->history[0]['design'])->toBe('qb_draw');
});
