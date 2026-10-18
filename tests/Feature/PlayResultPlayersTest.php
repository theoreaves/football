<?php

use App\Services\Simulation\PlayResultPlayers;
use App\Support\PlayAnnouncement;

test('result cards follow saved sack and scramble participants without unused targets', function () {
    $rosters = [];
    foreach (['home', 'away'] as $side) {
        foreach (['QB', 'RB', 'WR1', 'CB1', 'LB2'] as $i => $role) {
            $rosters[$side]['players'][$role] = ['id' => ($side === 'home' ? 0 : 10) + $i, 'name' => "$side $role", 'number' => $i];
        }
        $rosters[$side]['pool'] = array_values($rosters[$side]['players']);
    }
    $play = ['before' => ['possession' => 'home'], 'call' => 'screen', 'outcome' => 'sack', 'carrier' => 'QB', 'receiver_role' => 'RB'];
    $play['animation']['players'] = [['id' => 14, 'side' => 'away', 'role' => 'LB2', 'name' => 'Actual substitute', 'number' => 32]];
    $cards = app(PlayResultPlayers::class)->forPlay($rosters, $play);
    expect(array_column($cards, 'role'))->toBe(['QB', 'LB2'])
        ->and($cards[1]['player']['name'])->toBe('Actual substitute');
    $play['outcome'] = 'tackle';
    $play['scramble'] = true;
    expect(array_column(app(PlayResultPlayers::class)->forPlay($rosters, $play), 'role'))->toBe(['QB', 'LB2']);
    $play['scramble'] = false;
    $play['call'] = 'slant';
    $play['carrier'] = 'WR1';
    $play['receiver_role'] = 'WR1';
    $play['gain'] = 19;
    expect(PlayAnnouncement::titles($play))->toBe(['PASS COMPLETE: 19 Yards'])
        ->and(array_column(app(PlayResultPlayers::class)->forPlay($rosters, $play), 'role'))->toBe(['QB', 'WR1', 'CB1']);
    $play['outcome'] = 'incomplete';
    $play['throwaway'] = true;
    expect(array_column(app(PlayResultPlayers::class)->forPlay($rosters, $play), 'role'))->toBe(['QB']);
    $play['no_snap'] = true;
    expect(app(PlayResultPlayers::class)->forPlay($rosters, $play))->toBe([]);
});
