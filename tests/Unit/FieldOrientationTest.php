<?php

use App\Services\Simulation\FieldOrientation;

test('teams switch ends every quarter without changing relative field position', function () {
    foreach ([1 => 1, 2 => -1, 3 => 1, 4 => -1] as $quarter => $direction) {
        $state = ['quarter' => $quarter, 'possession' => 'home', 'spot' => 30];
        expect(FieldOrientation::direction($state))->toBe($direction)
            ->and(FieldOrientation::line($state))->toBe($direction === 1 ? 40.0 : 80.0);
        $state['possession'] = 'away';
        expect(FieldOrientation::direction($state))->toBe(-$direction);
    }
});

test('changed ends reflect players ball and field markers together', function () {
    $base = ['players' => [['path' => [[0, 40, 0, 10], [6, 60, 0, 15]]]], 'ball' => [[0, 40, 1, 20]], 'line' => 40, 'firstDown' => 50];
    $flipped = FieldOrientation::animation($base, ['quarter' => 2, 'possession' => 'home']);
    expect($flipped['players'][0]['path'][0][1])->toBe(80)->and($flipped['ball'][0][1])->toBe(80)
        ->and($flipped['firstDown'])->toBe(70)->and($flipped['direction'])->toBe(-1)
        ->and($base['line'])->toBe(40);
});
