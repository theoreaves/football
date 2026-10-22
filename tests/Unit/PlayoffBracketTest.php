<?php

use App\Services\Seasons\PlayoffBracket;

function bracketSeeds(int $count, string $prefix): array
{
    return array_map(fn ($i) => ['seed' => $i, 'team_id' => $i, 'name' => $prefix.$i], range(1, $count));
}

test('saved postseason opening brackets pair high seeds at home and record byes', function () {
    $service = new PlayoffBracket();
    foreach (['2' => [2, 0, 1], '4' => [4, 0, 2], '8' => [8, 0, 4],
              'nfl10' => [5, 3, 1], 'nfl12' => [6, 2, 2], 'nfl14' => [7, 1, 3]] as $format => [$seeds, $byes, $games]) {
        $input = ['format' => $format, 'conferences' => ['A' => bracketSeeds($seeds, 'Team')]];
        $bracket = $service->openingRound($input)['conferences']['A'];
        expect($bracket['byes'])->toHaveCount($byes);
        expect($bracket['games'])->toHaveCount($games);
        expect($bracket['games'][0]['home_seed'])->toBe($byes + 1);
        expect($bracket['games'][0]['away_seed'])->toBe($seeds);
        foreach ($bracket['games'] as $game) {
            expect($game['home_seed'])->toBeLessThan($game['away_seed']);
        }
    }
});

test('opening bracket is deterministic and keeps conferences isolated', function () {
    $service = new PlayoffBracket();
    $input = ['format' => 'nfl14', 'conferences' => ['A' => bracketSeeds(7, 'A'), 'B' => array_map(
        fn ($entry) => array_replace($entry, ['team_id' => $entry['team_id'] + 100]), bracketSeeds(7, 'B'))]];
    expect($service->openingRound($input))->toEqual($service->openingRound($input));
    expect($service->openingRound($input)['conferences']['B']['games'][0]['home_team_id'])->toBe(102);
});
