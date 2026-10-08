<?php

use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GameClock;
use App\Services\Simulation\Overtime;

function overtimeState(string $mode = 'modern'): array
{
    $state = app(ExhibitionEngine::class)->initial(180, 42, false);
    $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
    $state['rules'] = ['overtime' => $mode, 'penalties' => false];
    $state['quarter'] = 4;
    $state['clock'] = 0;
    $play = ['outcome' => 'clock_expired', 'no_snap' => true, 'summary' => 'Clock expires'];
    $state = app(GameClock::class)->advance($state, $state, $play, 0);
    // Use home as first receiver for explicit scenarios.
    $state['overtime']['receiver'] = 'home';
    $state['phase'] = 'scrimmage';
    $state['possession'] = 'home';

    return $state;
}

function overtimeResult(array $before, array $changes, int $seconds = 7, string $outcome = 'tackle'): array
{
    $state = array_replace_recursive($before, $changes);
    $play = ['outcome' => $outcome, 'summary' => 'Play'];

    return app(GameClock::class)->advance($state, $before, $play, $seconds);
}

test('tied regulation starts configured overtime with correct timeouts or stays a tie', function () {
    foreach (['traditional', 'modern', 'traditional_playoff', 'modern_playoff'] as $mode) {
        $state = overtimeState($mode);
        expect($state['quarter'])->toBe(5)->and($state['status'])->toBe('playing')
            ->and($state['clock'])->toBe(Overtime::playoff($state) ? 900 : 600)
            ->and($state['timeouts']['home'])->toBe(Overtime::playoff($state) ? 3 : 2)
            ->and(app(Overtime::class)->pending($state))->toBeFalse();
    }
    expect(overtimeState('none')['status'])->toBe('final');
});

test('traditional overtime ends immediately on a touchdown field goal or safety', function () {
    foreach ([['home_score' => 6, 'phase' => 'extra_point'], ['home_score' => 3, 'phase' => 'kickoff'], ['away_score' => 2, 'phase' => 'kickoff']] as $changes) {
        expect(overtimeResult(overtimeState('traditional'), $changes)['status'])->toBe('final');
    }
});

test('modern opening touchdowns allow a response and a tying try leads to sudden death', function () {
    $first = overtimeResult(overtimeState(), ['home_score' => 6, 'phase' => 'extra_point']);
    expect($first['status'])->toBe('playing');
    $first = overtimeResult($first, ['home_score' => 7, 'phase' => 'kickoff'], 0);
    $response = array_replace($first, ['possession' => 'away', 'phase' => 'scrimmage']);
    $td = overtimeResult($response, ['away_score' => 6, 'phase' => 'extra_point']);
    expect($td['status'])->toBe('playing');
    $tied = overtimeResult($td, ['away_score' => 7, 'phase' => 'kickoff'], 0);
    expect($tied['status'])->toBe('playing')->and($tied['overtime']['sudden_death'])->toBeTrue();
    $next = array_replace($tied, ['possession' => 'home', 'phase' => 'scrimmage']);
    expect(overtimeResult($next, ['home_score' => 10, 'phase' => 'kickoff'])['status'])->toBe('final');
    expect(overtimeResult($td, ['away_score' => 8, 'phase' => 'kickoff'], 0)['status'])->toBe('final');
});

test('modern opening field goals allow response and failed response ends the game', function () {
    $first = overtimeResult(overtimeState(), ['home_score' => 3, 'phase' => 'kickoff']);
    expect($first['status'])->toBe('playing');
    $response = array_replace($first, ['possession' => 'away', 'phase' => 'scrimmage']);
    expect(overtimeResult($response, ['possession' => 'home'], 7, 'interception')['status'])->toBe('final');
    expect(overtimeResult($response, ['away_score' => 6, 'phase' => 'extra_point'])['status'])->toBe('final');
});

test('a scoreless initial possession enables sudden death and defensive scores can win immediately', function () {
    $second = overtimeResult(overtimeState(), ['possession' => 'away']);
    expect($second['status'])->toBe('playing')->and($second['overtime']['sudden_death'])->toBeTrue();
    expect(overtimeResult($second, ['away_score' => 3, 'phase' => 'kickoff'])['status'])->toBe('final');
    expect(overtimeResult(overtimeState(), ['away_score' => 2, 'possession' => 'home', 'phase' => 'kickoff'])['status'])->toBe('final');
    expect(overtimeResult(overtimeState(), ['away_score' => 6, 'possession' => 'away', 'phase' => 'extra_point'], 7, 'interception')['status'])->toBe('final');
});

test('regular overtime expires even without a response while playoff overtime carries the response forward', function () {
    foreach (['traditional', 'modern'] as $mode) {
        $state = overtimeState($mode);
        $state['clock'] = 1;
        expect(overtimeResult($state, [], 1)['status'])->toBe('final');
    }
    $state = overtimeState('modern');
    $state = overtimeResult($state, ['home_score' => 3, 'phase' => 'kickoff']);
    $state = array_replace($state, ['possession' => 'away', 'phase' => 'scrimmage', 'clock' => 1]);
    expect(overtimeResult($state, [], 1)['status'])->toBe('final');
    $state['rules']['overtime'] = 'modern_playoff';
    $next = overtimeResult($state, [], 1);
    expect($next['quarter'])->toBe(6)->and($next['status'])->toBe('playing')->and($next['clock'])->toBe(900)
        ->and($next['possession'])->toBe('away')->and($next['overtime']['completed']['away'])->toBeFalse();
    $next['clock'] = 1;
    $third = overtimeResult($next, [], 1);
    expect($third['quarter'])->toBe(7)->and($third['timeouts'])->toBe(['home' => 3, 'away' => 3]);
});

test('playoff touchdown at period expiration keeps its required try and does not award a tie', function () {
    $state = overtimeState('modern_playoff');
    $state['clock'] = 1;
    $td = overtimeResult($state, ['home_score' => 6, 'phase' => 'extra_point'], 1);
    expect($td['quarter'])->toBe(5)->and($td['phase'])->toBe('extra_point')->and($td['status'])->toBe('playing');
    $try = overtimeResult($td, ['home_score' => 7, 'phase' => 'kickoff'], 0);
    expect($try['quarter'])->toBe(6)->and($try['clock'])->toBe(900)->and($try['home_score'])->toBe(7);
});
