<?php

use App\Services\Simulation\InterceptionReactions;

function reactionFixture(): array
{
    return ['outcome' => 'interception', 'passing' => true, 'throw_at' => 3.2, 'catch_at' => 4.0,
        'result_at' => 4.5, 'duration' => 8, 'reveal_at' => 4.5,
        'players' => [
            ['team' => 'offense', 'side' => 'home', 'role' => 'WR1', 'path' => [[0, 39, 0, 9], [3.2, 46, 0, 9], [4, 49, 0, 9], [8, 49, 0, 9]]],
            ['team' => 'offense', 'side' => 'home', 'role' => 'TE', 'path' => [[0, 39, 0, 34], [4, 52, 0, 34], [8, 52, 0, 34]]],
            ['team' => 'defense', 'side' => 'away', 'role' => 'CB1', 'path' => [[0, 43, 0, 9], [1.65, 44, 0, 18], [2.65, 48, 0, 25], [4, 52, 0, 34], [5, 48, 0, 34], [8, 30, 0, 34]]],
        ],
        'ball' => [[0, 39, 1, 26], [3.2, 40, 2, 26], [4, 52, 1, 34], [5, 48, 1, 34], [8, 30, 1, 34]],
        'ballHolders' => [[0, 'offense', 'C'], [.35, 'offense', 'QB'], [3.2, null, null], [4, 'defense', 'CB1']],
        'events' => [[0, 'Snap'], [3.2, 'Pass in flight'], [4, 'Interception'], [8, 'Return finish']],
    ];
}

test('interceptor covers the nearest receiver until after the actual release without knowing the catch point', function () {
    $original = reactionFixture();
    $changed = reactionFixture();
    $changed['players'][2]['path'][3][1] = 70;
    $changed['players'][2]['path'][3][3] = 45;
    $first = InterceptionReactions::apply($original, []);
    $second = InterceptionReactions::apply($changed, []);
    expect($first['coverage_role'])->toBe('WR1')->and($first['interception_react_at'])->toBeGreaterThan(3.2);
    $prefix = fn ($a) => array_values(array_filter($a['players'][2]['path'], fn ($p) => $p[0] <= $a['interception_react_at']));
    expect($prefix($first))->toBe($prefix($second));
    foreach ($prefix($first) as $point) {
        expect(abs($point[3] - 9))->toBeLessThan(1e-9);
    }
    expect($first)->toBe(InterceptionReactions::apply($original, []));
});

test('reaction movement is speed bounded and keeps the exact catch and INT1 return', function () {
    $before = reactionFixture();
    $animation = InterceptionReactions::apply($before, []);
    $path = $animation['players'][2]['path'];
    foreach ($path as $i => $point) {
        if ($i === 0 || $point[0] > $animation['catch_at']) {
            continue;
        }
        $previous = $path[$i - 1];
        expect(hypot($point[1] - $previous[1], $point[3] - $previous[3]))
            ->toBeLessThanOrEqual(8.95 * ($point[0] - $previous[0]) + 1e-7);
    }
    $delta = $animation['catch_at'] - $before['catch_at'];
    expect($delta)->toBeGreaterThan(0)->and($animation['throw_at'])->toBe(3.2);
    $return = array_values(array_filter($path, fn ($p) => $p[0] >= $animation['catch_at']));
    $oldReturn = array_slice($before['players'][2]['path'], 3);
    expect(count($return))->toBe(count($oldReturn));
    foreach ($return as $i => $point) {
        expect(array_slice($point, 1))->toBe(array_slice($oldReturn[$i], 1));
        expect(abs($point[0] - $oldReturn[$i][0] - $delta))->toBeLessThan(1e-7);
    }
    $holder = $animation['ballHolders'][array_key_last($animation['ballHolders'])];
    expect($holder)->toBe([$animation['catch_at'], 'defense', 'CB1']);
    expect(array_slice($animation['ball'][array_key_last($animation['ball'])], 1))->toBe([30, 1, 34]);
    expect($before['catch_at'])->toBe(4.0);
});

test('ordinary completions sacks fumbles and no-snap events keep their saved paths', function () {
    foreach ([['outcome' => 'tackle'], ['outcome' => 'fumble'], ['outcome' => 'sack', 'passing' => false], ['no_snap' => true], ['fake_kick' => true]] as $override) {
        $animation = array_replace(reactionFixture(), $override);
        expect(InterceptionReactions::apply($animation, []))->toBe($animation);
    }
});
