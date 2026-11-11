<?php

use App\Models\Exhibition;
use App\Services\Simulation\ExhibitionBoxScore;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\OffensiveOptions;
use App\Services\Simulation\Overtime;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\RosterBuilder;

/** A database-independent, identity-stable roster including reserves for injury/rotation coverage. */
function coachCharacterizationRosters(): array
{
    $rosters = [];
    foreach (['home', 'away'] as $side) {
        foreach (RosterBuilder::GROUPS as $role => $positions) {
            $id = ($side === 'home' ? 0 : 100) + count($rosters[$side]['players'] ?? []) + 1;
            $player = ['id' => $id, 'name' => $side.' '.$role, 'number' => $id % 100, 'position' => $positions[0], 'depth' => $role, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70)];
            $rosters[$side]['players'][$role] = $player;
            $rosters[$side]['pool'][] = $player;
        }
        foreach (['QB', 'RB', 'WR', 'TE', 'C', 'LG', 'RG', 'LT', 'RT', 'DE', 'DT', 'LB', 'CB', 'S', 'K', 'P'] as $index => $position) {
            $rosters[$side]['pool'][] = ['id' => ($side === 'home' ? 50 : 150) + $index, 'name' => $side.' reserve '.$position, 'number' => 50 + $index, 'position' => $position, 'depth' => $position.'9', 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 60)];
        }
        $rosters[$side]['year'] = 2026;
    }

    return $rosters;
}

/** Sort maps only: event, animation-frame, participant and history order remains significant. */
function coachCharacterizationCanonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(coachCharacterizationCanonical(...), $value);
}

function coachCharacterizationHash(mixed $value): string
{
    return hash('sha256', json_encode(coachCharacterizationCanonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
}

function coachCharacterizationResolve(array $scenario, ?array $state = null): array
{
    $engine = app(ExhibitionEngine::class);
    $state ??= array_replace_recursive($engine->initial($scenario['length'] ?? 180, $scenario['seed'], $scenario['opening'] ?? false), $scenario['state'] ?? []);
    if (($scenario['operation'] ?? '') === 'timeout') {
        return $engine->timeout($state, coachCharacterizationRosters(), $scenario['side'] ?? 'home', $scenario['timing'] ?? 'now');
    }
    $call = $scenario['call'];
    $formation = $scenario['formation'] ?? (isset(OffensiveOptions::ALIASES[$call]) ? (array_key_first(array_filter(OffensiveOptions::FORMATIONS, fn (array $plays): bool => in_array($call, $plays, true))) ?? 'shotgun') : 'shotgun');

    return $engine->resolve($state, coachCharacterizationRosters(), $call, $scenario['defense'] ?? ExhibitionEngine::defensesForCall($call)[0], $formation, $scenario['defense_formation'] ?? 'base_4_3', $scenario['tempo'] ?? 'normal', $scenario['clock_strategy'] ?? 'normal', $scenario['expect'] ?? 'balanced', $scenario['blitz'] ?? false, $scenario['motion'] ?? 'none');
}

/** Every field is hashed, including nested accept/decline alternatives and animation geometry. */
function coachCharacterizationSnapshot(array $results, array $history, array $state): array
{
    $game = new Exhibition(['state' => $state, 'rosters' => coachCharacterizationRosters(), 'history' => $history]);
    $last = end($history);

    return [
        'results_sha256' => coachCharacterizationHash($results),
        'history_sha256' => coachCharacterizationHash($history),
        'state_sha256' => coachCharacterizationHash($state),
        'animations_sha256' => coachCharacterizationHash(array_column($history, 'animation')),
        'box_score_sha256' => coachCharacterizationHash(app(ExhibitionBoxScore::class)->build($game)),
        'projection' => [
            'plays' => count($history), 'outcomes' => array_count_values(array_column($history, 'outcome')),
            'last_outcome' => $last['outcome'], 'last_gain' => $last['gain'],
            'score' => [$state['home_score'], $state['away_score']], 'quarter' => $state['quarter'], 'clock' => $state['clock'],
            'possession' => $state['possession'], 'phase' => $state['phase'], 'spot' => $state['spot'], 'down' => $state['down'], 'distance' => $state['distance'],
            'status' => $state['status'], 'version' => $state['version'], 'stats' => $state['stats'],
            'penalty' => $last['penalty'] ?? null, 'personnel_snaps' => $state['personnel_snaps'] ?? 0,
            'injuries' => $state['injuries'] ?? [],
        ],
    ];
}

function coachCharacterizationRun(array $scenario): array
{
    if (! ($scenario['complete_game'] ?? false)) {
        $result = coachCharacterizationResolve($scenario);
        $history = [$result['play']];
        $results = [$result];
        if (isset($scenario['decision'])) {
            $result = $result['play']['penalty_options'][$scenario['decision']];
            $results[] = $result;
            $history = [$result['play']];
        }
        foreach ($scenario['followups'] ?? [] as $followup) {
            $result = coachCharacterizationResolve($followup, $result['state']);
            $results[] = $result;
            $history[] = $result['play'];
        }

        return coachCharacterizationSnapshot($results, $history, $result['state']);
    }
    $state = array_replace_recursive(app(ExhibitionEngine::class)->initial($scenario['length'], $scenario['seed'], true), $scenario['state'] ?? []);
    $history = [];
    $results = [];
    for ($index = 0; $index < 1000 && $state['status'] !== 'final'; $index++) {
        $overtime = app(Overtime::class);
        if ($state['overtime']['toss']['call_pending'] ?? false) {
            $state = $overtime->call($state, 'heads');
        }
        if ($state['overtime']['toss']['pending'] ?? false) {
            $state = $overtime->choose($state, 'receive');
        }
        $call = $state['down'] === 4 ? ($state['spot'] > 65 ? 'field_goal' : 'punt') : ['inside_run', 'outside_run', 'slant', 'deep_pass', 'short_pass', 'medium_pass'][$index % 6];
        if ($state['phase'] !== 'scrimmage') {
            $call = ExhibitionEngine::callsForState($state)[0];
        }
        $result = coachCharacterizationResolve(['call' => $call], $state);
        $results[] = $result;
        if (isset($result['play']['penalty_options'])) {
            $result = $result['play']['penalty_options'][$index % 2 === 0 ? 'accept' : 'decline'];
        }
        $history[] = $result['play'];
        $state = $result['state'];
    }
    if ($state['status'] !== 'final') {
        throw new RuntimeException('The characterized complete game did not reach final within 1000 calls.');
    }

    return coachCharacterizationSnapshot($results, $history, $state);
}

/** Captured once from 06b78c4 before extraction. Never update these baselines to accommodate a refactor. */
test('Coach preserves the pre-extraction seeded result and every recorded field', function (array $scenario, array $expected) {
    expect(coachCharacterizationRun($scenario))->toBe($expected);
})->with(function (): array {
    $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/coach-characterization.json'), true, flags: JSON_THROW_ON_ERROR);
    $cases = [];
    foreach ($fixture['cases'] as $name => $case) {
        $cases[$name] = [$case['scenario'], $case['expected']];
    }

    return $cases;
});
