<?php

use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GameClock;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\PreSnapPolicy;
use App\Services\Simulation\RosterBuilder;

function arcadePacingRosters(): array
{
    $rosters = [];
    foreach (['home', 'away'] as $side) {
        foreach (RosterBuilder::GROUPS as $role => $positions) {
            $id = ($side === 'home' ? 0 : 100) + count($rosters[$side]['players'] ?? []) + 1;
            $player = ['id' => $id, 'name' => $side.' '.$role, 'number' => $id % 100, 'position' => $positions[0], 'depth' => $role, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70)];
            $rosters[$side]['players'][$role] = $player;
            $rosters[$side]['pool'][] = $player;
        }
    }

    return $rosters;
}

function arcadePacingState(int $length = 120, int $seed = 42): array
{
    $state = app(ExhibitionEngine::class)->initial($length, $seed, false);
    $state['rules'] = ['penalties' => false, 'injuries' => false, 'overtime' => 'none'];
    $state['controls'] = ['home' => 'human', 'away' => 'human'];

    return $state;
}

test('Arcade pacing uses Coach runoff at all presets and the two minute target', function (int $length, string $tempo, int $runoff) {
    $state = arcadePacingState($length);
    $state['clock_running'] = true;
    $prepared = app(PreSnapPolicy::class)->prepare($state, arcadePacingRosters(), $tempo);
    expect($prepared['result'])->toBeNull()->and($prepared['state']['clock'])->toBe($length - $runoff)
        ->and($prepared['state']['_clock_context']['runoff'])->toBe($runoff);
    $play = ['outcome' => 'tackle', 'summary' => 'Live play', 'runoff_seconds' => $runoff];
    $after = app(GameClock::class)->advance($prepared['state'], $prepared['state'], $play, 7);
    expect($after['clock'])->toBe($length - $runoff - 7)->and($play['clock_seconds'])->toBe($runoff + 7);
})->with([120, 180, 300, 600, 900])->with([['normal', 34], ['hurry', 3], ['drain', 38]]);

test('Arcade pacing preserves stopped and untimed clocks without runoff', function (bool $untimed) {
    $state = arcadePacingState();
    $state['clock'] = $untimed ? 0 : 1;
    $state['untimed_down'] = $untimed;
    $state['clock_running'] = $untimed;
    $prepared = app(PreSnapPolicy::class)->prepare($state, arcadePacingRosters(), 'drain');
    expect($prepared['result'])->toBeNull()->and($prepared['state']['clock'])->toBe($state['clock'])
        ->and($prepared['state']['_clock_context']['runoff'])->toBe(0);
})->with([false, true]);

test('Arcade pacing allows a one second live snap to complete before transition', function () {
    $state = arcadePacingState();
    $state['clock'] = 1;
    $prepared = app(PreSnapPolicy::class)->prepare($state, arcadePacingRosters());
    expect($prepared['result'])->toBeNull();
    $play = ['outcome' => 'tackle', 'summary' => 'Completed live play', 'gain' => 9];
    $after = app(GameClock::class)->advance($prepared['state'], $prepared['state'], $play, 7);
    expect($after['quarter'])->toBe(2)->and($after['clock'])->toBe(120)
        ->and($play['gain'])->toBe(9)->and($play['clock_seconds'])->toBe(7);
});

test('Arcade pacing reaches warning and halftime as no snap boundaries', function (int $seconds, string $outcome) {
    $state = arcadePacingState(180);
    $state['quarter'] = 2;
    $state['clock'] = $seconds;
    $state['clock_running'] = true;
    $result = app(PreSnapPolicy::class)->prepare($state, arcadePacingRosters())['result'];
    expect($result['play']['no_snap'])->toBeTrue()->and($result['play']['outcome'])->toBe($outcome)
        ->and($result['state']['stats']['home']['plays'])->toBe(0);
    if ($outcome === 'two_minute_warning') {
        expect($result['state']['clock'])->toBe(120)->and($result['state']['clock_running'])->toBeFalse();
    } else {
        expect($result['state']['quarter'])->toBe(3)->and($result['state']['phase'])->toBe('kickoff')->and($result['state']['clock'])->toBe(180);
    }
})->with([[135, 'two_minute_warning'], [20, 'clock_expired']]);

/**
 * Accounting/clock benchmark, not an HTTP session or anti-cheat test. JS generates
 * real fixed-tick recordings at each actual LOS. Presentation is omitted to keep
 * full games cheap. Coach is used only for special teams, never after live output.
 * Stress/lead use alternating runs/passes. Decisions uses down/distance and a seeded
 * mixed call choice with visible-state receiver reads. All punt/FG on fourth down, no timeouts,
 * penalties, injuries or overtime. This does NOT assert human gameplay balance.
 */
function arcadePacingGame(int $length, int $seed, string $tempo, bool $arcade, string $profile = 'stress'): array
{
    $engine = app(ExhibitionEngine::class);
    $state = arcadePacingState($length, $seed);
    $rosters = arcadePacingRosters();
    $counts = ['snaps' => ['home' => 0, 'away' => 0], 'drives' => ['home' => 0, 'away' => 0], 'runs' => 0, 'passes' => 0, 'runoff' => 0, 'live_seconds' => 0, 'outcomes' => [], 'passing_outcomes' => [], 'route_sets' => [], 'actual_pass_attempts' => $arcade ? 0 : null, 'scrambles' => $arcade ? 0 : null];
    $drive = null;
    $hash = hash_init('sha256');
    if ($arcade) {
        $process = proc_open(['node', base_path('tests/Fixtures/arcade-pacing-physics.mjs')], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start pacing physics fixture');
        }
    }
    try {
        for ($index = 0; $index < 2500 && $state['status'] !== 'final'; $index++) {
            $before = $state;
            $side = $state['possession'];
            $pass = ($counts['snaps']['home'] + $counts['snaps']['away']) % 2 === 1;
            if ($profile === 'decisions') {
                // Separate deterministic call-choice hash, never consume Coach or physics RNG.
                $choice = hexdec(substr(hash('sha256', $seed.':'.$index.':call'), 0, 6)) % 100;
                $passChance = $state['distance'] <= 3 ? 25 : ($state['down'] >= 3 ? 80 : 55);
                $pass = $choice < $passChance;
            }
            $call = $state['down'] === 4 ? ($state['spot'] > 65 ? 'field_goal' : 'punt') : ($pass ? 'slant' : 'inside_run');
            if ($state['phase'] !== 'scrimmage') {
                $call = ExhibitionEngine::callsForState($state)[0];
            }
            $interactive = in_array($call, ['inside_run', 'slant'], true);
            if ($arcade && $interactive) {
                $active = app(\App\Services\Simulation\GamePersonnel::class)->active($rosters, $state, $call, 'shotgun');
                $prepared = app(PreSnapPolicy::class)->prepare($state, $active, $tempo);
                $result = $prepared['result'];
                if ($result === null) {
                    $snap = $prepared['state'];
                    $direction = \App\Services\Simulation\FieldOrientation::direction($snap);
                    fwrite($pipes[0], json_encode(['direction' => $direction, 'startX' => \App\Services\Simulation\FieldOrientation::line($snap), 'seed' => $profile === 'decisions' ? \App\Services\Arcade\LivePlaySeed::forState($snap) : $seed + $index, 'distance' => $snap['distance'], 'players' => $active[$side]['players'], 'defense' => $active[$side === 'home' ? 'away' : 'home']['players'], 'pass' => $pass, 'profile' => $profile, 'routeSet' => 'slants'], JSON_THROW_ON_ERROR)."\n");
                    $response = fgets($pipes[1]);
                    if ($response === false) {
                        throw new RuntimeException('Physics fixture failed: '.stream_get_contents($pipes[2]));
                    }
                    $physics = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
                    hash_update($hash, $physics['recordingHash']);
                    $reason = $physics['result']['reason'];
                    if ($pass) {
                        $counts['actual_pass_attempts'] += $physics['attemptedPass'] ? 1 : 0;
                        $counts['scrambles'] += ! $physics['attemptedPass'] && ! in_array($reason, ['sack', 'safety', 'timeout'], true) ? 1 : 0;
                        $passingOutcome = $physics['passOutcome'] ?? $reason;
                        $counts['passing_outcomes'][$passingOutcome] = ($counts['passing_outcomes'][$passingOutcome] ?? 0) + 1;
                        $counts['route_sets'][$physics['routeSet']] = ($counts['route_sets'][$physics['routeSet']] ?? 0) + 1;
                    }
                    $outcome = match ($reason) {
                        'incomplete' => 'incomplete', 'interception' => 'interception', 'sack' => 'sack', default => $physics['passOutcome'] === 'complete' ? 'complete' : 'tackle'
                    };
                    $gain = $outcome === 'incomplete' ? 0 : (int) round(($physics['runnerX'] - \App\Services\Simulation\FieldOrientation::line($snap)) * $direction);
                    $gain = max(-$snap['spot'], min(100 - $snap['spot'], $gain));
                    $accounted = app(\App\Services\Simulation\ScrimmageAccounting::class)->apply($snap, $snap, $outcome, $gain, $outcome === 'interception', 'Scripted physics');
                    $play = ['call' => $call, 'defense' => 'man_to_man', 'offense_formation' => 'shotgun', 'defense_formation' => 'base_4_3', 'outcome' => $accounted['outcome'], 'gain' => $gain, 'target' => 0, 'carrier' => $pass ? 'WR1' : 'RB', 'summary' => $accounted['summary'], 'out_of_bounds' => $reason === 'out_of_bounds'];
                    $participants = [];
                    if ($profile === 'decisions') {
                        // Preserve real on-field fatigue/participation without constructing fake motion.
                        foreach ([$side => $pass ? ['QB', 'WR1', 'WR2'] : ['RB'], $side === 'home' ? 'away' : 'home' => $pass ? ['CB1', 'CB2'] : ['LB1', 'CB1']] as $actorSide => $roles) {
                            foreach ($roles as $role) {
                                $participants[] = ['id' => $active[$actorSide]['players'][$role]['id'], 'side' => $actorSide, 'role' => $role];
                            }
                        }
                    }
                    $result = app(\App\Services\Simulation\PlayFinalizer::class)->finish($accounted['state'], $snap, $play, $active, (int) ceil($physics['ticks'] / 60), ['players' => $participants, 'events' => []]);
                }
            } else {
                $result = $engine->resolve($state, $rosters, $call, ExhibitionEngine::defensesForCall($call)[0], 'shotgun', 'base_4_3', $tempo);
            }
            $state = $result['state'];
            $play = $result['play'];
            if ($before['phase'] === 'scrimmage' && ! ($play['no_snap'] ?? false)) {
                if ($drive !== $side) {
                    $counts['drives'][$side]++;
                    $drive = $side;
                }
                if ($interactive) {
                    $counts['snaps'][$side]++;
                    $counts[$pass ? 'passes' : 'runs']++;
                    $counts['outcomes'][$play['outcome']] = ($counts['outcomes'][$play['outcome']] ?? 0) + 1;
                    $counts['runoff'] += $play['runoff_seconds'];
                    $counts['live_seconds'] += $play['clock_seconds'] - $play['runoff_seconds'];
                }
            }
            if ($state['possession'] !== $side || $state['phase'] !== 'scrimmage' || ($before['quarter'] === 2 && $state['quarter'] === 3)) {
                $drive = null;
            }
        }
        if ($state['status'] !== 'final') {
            throw new RuntimeException('Pacing game exceeded bounded calls');
        }
    } finally {
        if ($arcade) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
    $snaps = array_sum($counts['snaps']);

    return $counts + ['snaps_per_drive' => round($snaps / max(1, array_sum($counts['drives'])), 3), 'mean_runoff' => round($counts['runoff'] / max(1, $snaps), 3), 'mean_live_seconds' => round($counts['live_seconds'] / max(1, $snaps), 3), 'recording_hash' => hash_final($hash)];
}

test('Arcade pacing scripted physics and seeded Coach games are reproducible', function () {
    foreach ([false, true] as $arcade) {
        $first = arcadePacingGame(120, 42, 'normal', $arcade);
        expect(arcadePacingGame(120, 42, 'normal', $arcade))->toBe($first)
            ->and(array_sum($first['snaps']))->toBeGreaterThan(0)->and(array_sum($first['drives']))->toBeGreaterThan(0);
    }
});

test('Arcade pacing opt in full preset and tempo distribution report', function () {
    if (! getenv('ARCADE_PACING_REPORT')) {
        $this->markTestSkipped('Set ARCADE_PACING_REPORT=1 for the full scripted comparison.');
    }
    foreach ([120, 180, 300, 600, 900] as $length) {
        foreach (['normal', 'hurry', 'drain'] as $tempo) {
            foreach ([42, 137, 2026] as $seed) {
                foreach ([false, true] as $arcade) {
                    $metrics = arcadePacingGame($length, $seed, $tempo, $arcade);
                    fwrite(STDOUT, json_encode(['length' => $length, 'tempo' => $tempo, 'seed' => $seed, 'mode' => $arcade ? 'scripted_physics' : 'seeded_coach'] + $metrics)."\n");
                    expect(array_sum($metrics['snaps']))->toBeGreaterThan(0);
                }
            }
        }
    }
});

/** Controlled matched-outcome isolation: not human or live Arcade performance. */
test('Arcade pacing matched Coach outcomes have identical clock policy and accounting', function (int $length, string $tempo) {
    foreach ([42, 137, 2026] as $seed) {
        $state = arcadePacingState($length, $seed);
        $state['clock_running'] = true;
        foreach (['inside_run', 'slant'] as $call) {
            $coach = app(ExhibitionEngine::class)->resolve($state, arcadePacingRosters(), $call, 'man_to_man', 'shotgun', 'base_4_3', $tempo);
            $active = app(\App\Services\Simulation\GamePersonnel::class)->active(arcadePacingRosters(), $state, $call, 'shotgun');
            $snap = app(PreSnapPolicy::class)->prepare($state, $active, $tempo)['state'];
            $chosen = $coach['play'];
            $accounted = app(\App\Services\Simulation\ScrimmageAccounting::class)->apply($snap, $snap, $chosen['outcome'], $chosen['gain'], in_array($chosen['outcome'], ['interception', 'fumble'], true), 'Matched chosen outcome');
            $seconds = $chosen['clock_seconds'] - $chosen['runoff_seconds'];
            $play = array_intersect_key($chosen, array_flip(['call', 'defense', 'offense_formation', 'defense_formation', 'gain', 'target', 'carrier', 'out_of_bounds']));
            $play += ['outcome' => $accounted['outcome'], 'summary' => $accounted['summary']];
            $matched = app(\App\Services\Simulation\PlayFinalizer::class)->finish($accounted['state'], $snap, $play, $active, $seconds, ['players' => [], 'events' => []]);
            foreach (['clock', 'quarter', 'clock_running', 'possession', 'spot', 'down', 'distance', 'stats'] as $key) {
                expect($matched['state'][$key])->toBe($coach['state'][$key]);
            }
            expect($matched['play']['runoff_seconds'])->toBe($chosen['runoff_seconds'])
                ->and($matched['play']['clock_seconds'])->toBe($chosen['clock_seconds']);
        }
    }
})->with([120, 180, 300, 600, 900])->with(['normal', 'hurry', 'drain']);

test('Arcade pacing opt in early lead input sensitivity report', function () {
    if (! getenv('ARCADE_PACING_REPORT')) {
        $this->markTestSkipped('Set ARCADE_PACING_REPORT=1 for the additional early lead profile.');
    }
    foreach ([42, 137, 2026] as $seed) {
        $metrics = arcadePacingGame(120, $seed, 'normal', true, 'lead');
        fwrite(STDOUT, json_encode(['length' => 120, 'tempo' => 'normal', 'seed' => $seed, 'mode' => 'scripted_early_lead'] + $metrics)."\n");
        expect(arcadePacingGame(120, $seed, 'normal', true, 'lead'))->toBe($metrics);
    }
});

/** This comparison is a plausible bounded bot, not calibrated human behavior. */
test('Arcade pacing visible decision profile is reproducible with mixed calls and current exhibition routes', function () {
    $first = arcadePacingGame(120, 42, 'normal', true, 'decisions');
    expect(arcadePacingGame(120, 42, 'normal', true, 'decisions'))->toBe($first)
        ->and($first['runs'])->toBeGreaterThan(0)->and($first['passes'])->toBeGreaterThan(0)
        ->and($first['route_sets']['slants'] ?? 0)->toBeGreaterThan(0)
        ->and($first['route_sets']['comeback'] ?? 0)->toBe(0)
        ->and(array_sum($first['passing_outcomes']))->toBe($first['passes']);
});

test('Arcade pacing opt in visible decision preset and tempo report', function () {
    if (! getenv('ARCADE_PACING_REPORT')) {
        $this->markTestSkipped('Set ARCADE_PACING_REPORT=1 for the visible decision comparison.');
    }
    foreach ([120, 180, 300, 600, 900] as $length) {
        foreach (['normal', 'hurry', 'drain'] as $tempo) {
            foreach ([42, 137, 2026] as $seed) {
                foreach ([false, true] as $arcade) {
                    $metrics = arcadePacingGame($length, $seed, $tempo, $arcade, 'decisions');
                    fwrite(STDOUT, json_encode(['length' => $length, 'tempo' => $tempo, 'seed' => $seed, 'mode' => $arcade ? 'scripted_visible_decisions' : 'seeded_coach_decisions'] + $metrics)."\n");
                    expect(array_sum($metrics['snaps']))->toBeGreaterThan(0);
                }
            }
        }
    }
});
