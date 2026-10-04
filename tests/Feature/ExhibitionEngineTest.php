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
        $state = array_merge($engine->initial(180, $seed, false), $changes);
        $result = $engine->resolve($state, engineRosters(), $call, 'balanced');
        if ($result['play']['outcome'] === $outcome) {
            return $result;
        }
    }
    throw new RuntimeException('No seed produced '.$outcome);
}

test('each offensive call has deterministic results and valid animation tracks', function () {
    $engine = app(ExhibitionEngine::class);
    foreach (array_diff(ExhibitionEngine::OFFENSE, ['kickoff', 'extra_point']) as $call) {
        foreach (['home', 'away'] as $side) {
            $state = array_merge($engine->initial(180, 42, false), ['possession' => $side]);
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
    expect($td['state']['home_score'])->toBe(6)->and($td['state']['phase'])->toBe('extra_point')->and($td['state']['possession'])->toBe('home');
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
    $punt = $engine->resolve(array_merge($engine->initial(180, 4, false), ['spot' => 90]), engineRosters(), 'punt', 'balanced');
    expect($punt['state']['possession'])->toBe('away')->and($punt['state']['spot'])->toBe(20);
    $good = engineOutcome('field_goal', 'field_goal_good', ['spot' => 80]);
    expect($good['state']['home_score'])->toBe(3)->and($good['state']['spot'])->toBe(35);
    $miss = engineOutcome('field_goal', 'field_goal_missed', ['spot' => 30]);
    expect($miss['state']['home_score'])->toBe(0)->and($miss['state']['spot'])->toBe(77);
    $safety = engineOutcome('slant', 'safety', ['spot' => 1]);
    expect($safety['state']['away_score'])->toBe(2)->and($safety['state']['phase'])->toBe('kickoff');
    $half = $engine->resolve(array_merge($engine->initial(180, 42, false), ['quarter' => 2, 'clock' => 1]), engineRosters(), 'inside_run', 'balanced');
    expect($half['state']['quarter'])->toBe(3)->and($half['state']['clock'])->toBe(180)->and($half['state']['possession'])->toBe('home')->and($half['state']['spot'])->toBe(35);
    $final = $engine->resolve(array_merge($engine->initial(180, 42, false), ['quarter' => 4, 'clock' => 1]), engineRosters(), 'inside_run', 'balanced');
    expect($final['state']['status'])->toBe('final');
    expect(fn () => $engine->resolve($final['state'], engineRosters(), 'slant', 'balanced'))->toThrow(LogicException::class);
});

test('complete games reach final and stronger matchups improve run production', function () {
    $engine = app(ExhibitionEngine::class);
    foreach ([180, 900] as $length) {
        $state = $engine->initial($length, 123, false);
        for ($i = 0; $i < 1000 && $state['status'] !== 'final'; $i++) {
            $call = $state['down'] === 4 ? ($state['spot'] > 65 ? 'field_goal' : 'punt') : ExhibitionEngine::OFFENSE[$i % 4];
            if (($state['phase'] ?? '') !== 'scrimmage') {
                $call = ExhibitionEngine::callsForState($state)[0];
            }
            $state = $engine->resolve($state, engineRosters(), $call, ExhibitionEngine::defensesForCall($call)[0])['state'];
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
        $state = $engine->initial(180, $seed, false);
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
                $state = array_merge($engine->initial(180, $seed, false), ['possession' => $side]);
                $state['rules']['penalties'] = false;
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
            $play = $engine->resolve($engine->initial(180, $seed, false), engineRosters(), $call, 'balanced', 'spread', 'two_high')['play'];
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
    $state = $engine->initial(180, 99, false);
    $singleback = $engine->resolve($state, engineRosters(), 'inside_run', 'balanced', 'singleback', 'single_high')['play'];
    $spread = $engine->resolve($state, engineRosters(), 'inside_run', 'balanced', 'spread', 'single_high')['play'];
    expect($singleback['gain'])->toBeGreaterThan($spread['gain']);
    expect(collect($singleback['animation']['players'])->firstWhere('role', 'QB')['path'][0][1])->toBe(33);
    expect(fn () => $engine->resolve($state, engineRosters(), 'short_pass', 'balanced', 'invalid'))->toThrow(LogicException::class);
});

test('kickoff extra point and return phases score separately including an untimed final try', function () {
    $engine = app(ExhibitionEngine::class);
    $state = $engine->initial(180, 77);
    expect($state['phase'])->toBe('kickoff')->and($state['possession'])->toBe('away');
    $kickoff = $engine->resolve($state, engineRosters(), 'kickoff', 'kickoff_return');
    expect($kickoff['state']['possession'])->toBe('home')->and($kickoff['state']['phase'])->toBe('scrimmage');
    expect(fn () => $engine->resolve($state, engineRosters(), 'slant', 'balanced'))->toThrow(LogicException::class);
    $td = engineOutcome('inside_run', 'touchdown', ['spot' => 99, 'distance' => 1, 'quarter' => 4, 'clock' => 1]);
    expect($td['state']['home_score'])->toBe(6)->and($td['state']['status'])->toBe('playing')->and($td['state']['clock'])->toBe(0);
    $try = $engine->resolve($td['state'], engineRosters(), 'extra_point', 'field_goal_block');
    expect($try['state']['home_score'])->toBeIn([6, 7])->and($try['state']['status'])->toBe('final')->and($try['state']['version'])->toBe(2);
});

test('punt returns and kick blocks have bounded tracks and phase-appropriate possession', function () {
    $engine = app(ExhibitionEngine::class);
    $seen = [];
    for ($seed = 1; $seed <= 400; $seed++) {
        foreach (['punt' => 'punt_return', 'field_goal' => 'field_goal_block', 'extra_point' => 'field_goal_block', 'kickoff' => 'kickoff_return'] as $call => $defense) {
            $state = $engine->initial(180, $seed, false);
            $state['spot'] = $call === 'kickoff' ? 35 : ($call === 'punt' ? 25 : 85);
            $state['phase'] = match ($call) {
                'extra_point' => 'extra_point', 'kickoff' => 'kickoff', default => 'scrimmage'
            };
            $play = $engine->resolve($state, engineRosters(), $call, $defense)['play'];
            $seen[$play['outcome']] = true;
            expect($play['animation']['players'])->toHaveCount(22);
            foreach ($play['animation']['ball'] as [$t,$x,$y,$z]) {
                expect($x >= 0 && $x <= 120 && $y >= 0 && $z >= 0 && $z <= 53.33)->toBeTrue();
            }
            if ($play['outcome'] === 'punt_return') {
                expect($play['after']['spot'])->toBe(100 - $play['landing'] + $play['return_yards']);
                $returner = collect($play['animation']['players'])->firstWhere('role', 'CB1');
                $path = $returner['path'];
                $ball = $play['animation']['ball'];
                expect($path[3][1])->toBe($ball[5][1]);
            }
        }
    }
    expect($seen)->toHaveKeys(['punt_return', 'field_goal_blocked', 'extra_point_good', 'extra_point_missed', 'kickoff_touchback']);
});

test('animation possession tracks distinguish passes turnovers and handoffs', function () {
    $timeline = app(\App\Services\Simulation\PlayTimeline::class);
    $base = ['before' => ['possession' => 'home', 'spot' => 25, 'distance' => 10], 'gain' => 8, 'target' => 8, 'summary' => 'Result'];
    foreach (['complete', 'incomplete', 'interception', 'fumble'] as $outcome) {
        $animation = $timeline->build($base + ['call' => 'short_pass', 'carrier' => 'WR1', 'outcome' => $outcome], engineRosters());
        expect($animation['ballHolders'][3])->toBe([2.2, null, null]);
        expect($animation['ballHolders'][4][1])->toBe($outcome === 'incomplete' ? null : ($outcome === 'interception' ? 'defense' : 'offense'));
        if ($outcome === 'fumble') {
            expect($animation['ballHolders'][5])->toBe([5.3, 'defense', 'CB1']);
        }
    }
    $run = $timeline->build($base + ['call' => 'inside_run', 'carrier' => 'RB', 'outcome' => 'tackle'], engineRosters());
    expect($run['ballHolders'])->toBe([[0, 'offense', 'C'], [.01, null, null], [.35, 'offense', 'QB'], [.6, null, null], [1, 'offense', 'RB']]);
    $special = app(\App\Services\Simulation\SpecialTeamsTimeline::class);
    foreach (['kickoff_return', 'kickoff_touchback'] as $outcome) {
        $kick = $special->build($base + ['call' => 'kickoff', 'carrier' => 'K', 'outcome' => $outcome, 'landing' => 80, 'return_yards' => $outcome === 'kickoff_return' ? 10 : 0], engineRosters());
        expect($kick['ballHolders'][0])->toBe([0, null, null]);
        expect(count($kick['ballHolders']))->toBe($outcome === 'kickoff_return' ? 2 : 1);
        if ($outcome === 'kickoff_return') {
            expect($kick['ballHolders'][1])->toBe([3.5, 'defense', 'CB1']);
        }
    }
});
