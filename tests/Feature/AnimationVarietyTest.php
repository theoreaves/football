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
            if (isset($a['play']['animation_variety'])) {
                $changed['animation_variety_counts'][$a['play']['animation_variety']['family']] = 57;
            }
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

test('expanded generated plays retain identical geometry possession statistics and saved replay choices', function () {
    $engine = app(ExhibitionEngine::class);
    $rosters = varietyRosters();
    $seen = [];
    foreach (['inside_run', 'deep_pass', 'punt', 'kickoff', 'field_goal', 'extra_point', 'onside_kick'] as $call) {
        for ($seed = 1; $seed <= 120; $seed++) {
            $special = in_array($call, ['punt', 'kickoff', 'field_goal', 'extra_point', 'onside_kick']);
            $before = array_replace($engine->initial(900, $seed, false), [
                'possession' => $seed % 2 ? 'home' : 'away', 'quarter' => 4, 'clock' => 90,
                'phase' => in_array($call, ['kickoff', 'onside_kick']) ? 'kickoff' : ($call === 'extra_point' ? 'extra_point' : 'scrimmage'),
                'spot' => $special ? ($call === 'punt' ? ($seed % 3 === 0 ? 65 : 40) : ($call === 'field_goal' ? 65 : 35)) : ($seed % 3 === 0 ? 97 : 40),
                'rules' => ['penalties' => false, 'injuries' => false],
            ]);
            $defense = match ($call) {
                'punt' => $seed % 2 ? 'punt_return' : 'punt_block', 'kickoff' => 'kickoff_return_aggressive',
                'field_goal', 'extra_point' => 'field_goal_block', 'onside_kick' => 'onside_return', default => 'blitz'
            };
            $a = $engine->resolve($before, $rosters, $call, $defense, 'shotgun', 'base_4_3', 'normal', 'sideline');
            $profile = $a['play']['animation_variety'] ?? null;
            if (! ($profile['pose_only'] ?? false) || isset($seen[$profile['family']])) {
                continue;
            }
            $family = $profile['family'];
            $seen[$family] = true;
            $changed = $before + ['animation_variety_counts' => [$family => 47]];
            $b = $engine->resolve($changed, $rosters, $call, $defense, 'shotgun', 'base_4_3', 'normal', 'sideline');
            $stateA = $a['state'];
            $stateB = $b['state'];
            unset($stateA['animation_variety_counts'], $stateB['animation_variety_counts']);
            expect($stateA)->toBe($stateB)
                ->and($b['state']['animation_variety_counts'][$family])->toBe(48)
                ->and($b['play']['animation_variety']['id'])->not->toBe($profile['id']);
            $animationA = $a['play']['animation'];
            $animationB = $b['play']['animation'];
            expect($animationA['variety'])->toBe($profile);
            unset($animationA['variety'], $animationB['variety']);
            expect($animationA)->toBe($animationB);
            $saved = $a['play'];
            $saved['before']['animation_variety_counts'][$family] = 999;
            $timeline = $special ? \App\Services\Simulation\SpecialTeamsTimeline::class : \App\Services\Simulation\PlayTimeline::class;
            expect(app($timeline)->build($saved, $rosters)['variety'])->toBe($profile);
        }
    }
    expect($seen)->toHaveKeys(['touchdown', 'sideline', 'sack', 'interception', 'fumble',
        'special_punt_return', 'special_punt_touchback', 'special_punt_blocked',
        'special_kickoff_return', 'special_kickoff_touchback',
        'special_field_goal_good', 'special_field_goal_missed', 'special_field_goal_blocked',
        'special_extra_point_good', 'special_extra_point_missed',
        'special_onside_recovered', 'special_onside_failed']);
});
