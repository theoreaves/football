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
