<?php

use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GamePersonnel;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\PlayFinalizer;

function finalizerChosenPlay(bool $penalty = false): array
{
    $before = (new ExhibitionEngine)->initial(180, 42, false);
    $before['rules']['penalties'] = $penalty;
    $before['rules']['injuries'] = false;
    $before['controls'] = ['home' => 'human', 'away' => 'human'];
    $state = $before;
    $state['spot'] = 32;
    $state['down'] = 2;
    $state['distance'] = 3;
    $state['stats']['home']['plays'] = 1;
    $state['stats']['home']['yards'] = 7;
    $play = [
        'call' => 'inside_run', 'defense' => 'man_to_man',
        'offense_formation' => 'shotgun', 'defense_formation' => 'base_4_3',
        'outcome' => 'tackle', 'gain' => 7, 'target' => 0, 'carrier' => 'RB',
        'summary' => 'chosen seven-yard run', 'dev_force_flag' => $penalty,
    ];
    $rosters = [];
    $roles = ['QB', 'RB', 'WR1', 'WR2', 'WR3', 'TE', 'C', 'LG', 'RG', 'LT', 'RT', 'DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3', 'CB1', 'CB2', 'S1', 'S2', 'K', 'P'];
    foreach (['home', 'away'] as $side) {
        foreach ($roles as $index => $role) {
            $rosters[$side]['players'][$role] = [
                'id' => ($side === 'home' ? 0 : 24) + $index + 1,
                'name' => $side.' '.$role, 'number' => $index + 1,
                'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70),
            ];
        }
    }
    $rosters = app(GamePersonnel::class)->active($rosters, $before, 'inside_run', 'shotgun');

    return [$state, $before, $play, $rosters, 7];
}

test('legacy finish delegates the complete ordered result to the shared finalizer', function (bool $penalty) {
    $arguments = finalizerChosenPlay($penalty);
    $direct = app(PlayFinalizer::class)->finish(...$arguments);
    $legacy = app(ExhibitionEngine::class)->finish(...$arguments);

    expect($legacy)->toBe($direct)
        ->and(json_encode($legacy, JSON_THROW_ON_ERROR))->toBe(json_encode($direct, JSON_THROW_ON_ERROR));
})->with([false, true]);

test('an already chosen outcome is finalized without invoking the Coach resolver', function () {
    $arguments = finalizerChosenPlay();
    $this->mock(ExhibitionEngine::class, function ($mock) {
        $mock->shouldReceive('resolve')->never();
        $mock->shouldReceive('finish')->never();
    });

    $result = app(PlayFinalizer::class)->finish(...$arguments);

    expect($result['play']['outcome'])->toBe('tackle')
        ->and($result['play']['gain'])->toBe(7)
        ->and($result['state']['spot'])->toBe(32)
        ->and($result['state']['down'])->toBe(2)
        ->and($result['state']['distance'])->toBe(3)
        ->and($result['state']['stats']['home']['plays'])->toBe(1)
        ->and($result['state']['stats']['home']['yards'])->toBe(7)
        ->and($result['state']['clock'])->toBe(173)
        ->and($result['state']['version'])->toBe(1)
        ->and($result['play']['after'])->toBe($result['state'])
        ->and($result['play']['animation']['players'])->toHaveCount(22)
        ->and($result['state']['personnel_snaps'])->toBe(1);
});

test('shared finalizer retains independent penalty options without resolving again', function () {
    $arguments = finalizerChosenPlay(true);
    $this->mock(ExhibitionEngine::class, function ($mock) {
        $mock->shouldReceive('resolve')->never();
        $mock->shouldReceive('finish')->never();
    });

    $result = app(PlayFinalizer::class)->finish(...$arguments);
    $options = $result['play']['penalty_options'];

    expect(array_keys($options))->toBe(['accept', 'decline'])
        ->and($result['state']['penalty_pending'])->toBeTrue()
        ->and($options['accept']['play']['outcome'])->toBe('penalty')
        ->and($options['accept']['state']['stats']['home']['plays'])->toBe(0)
        ->and($options['decline']['play']['outcome'])->toBe('tackle')
        ->and($options['decline']['state']['stats']['home']['plays'])->toBe(1);
    foreach ($options as $option) {
        expect($option['state']['version'])->toBe(1)
            ->and($option['play']['after'])->toBe($option['state'])
            ->and($option['state']['personnel_snaps'])->toBe(1);
    }
});
