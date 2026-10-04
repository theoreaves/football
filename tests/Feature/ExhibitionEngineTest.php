<?php

use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PlayerRatings;

function engineRosters(int $rating = 70): array
{
    $rosters = [];
    foreach (['home', 'away'] as $side) {
        $roles = ['QB', 'RB', 'WR1', 'WR2', 'WR3', 'TE', 'C', 'LG', 'RG', 'LT', 'RT', 'DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3', 'CB1', 'CB2', 'S1', 'S2', 'K', 'P'];
        foreach ($roles as $i => $role) {
            $rosters[$side]['players'][$role] = ['id' => ($side === 'home' ? 0 : 24) + $i + 1, 'name' => $role, 'number' => $i + 1, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, $rating)];
        }
        $rosters[$side]['year'] = 2026;
    }

    return $rosters;
}

function engineOutcome(string $call, string $outcome, array $changes = []): array
{
    $engine = app(ExhibitionEngine::class);
    for ($seed = 1; $seed < 5000; $seed++) {
        $state = array_merge($engine->initial(180, $seed), $changes);
        $result = $engine->resolve($state, engineRosters(), $call, 'balanced');
        if ($result['play']['outcome'] === $outcome) {
            return $result;
        }
    }
    throw new RuntimeException('No seed produced '.$outcome);
}

test('each offensive call has deterministic results and valid animation tracks', function () {
    $engine = app(ExhibitionEngine::class);
    foreach (ExhibitionEngine::OFFENSE as $call) {
        foreach (['home', 'away'] as $side) {
            $state = array_merge($engine->initial(180, 42), ['possession' => $side]);
            $result = $engine->resolve($state, engineRosters(), $call, 'blitz');
            expect($result)->toBe($engine->resolve($state, engineRosters(), $call, 'blitz'));
            expect($result['state']['version'])->toBe(1);
            $animation = $result['play']['animation'];
            expect($animation['players'])->toHaveCount(22);
            foreach (array_merge(array_column($animation['players'], 'path'), [$animation['ball']]) as $path) {
                $last = -1;
                foreach ($path as [$t, $x, $y, $z]) {
                    expect($t)->toBeGreaterThan($last);
                    expect($x >= 0 && $x <= 120 && $y >= 0 && $z >= 0 && $z <= 53.33)->toBeTrue();
                    $last = $t;
                }
            }
        }
    }
});

test('touchdowns first downs sacks incompletions and turnovers advance correctly', function () {
    $td = engineOutcome('inside_run', 'touchdown', ['spot' => 99, 'distance' => 1]);
    expect($td['state']['home_score'])->toBe(7)->and($td['state']['possession'])->toBe('away')->and($td['state']['spot'])->toBe(25);
    $incomplete = engineOutcome('slant', 'incomplete', ['down' => 4, 'spot' => 60, 'distance' => 5]);
    expect($incomplete['play']['gain'])->toBe(0)->and($incomplete['state']['possession'])->toBe('away')->and($incomplete['state']['spot'])->toBe(40);
    $sack = engineOutcome('slant', 'sack');
    expect($sack['play']['gain'])->toBeLessThan(0)->and($sack['state']['distance'])->toBeGreaterThan(10);
    $pick = engineOutcome('deep_pass', 'interception');
    expect($pick['state']['possession'])->toBe('away')->and($pick['state']['spot'])->toBe(100 - 25 - $pick['play']['gain'])->and($pick['state']['stats']['home']['turnovers'])->toBe(1);
    $fumble = engineOutcome('inside_run', 'fumble');
    expect($fumble['state']['possession'])->toBe('away')->and($fumble['state']['stats']['home']['turnovers'])->toBe(1);
    $first = engineOutcome('slant', 'tackle', ['distance' => 1]);
    expect($first['state']['down'])->toBe(1)->and($first['state']['distance'])->toBe(min(10, 100 - $first['state']['spot']));
});

test('special teams safety halftime and final boundaries are handled', function () {
    $engine = app(ExhibitionEngine::class);
    $punt = $engine->resolve(array_merge($engine->initial(180, 4), ['spot' => 90]), engineRosters(), 'punt', 'balanced');
    expect($punt['state']['possession'])->toBe('away')->and($punt['state']['spot'])->toBe(20);
    $good = engineOutcome('field_goal', 'field_goal_good', ['spot' => 80]);
    expect($good['state']['home_score'])->toBe(3)->and($good['state']['spot'])->toBe(25);
    $miss = engineOutcome('field_goal', 'field_goal_missed', ['spot' => 30]);
    expect($miss['state']['home_score'])->toBe(0)->and($miss['state']['spot'])->toBe(77);
    $safety = engineOutcome('slant', 'safety', ['spot' => 1]);
    expect($safety['state']['away_score'])->toBe(2)->and($safety['state']['possession'])->toBe('away');
    $half = $engine->resolve(array_merge($engine->initial(180, 42), ['quarter' => 2, 'clock' => 1]), engineRosters(), 'inside_run', 'balanced');
    expect($half['state']['quarter'])->toBe(3)->and($half['state']['clock'])->toBe(180)->and($half['state']['possession'])->toBe('away')->and($half['state']['spot'])->toBe(25);
    $final = $engine->resolve(array_merge($engine->initial(180, 42), ['quarter' => 4, 'clock' => 1]), engineRosters(), 'inside_run', 'balanced');
    expect($final['state']['status'])->toBe('final');
    expect(fn () => $engine->resolve($final['state'], engineRosters(), 'slant', 'balanced'))->toThrow(LogicException::class);
});

test('complete games reach final and stronger matchups improve run production', function () {
    $engine = app(ExhibitionEngine::class);
    foreach ([180, 900] as $length) {
        $state = $engine->initial($length, 123);
        for ($i = 0; $i < 1000 && $state['status'] !== 'final'; $i++) {
            $call = $state['down'] === 4 ? ($state['spot'] > 65 ? 'field_goal' : 'punt') : ExhibitionEngine::OFFENSE[$i % 4];
            $state = $engine->resolve($state, engineRosters(), $call, ExhibitionEngine::DEFENSE[$i % 4])['state'];
            expect($state['spot'] >= 0 && $state['spot'] <= 100 && $state['down'] >= 1 && $state['down'] <= 4)->toBeTrue();
        }
        expect($state['status'])->toBe('final')->and($state['clock'])->toBe(0);
    }
    $strong = engineRosters();
    $weak = engineRosters();
    foreach ($strong['home']['players'] as &$player) {
        $player['ratings'] = array_fill_keys(PlayerRatings::FIELDS, 95);
    } unset($player);
    foreach ($weak['home']['players'] as &$player) {
        $player['ratings'] = array_fill_keys(PlayerRatings::FIELDS, 15);
    } unset($player);
    $better = 0;
    $worse = 0;
    for ($seed = 1; $seed <= 100; $seed++) {
        $state = $engine->initial(180, $seed);
        $better += $engine->resolve($state, $strong, 'outside_run', 'balanced')['play']['gain'];
        $worse += $engine->resolve($state, $weak, 'outside_run', 'balanced')['play']['gain'];
    }
    expect($better)->toBeGreaterThan($worse + 500);
});

test('the ball and tackler finish at the engine dead-ball spot for both directions', function () {
    $engine = app(ExhibitionEngine::class);
    foreach (['home', 'away'] as $side) {
        foreach (['inside_run', 'outside_run', 'slant', 'deep_pass'] as $call) {
            for ($seed = 1; $seed <= 60; $seed++) {
                $state = array_merge($engine->initial(180, $seed), ['possession' => $side]);
                $play = $engine->resolve($state, engineRosters(), $call, 'balanced')['play'];
                $animation = $play['animation'];
                $ball = end($animation['ball']);
                $spot = $state['spot'] + ($play['outcome'] === 'incomplete' ? $play['target'] : $play['gain']);
                expect($ball[1])->toBe($side === 'home' ? 10 + $spot : 110 - $spot);
                if ($play['outcome'] === 'incomplete') {
                    expect($ball[2])->toBe(0);

                    continue;
                }
                $role = $play['outcome'] === 'interception' ? 'CB1' : $play['carrier'];
                $player = collect($animation['players'])->first(fn ($player) => $player['role'] === $role);
                $end = end($player['path']);
                expect($ball[1])->toBe($end[1])->and($ball[3])->toBe($end[3]);
            }
        }
    }
});

test('pass depths and formation alignments differ and formations affect matchups', function () {
    $engine = app(ExhibitionEngine::class);
    foreach (['short_pass' => [2, 7], 'medium_pass' => [10, 20], 'deep_pass' => [18, 35]] as $call => [$min, $max]) {
        $seen = false;
        for ($seed = 1; $seed < 30; $seed++) {
            $play = $engine->resolve($engine->initial(180, $seed), engineRosters(), $call, 'balanced', 'spread', 'two_high')['play'];
            if ($play['carrier'] !== 'WR1') {
                continue;
            }
            $seen = true;
            expect($play['target'])->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
            $qb = collect($play['animation']['players'])->firstWhere('role', 'QB');
            $wr = collect($play['animation']['players'])->firstWhere('role', 'WR1');
            $safety = collect($play['animation']['players'])->firstWhere('role', 'S1');
            expect($qb['path'][0][1])->toBe(30)->and($wr['path'][0][3])->toBe(4)->and($safety['path'][0][3])->toBe(16);
        }
        expect($seen)->toBeTrue();
    }
    $state = $engine->initial(180, 99);
    $singleback = $engine->resolve($state, engineRosters(), 'inside_run', 'balanced', 'singleback', 'single_high')['play'];
    $spread = $engine->resolve($state, engineRosters(), 'inside_run', 'balanced', 'spread', 'single_high')['play'];
    expect($singleback['gain'])->toBeGreaterThan($spread['gain']);
    expect(collect($singleback['animation']['players'])->firstWhere('role', 'QB')['path'][0][1])->toBe(33);
    expect(fn () => $engine->resolve($state, engineRosters(), 'short_pass', 'balanced', 'invalid'))->toThrow(LogicException::class);
});
