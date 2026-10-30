<?php

use App\Models\Exhibition;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionBoxScore;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\OffensiveOptions;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\SpecialTeamsTricks;

function trickRosters(): array
{
    $rosters = [];
    foreach (['home', 'away'] as $side) {
        foreach (array_keys(\App\Services\Simulation\RosterBuilder::GROUPS) as $i => $role) {
            $rosters[$side]['players'][$role] = ['id' => $i + 1 + ($side === 'home' ? 0 : 100), 'name' => $side.' '.$role, 'number' => $i + 1, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70)];
        }
    }

    return $rosters;
}

function trickState(array $overrides = []): array
{
    return array_replace_recursive(app(ExhibitionEngine::class)->initial(900, 42, false), ['rules' => ['penalties' => false, 'injuries' => false]], $overrides);
}

test('2026 onside kick is declared legal in every quarter and at any score with a hands team', function () {
    foreach ([1, 2, 3, 4] as $quarter) {
        foreach ([-7, 0, 7] as $margin) {
            $state = trickState(['phase' => 'kickoff', 'quarter' => $quarter, 'home_score' => max(0, $margin), 'away_score' => max(0, -$margin)]);
            expect(ExhibitionEngine::callsForState($state))->toBe([
                'kickoff', 'kickoff_deep', 'kickoff_high', 'kickoff_short',
                'kickoff_left', 'kickoff_right', 'onside_kick',
            ]);
            $result = app(ExhibitionEngine::class)->resolve($state, trickRosters(), 'onside_kick', 'onside_return');
            expect($result['play']['declared_onside'])->toBeTrue()->and($result['play']['gain'])->toBeGreaterThanOrEqual(10);
            expect($result['state']['down'])->toBe(1)->and($result['state']['phase'])->toBe('scrimmage');
            expect($result['state']['stats']['home']['turnovers'])->toBe(0);
            expect($result['play']['animation']['events'][0][1])->toContain('Referee notifies');
            $ids = array_map(fn ($p) => $p['side'].':'.$p['id'], $result['play']['animation']['players']);
            expect(array_unique($ids))->toHaveCount(22);
        }
    }
    expect(ExhibitionEngine::callsForState(trickState()))->not->toContain('onside_kick');
    expect(ExhibitionEngine::defensesForCall('onside_kick'))->toBe(['onside_return']);
});

test('onside recovery stops at the recovery spot and retries are deterministic without offensive stats', function () {
    $engine = app(ExhibitionEngine::class);
    $found = [];
    for ($seed = 1; $seed < 200 && count($found) < 2; $seed++) {
        $state = trickState(['phase' => 'kickoff', 'spot' => 35, 'clock' => 82, 'quarter' => 4, 'seed' => $seed]);
        $result = $engine->resolve($state, trickRosters(), 'onside_kick', 'onside_return');
        $found[$result['play']['outcome']] = $result;
        expect($result)->toBe($engine->resolve($state, trickRosters(), 'onside_kick', 'onside_return'));
    }
    expect($found)->toHaveKeys(['onside_recovered', 'onside_failed']);
    $own = $found['onside_recovered'];
    expect($own['state']['possession'])->toBe('home')->and($own['state']['spot'])->toBe($own['play']['landing']);
    expect($own['play']['return_yards'])->toBe(0)->and($own['state']['clock_running'])->toBeFalse();
    $game = new Exhibition(['rosters' => trickRosters(), 'state' => $own['state'], 'history' => [$own['play']]]);
    $score = app(ExhibitionBoxScore::class)->build($game);
    expect($score['teams']['home']['plays'])->toBe(0)->and($score['teams']['home']['first_downs'])->toBe(0);
    expect($found['onside_failed']['state']['possession'])->toBe('away');
});

test('CPU selects late comeback onside kicks but preserves ordinary opening and leading kickoffs', function () {
    $coach = app(CpuCoach::class);
    $state = trickState(['phase' => 'kickoff', 'quarter' => 4, 'clock' => 82, 'home_score' => 20, 'away_score' => 22]);
    expect($coach->offense($state, trickRosters())['call'])->toBe('onside_kick');
    foreach ([['quarter' => 1], ['home_score' => 24], ['home_score' => 22], ['clock' => 400], ['clock' => 150, 'timeouts' => ['home' => 3]]] as $override) {
        expect($coach->offense(array_replace_recursive($state, $override), trickRosters())['call'])
            ->toBeIn(['kickoff', 'kickoff_deep', 'kickoff_high', 'kickoff_short', 'kickoff_left', 'kickoff_right']);
    }
});

test('fake run and pass designs use the actual punter or holder and normal fourth down resolution', function () {
    $engine = app(ExhibitionEngine::class);
    $rosters = trickRosters();
    foreach (SpecialTeamsTricks::FAKES as $call) {
        $formation = str_starts_with($call, 'fake_punt') ? 'punt' : 'field_goal';
        $defense = $formation === 'punt' ? 'punt_return' : 'field_goal_block';
        $outcomes = [];
        for ($seed = 1; $seed < 30 && count($outcomes) < 2; $seed++) {
            $state = trickState(['down' => 4, 'distance' => 3, 'spot' => 50, 'seed' => $seed]);
            $result = $engine->resolve($state, $rosters, $call, $defense, $formation);
            $outcomes[$result['state']['possession']] = $result;
            expect($result['play']['design'])->toBe($call)->and($result['play']['fake_kick'])->toBeTrue();
            expect($result['play']['call'])->toBe(OffensiveOptions::canonical($call));
            $qb = collect($result['play']['animation']['players'])->first(fn ($p) => $p['side'] === 'home' && $p['role'] === 'QB');
            expect($qb['id'])->toBe($rosters['home']['players']['P']['id']);
            expect($qb['path'][0][1])->toBeLessThan(60);
            $game = new Exhibition(['rosters' => $rosters, 'state' => $result['state'], 'history' => [$result['play']]]);
            $box = app(ExhibitionBoxScore::class)->build($game);
            $punter = collect($box['players']['home'])->firstWhere('id', $rosters['home']['players']['P']['id']);
            expect($punter['punts'])->toBe(0)->and($box['players']['home']['K']['fg_attempts'])->toBe(0);
            expect($box['teams']['home']['plays'])->toBe(1);
            if (str_ends_with($call, '_run')) {
                expect($punter['rushes'])->toBe(1)->and($punter['rushing_yards'])->toBe($result['play']['gain']);
                expect(collect($box['players']['away'])->sum('defensive_sacks'))->toBe(0);
            } else {
                expect($punter['pass_attempts'])->toBe(1);
            }
        }
        expect($outcomes)->toHaveKeys(['home', 'away']);
        expect($outcomes['home']['state']['down'])->toBe(1);
        expect($outcomes['away']['state']['spot'])->toBe(100 - (50 + $outcomes['away']['play']['gain']));
    }
});

test('fake calls require their matching kick formation and remain extremely rare for CPU choices', function () {
    $state = trickState(['down' => 4, 'distance' => 3, 'spot' => 50]);
    expect(fn () => app(ExhibitionEngine::class)->resolve($state, trickRosters(), 'fake_punt_run', 'punt_return', 'shotgun'))->toThrow(LogicException::class);
    $counts = ['punt' => 0, 'field_goal' => 0];
    for ($seed = 1; $seed <= 10000; $seed++) {
        $state['seed'] = $seed;
        foreach (array_keys($counts) as $kick) {
            if (SpecialTeamsTricks::cpuFake($state, $kick)) {
                $counts[$kick]++;
            }
        }
    }
    expect($counts['punt'])->toBeGreaterThan(200)->toBeLessThan(400);
    expect($counts['field_goal'])->toBeGreaterThan(40)->toBeLessThan(130);
    expect(SpecialTeamsTricks::cpuFake(array_replace($state, ['quarter' => 4, 'clock' => 82]), 'field_goal'))->toBeNull();
});

test('a legal onside recovery in overtime counts the receivers opportunity and permits sudden death', function () {
    $engine = app(ExhibitionEngine::class);
    $found = null;
    for ($seed = 1; $seed < 200; $seed++) {
        $state = app(\App\Services\Simulation\Overtime::class)->begin(trickState(['quarter' => 4, 'clock' => 0, 'seed' => $seed, 'rules' => ['overtime' => 'modern'], 'controls' => ['home' => 'cpu', 'away' => 'cpu']]));
        $result = $engine->resolve($state, trickRosters(), 'onside_kick', 'onside_return');
        if ($result['play']['outcome'] === 'onside_recovered') {
            $found = $result;
            $receiver = $state['overtime']['receiver'];
            expect($result['state']['overtime']['completed'][$receiver])->toBeTrue();
            expect($result['state']['overtime']['sudden_death'])->toBeTrue();
            expect($result['state']['status'])->toBe('playing');
            break;
        }
    }
    expect($found)->not->toBeNull();
});
