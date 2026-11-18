<?php

use App\Services\Arcade\FullTeamSetup;
use App\Services\Simulation\PenaltyRules;

test('expanded Arcade pass calls retain canonical seeded pass interference eligibility', function (string $call) {
    // Fixed seed 4/version 0 produces canonical live roll 7 (the DPI band).
    $state = ['rules' => ['penalties' => true], 'phase' => 'scrimmage', 'seed' => 4, 'version' => 0];
    $play = ['source' => 'arcade', 'kind' => FullTeamSetup::kind($call), 'call' => $call, 'carrier' => 'WR1', 'outcome' => 'incomplete'];
    $rules = new PenaltyRules;
    expect($rules->live($state, $play))->toBe($play['kind'] === 'pass' ? 'defensive_pass_interference' : null);
    $play['carrier'] = 'QB';
    expect($rules->live($state, $play))->toBeNull();
})->with(FullTeamSetup::PLAYBOOK_CALLS);

test('non-Arcade unknown calls cannot opt into the catalog penalty classification', function () {
    $state = ['rules' => ['penalties' => true], 'phase' => 'scrimmage', 'seed' => 4, 'version' => 0];
    $play = ['kind' => 'pass', 'call' => 'four_verticals', 'carrier' => 'WR1', 'outcome' => 'incomplete'];
    $rules = new PenaltyRules;
    expect($rules->live($state, $play))->toBeNull();
    $play['call'] = 'short_pass';
    expect($rules->live($state, $play))->toBe('defensive_pass_interference');
});
