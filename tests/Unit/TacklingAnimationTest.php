<?php

use App\Services\Simulation\TacklingAnimation;

function tacklingFixture(bool $passing = false): array
{
    $track = fn ($role, $team, $x, $z, $endX, $endZ) => ['role' => $role, 'team' => $team, 'side' => $team === 'offense' ? 'home' : 'away',
        'path' => [[0, $x, 0, $z], [2.2, $x, 0, $z], [3.8, $endX, 0, $endZ], [5.3, $endX, 0, $endZ], [6, $endX, 0, $endZ]]];

    return ['call' => $passing ? 'short_pass' : 'inside_run', 'outcome' => 'tackle', 'passing' => $passing,
        'carrier' => 'WR1', 'receiver_role' => 'WR1', 'tackler_role' => 'CB1', 'tackle_style' => 'wrap', 'contact_at' => 5.3,
        'throw_at' => 2.2, 'catch_at' => 3.8, 'result_at' => 5.3, 'reveal_at' => 5.3, 'duration' => 6,
        'players' => [$track('WR1', 'offense', 50, 25, 55, 25), $track('CB1', 'defense', 20, 49, 55, 25),
            $track('LB2', 'defense', 56, 25, 55, 25), $track('S1', 'defense', 58, 27, 56, 26)],
        'ball' => [[0, 50, 1, 25], [2.2, 50, 1, 25], [3.8, 55, 1, 25], [5.3, 55, 1, 25], [6, 55, 1, 25]],
        'ballHolders' => [[0, 'offense', 'C'], [.35, 'offense', 'QB'], [2.2, null, null], [3.8, 'offense', 'WR1']],
        'events' => [[0, 'Snap'], [3.8, 'Catch'], [5.3, 'Tackle']]];
}

function expectBoundedPursuit(array $animation): void
{
    foreach ($animation['players'] as $player) {
        if ($player['team'] !== 'defense') {
            continue;
        }
        for ($i = 1; $i < count($player['path']); $i++) {
            $a = $player['path'][$i - 1];
            $b = $player['path'][$i];
            $distance = hypot($b[1] - $a[1], $b[3] - $a[3]);
            expect($b[0])->toBeGreaterThanOrEqual($a[0]);
            expect($distance)->toBeLessThanOrEqual((5.8 + 65 * .025) * ($b[0] - $a[0]) + 1e-7);
        }
    }
}

test('visual tackles select a nearby defender and cap every defender including the old tackler', function () {
    foreach ([false, true] as $passing) {
        $before = tacklingFixture($passing);
        $animation = TacklingAnimation::apply($before, []);
        expect($animation['tackler_role'])->toBe('LB2')->and($animation['pursuit_version'])->toBe('tack1')
            ->and($animation['ball'])->toBe($before['ball'])->and($animation['ballHolders'])->toBe($before['ballHolders'])
            ->and($animation['players'][0])->toBe($before['players'][0]);
        expectBoundedPursuit($animation);
        expect($animation)->toBe(TacklingAnimation::apply($before, []));
        $primary = collect($animation['players'])->firstWhere('role', $animation['tackler_role']);
        $end = $primary['path'][array_key_last($primary['path'])];
        expect(hypot($end[1] - 55, $end[3] - 25))->toBeLessThanOrEqual(.650001);
        expect($before['tackler_role'])->toBe('CB1');
    }
});

test('unreachable tackles extend presentation rather than teleporting and keep spatial endpoints', function () {
    $before = tacklingFixture(true);
    foreach ($before['players'] as &$player) {
        if ($player['team'] === 'defense') {
            $player['path'] = [[0, 5, 0, 3], [6, 5, 0, 3]];
        }
    }
    unset($player);
    $animation = TacklingAnimation::apply($before, []);
    expect($animation['contact_at'])->toBeGreaterThan($before['contact_at'])
        ->and($animation['throw_at'])->toBe($before['throw_at'])->and($animation['catch_at'])->toBe($before['catch_at']);
    expectBoundedPursuit($animation);
    foreach (['ball', 'players'] as $key) {
        $oldPath = $key === 'ball' ? $before['ball'] : $before['players'][0]['path'];
        $newPath = $key === 'ball' ? $animation['ball'] : $animation['players'][0]['path'];
        expect(array_slice($newPath[array_key_last($newPath)], 1))->toBe(array_slice($oldPath[array_key_last($oldPath)], 1));
    }
    expect(array_map(fn ($event) => array_slice($event, 1), $animation['ballHolders']))
        ->toBe(array_map(fn ($event) => array_slice($event, 1), $before['ballHolders']));
});

test('special events fumble recoveries interceptions and no-contact plays retain their original animation', function () {
    foreach ([['outcome' => 'interception'], ['outcome' => 'fumble'], ['no_snap' => true], ['out_of_bounds' => true], ['fake_kick' => true], ['contact_at' => null], ['contact_carrier_team' => 'defense']] as $override) {
        $animation = array_replace(tacklingFixture(), $override);
        expect(TacklingAnimation::apply($animation, []))->toBe($animation);
    }
});
