<?php

use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PlayerRatings;

function varietyRosters(): array
{
    $rosters = [];
    $roles = ['QB', 'RB', 'WR1', 'WR2', 'WR3', 'TE', 'C', 'LG', 'RG', 'LT', 'RT', 'DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3', 'CB1', 'CB2', 'S1', 'S2', 'K', 'P'];
    foreach (['home', 'away'] as $side) {
        foreach ($roles as $i => $role) {
            $rosters[$side]['players'][$role] = ['id' => $i + 1, 'name' => $side.' '.$role, 'number' => $i + 1, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70)];
        }
    }

    return $rosters;
}

test('different visual counters do not change simulated results clocks statistics or personnel', function () {
    $engine = app(ExhibitionEngine::class);
    $eligible = 0;
    foreach (['inside_run', 'short_pass', 'deep_pass'] as $call) {
        foreach (range(1, 12) as $seed) {
            $before = array_replace($engine->initial(900, $seed, false), ['rules' => ['penalties' => false, 'injuries' => false]]);
            $changed = $before + ['animation_variety_counts' => ['run' => 57, 'catch' => 57, 'incomplete' => 57]];
            $a = $engine->resolve($before, varietyRosters(), $call, 'man_to_man');
            $b = $engine->resolve($changed, varietyRosters(), $call, 'man_to_man');
            $stateA = $a['state'];
            $stateB = $b['state'];
            unset($stateA['animation_variety_counts'], $stateB['animation_variety_counts']);
            expect($stateA)->toBe($stateB);
            foreach (['call', 'outcome', 'gain', 'target', 'carrier', 'clock_seconds', 'summary'] as $key) {
                expect($a['play'][$key])->toBe($b['play'][$key]);
            }
            if (isset($a['play']['animation_variety'])) {
                $eligible++;
                $profile = $a['play']['animation_variety'];
                expect($a['play']['animation']['variety'])->toBe($profile)
                    ->and($a['state']['animation_variety_counts'][$profile['family']])->toBe(1)
                    ->and($b['state']['animation_variety_counts'][$profile['family']])->toBe(58)
                    ->and($b['play']['animation_variety']['id'])->not->toBe($profile['id']);
                // Rebuilding a saved play must use its saved choice, not new counters.
                $saved = $a['play'];
                $saved['before']['animation_variety_counts'][$profile['family']] = 99;
                expect(app(\App\Services\Simulation\PlayTimeline::class)->build($saved, varietyRosters())['variety'])->toBe($profile);
            }
        }
    }
    expect($eligible)->toBeGreaterThan(10);
});

test('scramble variants preserve the pocket set possession finish and football state', function () {
    $engine = app(ExhibitionEngine::class);
    $found = 0;
    foreach (range(1, 300) as $seed) {
        $before = array_replace($engine->initial(900, $seed, false), ['rules' => ['penalties' => false, 'injuries' => false]]);
        $a = $engine->resolve($before, varietyRosters(), 'deep_pass', 'blitz');
        if (($a['play']['animation_variety']['family'] ?? null) !== 'scramble') {
            continue;
        }
        $changed = $before + ['animation_variety_counts' => ['scramble' => 41]];
        $b = $engine->resolve($changed, varietyRosters(), 'deep_pass', 'blitz');
        $stateA = $a['state'];
        $stateB = $b['state'];
        unset($stateA['animation_variety_counts'], $stateB['animation_variety_counts']);
        expect($stateA)->toBe($stateB)
            ->and($b['state']['animation_variety_counts']['scramble'])->toBe(42)
            ->and($a['play']['animation_variety']['id'])->not->toBe($b['play']['animation_variety']['id']);
        $paths = [];
        foreach ([$a, $b] as $result) {
            $animation = $result['play']['animation'];
            $qb = collect($animation['players'])->first(fn ($p) => $p['team'] === 'offense' && $p['role'] === 'QB')['path'];
            $paths[] = $qb;
            foreach ($qb as $point) {
                if ($point[0] < .6) {
                    continue;
                }
                $ball = collect($animation['ball'])->first(fn ($p) => abs($p[0] - $point[0]) < 1e-8);
                expect($ball)->not->toBeNull();
                expect(hypot($ball[1] - $point[1], $ball[3] - $point[3]))->toBeLessThan(1e-8);
            }
            expect($animation['passing'])->toBeFalse()
                ->and($animation['variety']['family'])->toBe('scramble');
        }
        $pocket = fn ($path) => array_values(array_filter($path, fn ($p) => $p[0] <= 1.55));
        expect($pocket($paths[0]))->toBe($pocket($paths[1]))
            ->and(array_slice(end($paths[0]), 1))->toBe(array_slice(end($paths[1]), 1));
        $saved = $a['play'];
        $saved['before']['animation_variety_counts']['scramble'] = 99;
        expect(app(\App\Services\Simulation\PlayTimeline::class)->build($saved, varietyRosters())['variety'])->toBe($a['play']['animation_variety']);
        if (++$found === 4) {
            break;
        }
    }
    expect($found)->toBe(4);
});
