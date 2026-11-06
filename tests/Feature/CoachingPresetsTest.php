<?php

use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\OffensiveOptions;
use App\Services\Simulation\PlayerRatings;

test('coaching presets produce distinct legal tendencies and preserve victory decisions for either team', function () {
    $coach = app(CpuCoach::class);
    $engine = app(ExhibitionEngine::class);
    $players = array_fill_keys(['QB', 'RB', 'WR1', 'C', 'K'], ['ratings' => array_fill_keys(PlayerRatings::FIELDS, 70)]);
    $rosters = ['home' => ['players' => $players], 'away' => ['players' => $players]];
    $counts = [];
    foreach (config('team-settings.coaching_presets') as $name => $preset) {
        $counts[$name] = ['pass' => 0, 'blitz' => 0];
        foreach (['home', 'away'] as $side) {
            $other = $side === 'home' ? 'away' : 'home';
            foreach (range(1, 200) as $seed) {
                $state = array_replace($engine->initial(900, $seed, false), [
                    'possession' => $side, 'quarter' => 1, 'clock' => 600, 'spot' => 40,
                    'coaching' => [$side => $preset['values'], $other => $preset['values']],
                ]);
                $plan = $coach->offense($state, $rosters);
                expect($plan)->toBe($coach->offense($state, $rosters))
                    ->and($plan['call'])->toBeIn(OffensiveOptions::FORMATIONS[$plan['formation']]);
                $counts[$name]['pass'] += in_array(OffensiveOptions::canonical($plan['call']), ['short_pass', 'slant', 'medium_pass', 'deep_pass']) ? 1 : 0;
                $counts[$name]['blitz'] += $coach->defense($state, 'inside_run')['call'] === 'blitz' ? 1 : 0;
                $state = array_replace($state, ['quarter' => 4, 'clock' => 30, $side.'_score' => 21, $other.'_score' => 17,
                    'timeouts' => [$side => 3, $other => 0], 'warnings' => ['4' => true]]);
                expect($coach->offense($state, $rosters)['call'])->toBe('kneel');
                $state['clock'] = 175;
                expect(OffensiveOptions::canonical($coach->offense($state, $rosters)['call']))->toBe('inside_run')
                    ->and($coach->management($state))->toBe(['tempo' => 'drain', 'clock_strategy' => 'normal']);
            }
        }
    }
    expect($counts['aerial_attack']['pass'])->toBeGreaterThan($counts['balanced']['pass'])
        ->and($counts['balanced']['pass'])->toBeGreaterThan($counts['ground_game']['pass'])
        ->and($counts['pressure_defense']['blitz'])->toBeGreaterThan($counts['balanced']['blitz']);
});
