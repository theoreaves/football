152G<?php

use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PlayerRatings;

test('touchdown carriers cross the goal line while stats stop at it', function () {
    foreach (['home', 'away'] as $side) {
        $result = engineOutcome('inside_run', 'touchdown', ['possession' => $side, 'spot' => 99, 'distance' => 1]);
        $ball = $result['play']['animation']['ball'];
        $end = $ball[array_key_last($ball)][1];
        expect($result['play']['gain'])->toBe(1);
        expect($side === 'home' ? $end > 110 : $end < 10)->toBeTrue();
    }
});

test('red zone pass targets and turnover animations extend into the end zone', function () {
    foreach (['touchdown', 'incomplete', 'interception'] as $outcome) {
        $result = engineOutcome('deep_pass', $outcome, ['spot' => 95, 'distance' => 5]);
        expect($result['play']['target'])->toBeGreaterThan(5)->toBeLessThanOrEqual(13);
        $ball = $result['play']['animation']['ball'];
        expect(max(array_column($ball, 1)))->toBeGreaterThan(110)->toBeLessThan(120);
        if ($outcome === 'interception') {
            expect($result['state']['possession'])->toBe('away')->and($result['state']['spot'])->toBe(20);
        }
    }
});

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
        $result = $engine->resolve($state, engineRosters(), $call, 'man_to_man');
        if ($result['play']['outcome'] === $outcome) {
            return $result;
        }
    }
    throw new RuntimeException('No seed produced '.$outcome);
}

test('each offensive call has deterministic results and valid animation tracks', function () {
    $engine = app(ExhibitionEngine::class);
    foreach (ExhibitionEngine::callsForState(['phase' => 'scrimmage']) as $call) {
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
    $punt = $engine->resolve(array_merge($engine->initial(180, 4, false), ['spot' => 90]), engineRosters(), 'punt', 'man_to_man');
    expect($punt['state']['possession'])->toBe('away')->and($punt['state']['spot'])->toBe(20);
    $good = engineOutcome('field_goal', 'field_goal_good', ['spot' => 80]);
    expect($good['state']['home_score'])->toBe(3)->and($good['state']['spot'])->toBe(35);
    $miss = engineOutcome('field_goal', 'field_goal_missed', ['spot' => 30]);
    expect($miss['state']['home_score'])->toBe(0)->and($miss['state']['spot'])->toBe(77);
    $safety = engineOutcome('slant', 'safety', ['spot' => 1]);
    expect($safety['state']['away_score'])->toBe(2)->and($safety['state']['phase'])->toBe('kickoff');
    $half = $engine->resolve(array_merge($engine->initial(180, 42, false), ['quarter' => 2, 'clock' => 1, 'rules' => ['penalties' => false]]), engineRosters(), 'inside_run', 'man_to_man');
    expect($half['state']['quarter'])->toBe(3)->and($half['state']['clock'])->toBe(180)->and($half['state']['possession'])->toBe('home')->and($half['state']['spot'])->toBe(35);
    $final = $engine->resolve(array_merge($engine->initial(180, 42, false), ['quarter' => 4, 'clock' => 1, 'rules' => ['penalties' => false]]), engineRosters(), 'inside_run', 'man_to_man');
    expect($final['state']['status'])->toBe('final');
    expect(fn () => $engine->resolve($final['state'], engineRosters(), 'slant', 'man_to_man'))->toThrow(LogicException::class);
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
        $better += $engine->resolve($state, $strong, 'outside_run', 'man_to_man')['play']['gain'];
        $worse += $engine->resolve($state, $weak, 'outside_run', 'man_to_man')['play']['gain'];
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
                $play = $engine->resolve($state, engineRosters(), $call, 'man_to_man')['play'];
                $animation = $play['animation'];
                $ball = end($animation['ball']);
                $spot = $state['spot'] + ($play['outcome'] === 'incomplete' ? $play['target'] : $play['gain']);

                if ($play['throwaway'] ?? false) {
                    // A throwaway deliberately travels toward the sideline,
                    // not to the intended receiver's target yard line.
                    expect($play['outcome'])->toBe('incomplete');
                    expect($ball[2])->toBe(0);

                    continue;
                }

                expect($ball[1])->toBe(
                    $side === 'home' ? 10 + $spot : 110 - $spot,
                    "Side: {$side}, Call: {$call}, Seed: {$seed}, "
                    ."Outcome: {$play['outcome']}, "
                    ."Throwaway: ".json_encode($play['throwaway'] ?? false).", "
                    ."Defensive return: ".json_encode($play['defensive_return'] ?? false)
                );


//                expect($ball[1])->toBe($side === 'home' ? 10 + $spot : 110 - $spot);
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
            $play = $engine->resolve($engine->initial(180, $seed, false), engineRosters(), $call, 'man_to_man', 'spread', 'two_high')['play'];
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
    $singleback = $engine->resolve($state, engineRosters(), 'inside_run', 'man_to_man', 'singleback', 'single_high')['play'];
    $spread = $engine->resolve($state, engineRosters(), 'inside_run', 'man_to_man', 'spread', 'single_high')['play'];
    expect($singleback['gain'])->toBeGreaterThan($spread['gain']);
    expect(collect($singleback['animation']['players'])->firstWhere('role', 'QB')['path'][0][1])->toBe(33);
    expect(fn () => $engine->resolve($state, engineRosters(), 'short_pass', 'man_to_man', 'invalid'))->toThrow(LogicException::class);
});

test('kickoff extra point and return phases score separately including an untimed final try', function () {
    $engine = app(ExhibitionEngine::class);
    $state = $engine->initial(180, 77);
    expect($state['phase'])->toBe('kickoff')->and($state['possession'])->toBe('away');
    $kickoff = $engine->resolve($state, engineRosters(), 'kickoff', 'kickoff_return');
    expect($kickoff['state']['possession'])->toBe('home')->and($kickoff['state']['phase'])->toBe('scrimmage');
    expect(fn () => $engine->resolve($state, engineRosters(), 'slant', 'man_to_man'))->toThrow(LogicException::class);
    $td = engineOutcome('inside_run', 'touchdown', ['spot' => 99, 'distance' => 1, 'quarter' => 4, 'clock' => 1, 'away_score' => 7]);
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

test('two point tries start at the two and consume no time or regular statistics', function () {
    $engine = app(ExhibitionEngine::class);
    foreach (['two_point_run', 'two_point_pass'] as $call) {
        foreach (['home', 'away'] as $side) {
            foreach ([0, 1, 121] as $clock) {
                $state = array_merge($engine->initial(180, 42, false), ['phase' => 'extra_point', 'possession' => $side, 'spot' => 85, 'clock' => $clock, 'quarter' => 4, 'clock_running' => true, 'rules' => ['penalties' => false]]);
                $result = $engine->resolve($state, engineRosters(), $call, 'run_stop');
                expect($result['play']['before']['spot'])->toBe(98)->and($result['play']['clock_seconds'])->toBe(0)
                    ->and($result['state']['clock'])->toBe($clock)->and($result['state']['clock_running'])->toBeFalse()
                    ->and($result['state']['phase'])->toBe('kickoff')->and($result['state']['possession'])->toBe($side)
                    ->and($result['state']['stats'][$side]['plays'])->toBe(0)
                    ->and($result['state'][$side.'_score'])->toBe(in_array($result['play']['outcome'], ['touchdown'], true) ? 2 : 0)
                    ->and($result['state']['status'])->toBe($clock === 0 ? 'final' : 'playing');
            }
        }
    }
});

test('kick tries never use a running game clock', function () {
    $engine = app(ExhibitionEngine::class);
    $state = array_merge($engine->initial(180, 42, false), ['phase' => 'extra_point', 'spot' => 85, 'clock' => 121, 'quarter' => 2, 'clock_running' => true]);
    $result = $engine->resolve($state, engineRosters(), 'extra_point', 'field_goal_block');
    expect($result['state']['clock'])->toBe(121)->and($result['play']['clock_seconds'])->toBe(0)->and($result['state']['clock_running'])->toBeFalse();
});

test('new defensive fronts have eleven defenders and run stop crowds the line', function () {
    $engine = app(ExhibitionEngine::class);
    foreach (['base_3_5', 'nickel'] as $formation) {
        $state = $engine->initial(180, 42, false);
        $result = $engine->resolve($state, engineRosters(), 'inside_run', 'run_stop', 'singleback', $formation);
        $defenders = array_filter($result['play']['animation']['players'], fn ($p) => $p['team'] === 'defense');
        expect($defenders)->toHaveCount(11);
        foreach ($defenders as $p) {
            if (str_starts_with($p['role'], 'LB')) {
                expect(abs($p['path'][0][1] - $result['play']['animation']['line']))->toBeLessThanOrEqual(2);
            }
        }
    }
});

test('a human can accept or decline a try penalty and an accepted try stays untimed', function () {
    $engine = app(ExhibitionEngine::class);
    $found = null;
    for ($seed = 1; $seed < 200; $seed++) {
        $state = array_merge($engine->initial(180, $seed, false), ['phase' => 'extra_point', 'spot' => 85, 'quarter' => 4, 'clock' => 0]);
        $result = $engine->resolve($state, engineRosters(), 'two_point_pass', 'zone');
        if (isset($result['play']['penalty_options']) && $result['play']['penalty']['type'] === 'holding') {
            $found = $result;
            break;
        }
    }
    expect($found)->not->toBeNull()->and($found['state']['penalty_pending'])->toBeTrue();
    $accepted = $found['play']['penalty_options']['accept'];
    expect($accepted['state']['phase'])->toBe('extra_point')->and($accepted['state']['status'])->toBe('playing')
        ->and($accepted['state']['clock'])->toBe(0)->and($accepted['play']['clock_seconds'])->toBe(0)
        ->and($accepted['state']['stats']['home']['plays'])->toBe(0)->and($accepted['state']['spot'])->toBe(88);
    $retryState = $accepted['state'];
    $retryState['rules']['penalties'] = false;
    $retry = $engine->resolve($retryState, engineRosters(), 'two_point_run', 'run_stop');
    expect($retry['play']['before']['spot'])->toBe(88)->and($retry['play']['clock_seconds'])->toBe(0);
    $declined = $found['play']['penalty_options']['decline'];
    expect($declined['state']['phase'])->toBe('kickoff')->and($declined['state']['status'])->toBe('final');
});

test('a last second touchdown skips a try when it cannot change the winner', function () {
    $td = engineOutcome('inside_run', 'touchdown', ['spot' => 99, 'distance' => 1, 'quarter' => 4, 'clock' => 1]);
    expect($td['state']['home_score'])->toBe(6)->and($td['state']['status'])->toBe('final');
});

test('a sack still marks the quarterback as facing downfield without a throw', function () {
    $result = engineOutcome('deep_pass', 'sack');
    expect($result['play']['animation']['dropback'])->toBeTrue()->and($result['play']['animation']['passing'])->toBeFalse();
});

test('a defensive try return scores two and animates possession to the opposite goal', function () {
    $engine = app(ExhibitionEngine::class);
    $found = null;
    for ($seed = 1; $seed < 5000; $seed++) {
        $state = array_merge($engine->initial(180, $seed, false), ['phase' => 'extra_point', 'spot' => 85, 'rules' => ['penalties' => false]]);
        $result = $engine->resolve($state, engineRosters(), 'two_point_pass', 'zone');
        if ($result['state']['away_score'] === 2) {
            $found = $result;
            break;
        }
    }
    expect($found)->not->toBeNull()->and($found['state']['home_score'])->toBe(0)->and($found['state']['possession'])->toBe('home')
        ->and($found['play']['clock_seconds'])->toBe(0)->and($found['play']['animation']['ball'][array_key_last($found['play']['animation']['ball'])][1])->toBe(7);
});

test('pressure can produce sacks scrambles throwaways and passes with mobility affecting sacks', function () {
    $engine = app(ExhibitionEngine::class);
    $counts = ['sack' => 0, 'scramble' => 0, 'throwaway' => 0, 'pressure_pass' => 0];
    $sacks = ['slow' => 0, 'mobile' => 0];
    foreach (range(1, 800) as $seed) {
        $state = $engine->initial(900, $seed, false);
        $state['rules'] = ['penalties' => false, 'injuries' => false];
        foreach (['slow' => 25, 'mobile' => 90] as $kind => $speed) {
            $rosters = engineRosters();
            $rosters['home']['players']['QB']['ratings']['speed'] = $speed;
            $play = $engine->resolve($state, $rosters, 'deep_pass', 'man_to_man')['play'];
            $sacks[$kind] += $play['outcome'] === 'sack';
            if ($kind === 'mobile') {
                $counts['sack'] += $play['outcome'] === 'sack';
                $counts['scramble'] += $play['scramble'];
                $counts['throwaway'] += $play['throwaway'];
                $counts['pressure_pass'] += $play['pressure'] && ! $play['scramble'] && ! $play['throwaway'] && $play['outcome'] !== 'sack';
            }
        }
    }
    foreach ($counts as $count) {
        expect($count)->toBeGreaterThan(0);
    }
    expect($sacks['mobile'])->toBeLessThan($sacks['slow']);
});

test('motion and pressure metadata survive deterministic formation replays', function () {
    $engine = app(ExhibitionEngine::class);
    $state = $engine->initial(900, 55, false);
    $state['rules'] = ['penalties' => false, 'injuries' => false];
    $play = $engine->resolve($state, engineRosters(), 'screen', 'zone', 'trips', 'dime', 'normal', 'normal', 'pass', true, 'WR2')['play'];
    expect($play['design'])->toBe('screen')->and($play['motion'])->toBe('WR2')->and($play['expect'])->toBe('pass')->and($play['blitz'])->toBeTrue();
    expect($play['animation']['motion'])->toBe('WR2')->and($play['animation']['motion_start'])->not->toBe($play['animation']['motion_end']);
});

test('man coverage follows motion while zone coverage holds its assignment even when blitzing', function () {
    $engine = app(ExhibitionEngine::class);
    $state = $engine->initial(900, 55, false);
    $state['rules'] = ['penalties' => false, 'injuries' => false];
    foreach (['WR1' => 'CB1', 'WR2' => 'CB2', 'WR3' => 'LB3', 'TE' => 'S2', 'RB' => 'LB2'] as $receiver => $defender) {
        foreach (['man_to_man', 'zone'] as $coverage) {
            $animation = $engine->resolve($state, engineRosters(), 'short_pass', $coverage, 'shotgun', 'base_4_3', 'normal', 'normal', 'balanced', true, $receiver)['play']['animation'];
            expect($animation['motion_defender'])->toBe($coverage === 'man_to_man' ? $defender : null);
            if ($coverage === 'man_to_man') {
                $track = collect($animation['players'])->first(fn ($player) => $player['team'] === 'defense' && $player['role'] === $defender);
                expect($track['path'][0][3])->toBe($animation['motion_defender_end'])
                    ->and($animation['motion_defender_end'] - $animation['motion_defender_start'])->toBe($animation['motion_end'] - $animation['motion_start']);
            }
        }
    }
});

test('touchback animations travel into either end zone including older saved plays', function () {
    foreach (['home', 'away'] as $side) {
        foreach (['kickoff', 'punt'] as $call) {
            $play = ['before' => ['possession' => $side, 'spot' => 35, 'distance' => 10], 'call' => $call, 'carrier' => $call === 'punt' ? 'P' : 'K', 'outcome' => $call.'_touchback', 'gain' => 65, 'target' => 0, 'summary' => 'Touchback', 'landing' => 100, 'return_yards' => 0];
            $animation = app(\App\Services\Simulation\SpecialTeamsTimeline::class)->build($play, engineRosters());
            $x = $animation['ball'][3][1];
            expect($side === 'home' ? $x > 110 : $x < 10)->toBeTrue();
            expect(end($animation['ballHolders']))->toBe([$call === 'kickoff' ? 0 : 1.2, null, null]);
        }
    }
});
