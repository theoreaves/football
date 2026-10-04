<?php

use App\Models\Player;
use App\Services\Simulation\PlayerRatings;

test('current ratings respect saved values without STF attributes', function () {
    $player = new Player(['position' => 'QB', 'simulation_ratings' => ['throwing' => 99]]);
    $ratings = (new PlayerRatings)->forPlayer($player);
    expect($ratings['throwing'])->toBe(99)->and(array_keys($ratings))->toBe(PlayerRatings::FIELDS);
});
