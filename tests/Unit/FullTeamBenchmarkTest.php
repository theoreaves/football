<?php

use App\Services\Arcade\FullTeamSetup;

pest()->extend(Tests\TestCase::class);
require_once __DIR__.'/../Fixtures/fullteam-benchmark-support.php';

test('full team actual recordings validate all calls and directions with one participation charge', function (string $call, int $direction) {
    $fixture = fullteamBenchmarkFixture($call, $direction);
    $generated = fullteamBenchmarkRun($fixture);
    $validated = fullteamBenchmarkValidate($fixture, $generated['recording']);
    $recording = $generated['recording'];
    $resolved = $validated['resolved'];
    $state = $validated['result']['state'];
    expect($recording['version'])->toBe(2)->and($recording['speedScale'])->toBe(.925)
        ->and($recording['actors'])->toHaveCount(22)->and($recording['truncated'])->toBeFalse()
        ->and($resolved['arcade_on_field'])->toHaveCount(22)
        ->and($state['personnel_snaps'])->toBe(1)->and($state['version'])->toBe(1)
        ->and($state['stats']['home']['plays'])->toBe(1);
    foreach ($fixture['setup']['actors'] as $actor) {
        expect($state['fatigue'][$actor['side']][$actor['player_id']])->toEqual(2.5);
    }
    foreach (['frames', 'events', 'bytes', 'nodes'] as $limit) {
        expect($generated['metrics'][$limit])->toBeLessThanOrEqual(FullTeamSetup::LIMITS[$limit]);
    }
    $keys = array_column($recording['actors'], 'key');
    foreach ($recording['events'] as $event) {
        if ($event[2] === 'handoff') {
            expect($keys[$event[3]])->toBe('offense_QB')->and($keys[$event[4]])->toBe('offense_RB');
        }
        if ($event[2] === 'catch') {
            expect($resolved['participants']['receiver']['player_id'])->toBe($recording['actors'][$event[3]]['id']);
        }
        if ($event[2] === 'tackle') {
            expect($resolved['participants']['tackler']['player_id'])->toBe($recording['actors'][$event[4]]['id']);
        }
    }
})->with(FullTeamSetup::CALLS)->with([1, -1]);

test('full team synthetic seed replay is exact and rating interventions alter only one field', function () {
    $fixture = fullteamBenchmarkFixture('short_pass');
    $a = fullteamBenchmarkRun($fixture);
    $b = fullteamBenchmarkRun($fixture);
    expect($a['recording'])->toBe($b['recording']);
    foreach (['lg_blocking_90', 'qb_throwing_90', 'wr1_catching_90', 'lb2_tackling_90'] as $variant) {
        $altered = fullteamBenchmarkFixture('short_pass', 1, 42, $variant);
        $differences = 0;
        foreach ($fixture['setup']['players'] as $index => $player) {
            foreach ($player['ratings'] as $rating => $value) {
                $differences += (int) ($altered['setup']['players'][$index]['ratings'][$rating] !== $value);
            }
        }
        expect($differences)->toBe(1);
    }
});

test('full team opt in synthetic live snap QA benchmark report', function () {
    if (! getenv('FULLTEAM_BENCHMARK_REPORT')) {
        $this->markTestSkipped('Set FULLTEAM_BENCHMARK_REPORT=1 for the Stage B single-snap report.');
    }
    foreach (['baseline', 'lg_blocking_90', 'qb_throwing_90', 'wr1_catching_90', 'lb2_tackling_90'] as $variant) {
        foreach ([42, 137, 2026] as $seed) {
            foreach (FullTeamSetup::CALLS as $call) {
                foreach ([1, -1] as $direction) {
                    $fixture = fullteamBenchmarkFixture($call, $direction, $seed, $variant);
                    $generated = fullteamBenchmarkRun($fixture);
                    $validated = fullteamBenchmarkValidate($fixture, $generated['recording']);
                    $metrics = ['variant' => $variant, 'inputSeed' => $seed, 'phpValidationMs' => $validated['validationMs']] + $generated['metrics'];
                    fwrite(STDOUT, json_encode(['fullteam_benchmark' => $metrics], JSON_THROW_ON_ERROR)."\n");
                    expect($validated['result']['state']['personnel_snaps'])->toBe(1);
                }
            }
        }
    }
});

test('full team pause and replay display frames do not consume live time or change the recording', function () {
    $fixture = fullteamBenchmarkFixture('short_pass');
    $plain = fullteamBenchmarkRun($fixture);
    $paused = fullteamBenchmarkRun($fixture, 'pause');
    expect($paused['recording'])->toBe($plain['recording'])
        ->and($paused['metrics']['ticks'])->toBe($plain['metrics']['ticks'])
        ->and($paused['metrics']['seconds'])->toBe($plain['metrics']['seconds']);
});

test('full team opt in representative saved roster single snap report', function () {
    $path = getenv('FULLTEAM_SAVED_ROSTER_FIXTURE');
    if (! $path) {
        $this->markTestSkipped('Set FULLTEAM_SAVED_ROSTER_FIXTURE to an approved read-only export; never read the app database.');
    }
    $saved = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    foreach ([42, 137, 2026] as $seed) {
        foreach (FullTeamSetup::CALLS as $call) {
            foreach ([1, -1] as $direction) {
                $fixture = fullteamBenchmarkFixture($call, $direction, $seed, 'baseline', $saved);
                $generated = fullteamBenchmarkRun($fixture);
                $validated = fullteamBenchmarkValidate($fixture, $generated['recording']);
                $metrics = ['variant' => 'saved_roster', 'inputSeed' => $seed, 'phpValidationMs' => $validated['validationMs']] + $generated['metrics'];
                // Metrics contain roles/keys only, never roster names, appearance or saved-game identity.
                fwrite(STDOUT, json_encode(['fullteam_benchmark' => $metrics], JSON_THROW_ON_ERROR)."\n");
                expect($validated['result']['state']['personnel_snaps'])->toBe(1);
            }
        }
    }
});

test('full team held pocket resolves a real sack and short outlet keeps actual receiver control', function (int $direction) {
    $fixture = fullteamBenchmarkFixture('medium_pass', $direction);
    $held = fullteamBenchmarkRun($fixture, 'hold');
    $sack = fullteamBenchmarkValidate($fixture, $held['recording']);
    expect($sack['resolved']['outcome'])->toBe('sack')
        ->and($sack['resolved']['participants']['carrier']['role'])->toBe('QB')
        ->and($sack['resolved']['participants']['tackler']['side'])->toBe('away');
    $outlet = fullteamBenchmarkRun($fixture, 'outlet');
    $caught = fullteamBenchmarkValidate($fixture, $outlet['recording']);
    expect($outlet['metrics']['passOutcome'])->toBe('complete')
        ->and($caught['resolved']['participants']['receiver']['role'])->toBeIn(['RB', 'TE'])
        ->and($caught['resolved']['participants']['carrier']['player_id'])->toBe($caught['resolved']['participants']['receiver']['player_id']);
})->with([1, -1]);
