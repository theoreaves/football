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

test('recorded world trajectories and participant identities survive finalization and penalty alternatives', function (bool $penalty) {
    [$state, $before, $play, $rosters, $seconds] = finalizerChosenPlay($penalty);
    $state['quarter'] = $before['quarter'] = 2;
    $recording = [
        'direction' => -1, 'line' => 85, 'firstDown' => 75,
        'players' => [
            ['id' => $rosters['home']['players']['RB']['id'], 'role' => 'RB', 'side' => 'home', 'path' => [[0, 86, 25], [1, 78, 24]]],
            ['id' => $rosters['away']['players']['LB2']['id'], 'role' => 'LB2', 'side' => 'away', 'path' => [[0, 80, 20], [1, 78, 24]]],
        ],
        'ball' => [[0, 86, 25, 1], [1, 78, 24, 1]], 'events' => [[0, 'Actual snap'], [1, 'Actual tackle']],
    ];
    $play['tackler_id'] = $rosters['away']['players']['LB2']['id'];
    $this->mock(\App\Services\Simulation\PlayTimeline::class)->shouldNotReceive('build');
    $this->mock(ExhibitionEngine::class)->shouldNotReceive('resolve');
    $result = app(PlayFinalizer::class)->finish($state, $before, $play, $rosters, $seconds, $recording);
    foreach ([$result, ...array_values($result['play']['penalty_options'] ?? [])] as $option) {
        expect($option['play']['animation']['players'])->toBe($recording['players'])
            ->and($option['play']['animation']['ball'])->toBe($recording['ball'])
            ->and($option['play']['animation']['line'])->toBe(85)
            ->and($option['play']['animation']['direction'])->toBe(-1)
            ->and($option['play']['tackler_id'])->toBe($play['tackler_id'])
            ->and($option['play'])->not->toHaveKey('animation_variety');
    }
})->with([false, true]);

test('shared scrimmage accounting handles already resolved results without a resolver', function (array $setup, string $outcome, int $gain, bool $flip, array $expected) {
    $before = array_replace((new ExhibitionEngine)->initial(180, 42, false), $setup);
    $this->mock(ExhibitionEngine::class)->shouldNotReceive('resolve');
    $result = app(\App\Services\Simulation\ScrimmageAccounting::class)->apply($before, $before, $outcome, $gain, $flip, 'Actual play');
    foreach ($expected as $key => $value) {
        expect($result['state'][$key])->toBe($value);
    }
    expect($result['state']['stats']['home']['plays'])->toBe(1)
        ->and($result['state']['stats']['home']['yards'])->toBe($outcome === 'interception' ? 0 : $gain)
        ->and($result['state']['stats']['home']['turnovers'])->toBe($flip ? 1 : 0);
})->with([
    'touchdown' => [['spot' => 95], 'tackle', 5, false, ['home_score' => 6, 'phase' => 'extra_point', 'spot' => 85]],
    'safety' => [['spot' => 3], 'sack', -3, false, ['away_score' => 2, 'phase' => 'kickoff', 'spot' => 20]],
    'first down' => [[], 'tackle', 10, false, ['down' => 1, 'distance' => 10, 'spot' => 35]],
    'fourth down' => [['down' => 4], 'tackle', 3, false, ['possession' => 'away', 'down' => 1, 'spot' => 72]],
    'interception' => [[], 'interception', 15, true, ['possession' => 'away', 'down' => 1, 'spot' => 60]],
    'interception touchback' => [['spot' => 95], 'interception', 5, true, ['possession' => 'away', 'spot' => 20]],
]);

test('shared presnap policy preserves snap-time pacing and leaves cancellation input untouched', function (string $tempo, int $runoff) {
    [, $before, , $rosters] = finalizerChosenPlay();
    $before['clock_running'] = true;
    $original = $before;
    $prepared = app(\App\Services\Simulation\PreSnapPolicy::class)->prepare($before, $rosters, $tempo);
    expect($prepared['result'])->toBeNull()
        ->and($prepared['state']['clock'])->toBe(180 - $runoff)
        ->and($prepared['state']['_clock_context']['runoff'])->toBe($runoff)
        ->and($before)->toBe($original);
})->with(['normal' => ['normal', 34], 'hurry' => ['hurry', 3], 'drain' => ['drain', 38]]);

test('shared presnap policy handles expiry warnings and untimed downs', function () {
    [, $before, , $rosters] = finalizerChosenPlay();
    $before['clock_running'] = true;
    $before['clock'] = 1;
    $policy = app(\App\Services\Simulation\PreSnapPolicy::class);
    $expired = $policy->prepare($before, $rosters);
    expect($expired['result']['play']['outcome'])->toBe('clock_expired')
        ->and($expired['result']['state']['quarter'])->toBe(2)
        ->and($expired['result']['state']['stats']['home']['plays'])->toBe(0);
    $before['untimed_down'] = true;
    $before['clock'] = 0;
    $untimed = $policy->prepare($before, $rosters);
    expect($untimed['result'])->toBeNull()->and($untimed['state']['clock'])->toBe(0);
    $before['untimed_down'] = false;
    $before['quarter'] = 2;
    $before['clock'] = 130;
    $warning = $policy->prepare($before, $rosters);
    expect($warning['result']['play']['outcome'])->toBe('two_minute_warning')
        ->and($warning['result']['state']['clock'])->toBe(120)
        ->and($warning['result']['state']['warnings'][2])->toBeTrue();
});

test('shared presnap penalties retain independent human choices without a snap', function () {
    [, $before, , $rosters] = finalizerChosenPlay(true);
    $before['clock_running'] = true;
    $this->partialMock(\App\Services\Simulation\PenaltyRules::class, function ($mock) {
        $mock->shouldReceive('preSnap')->once()->andReturn('false_start');
    });
    $result = app(\App\Services\Simulation\PreSnapPolicy::class)->prepare($before, $rosters)['result'];
    expect($result['play']['no_snap'])->toBeTrue()
        ->and($result['state']['penalty_pending'])->toBeTrue()
        ->and($result['play']['penalty_options']['accept']['state']['spot'])->toBe(20)
        ->and($result['play']['penalty_options']['decline']['state']['spot'])->toBe(25);
    foreach ($result['play']['penalty_options'] as $option) {
        expect($option['state']['clock'])->toBe(146)
            ->and($option['state']['stats']['home']['plays'])->toBe(0)
            ->and($option['state']['version'])->toBe(1);
    }
});
