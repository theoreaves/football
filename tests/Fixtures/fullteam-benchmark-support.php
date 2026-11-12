<?php

use App\Models\Exhibition;
use App\Services\Arcade\ArcadeContext;
use App\Services\Arcade\FullTeamLivePlayContract;
use App\Services\Arcade\FullTeamSetup;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GamePersonnel;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\PlayFinalizer;
use App\Services\Simulation\RosterBuilder;
use App\Services\Simulation\ScrimmageAccounting;

/** Synthetic equal-70 snapshots, passed through the production setup/personnel API. No database. */
function fullteamBenchmarkFixture(string $call, int $direction = 1, int $seed = 42, string $variant = 'baseline', ?array $savedFixture = null): array
{
    $rosters = [];
    foreach (['home', 'away'] as $side) {
        foreach (RosterBuilder::GROUPS as $role => $positions) {
            $id = ($side === 'home' ? 0 : 100) + count($rosters[$side]['players'] ?? []) + 1;
            $ratings = array_fill_keys(PlayerRatings::FIELDS, 70);
            $change = match ($variant) {
                'lg_blocking_90' => ['home', 'LG', 'blocking'],
                'qb_throwing_90' => ['home', 'QB', 'throwing'],
                'wr1_catching_90' => ['home', 'WR1', 'catching'],
                'lb2_tackling_90' => ['away', 'LB2', 'tackling'],
                default => null,
            };
            if ($change && [$side, $role] === array_slice($change, 0, 2)) {
                if (! array_key_exists($change[2], $ratings)) {
                    throw new RuntimeException('Unknown controlled rating '.$change[2]);
                }
                $ratings[$change[2]] = 90;
            }
            $player = ['id' => $id, 'name' => $side.' '.$role, 'firstname' => $side, 'lastname' => $role, 'number' => $id % 100, 'position' => $positions[0], 'depth' => $role, 'ratings' => $ratings];
            $rosters[$side]['players'][$role] = $player;
            $rosters[$side]['pool'][] = $player;
        }
    }
    if ($savedFixture !== null) {
        if ($variant !== 'baseline') {
            throw new RuntimeException('Saved roster benchmarks do not alter ratings.');
        }
        $rosters = $savedFixture['rosters'];
    }
    $state = app(ExhibitionEngine::class)->initial(300, $seed, false);
    $state['quarter'] = $direction === 1 ? 1 : 2;
    $state['spot'] = 35;
    $state['team_appearance'] = $savedFixture['appearance'] ?? [];
    $state['rules'] = ['penalties' => false, 'injuries' => false, 'overtime' => 'none'];
    $state['controls'] = ['home' => 'human', 'away' => 'human'];
    $rosters = app(GamePersonnel::class)->active($rosters, $state, $call, 'shotgun');
    $game = new Exhibition(['state' => $state, 'rosters' => $rosters]);
    $setup = app(FullTeamSetup::class)->build($game, $state, $rosters, $call);

    return compact('state', 'rosters', 'setup', 'variant', 'seed');
}

function fullteamBenchmarkRun(array $fixture, string $profile = 'decisions'): array
{
    $process = proc_open(['node', base_path('tests/Fixtures/fullteam-benchmark-physics.mjs')], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start full-team benchmark');
    }
    fwrite($pipes[0], json_encode(['setup' => $fixture['setup'], 'profile' => $profile], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException('Full-team fixture failed: '.$error);
    }

    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

function fullteamBenchmarkValidate(array $fixture, array $recording): array
{
    $context = new ArcadeContext(1, 1, 1, $fixture['state']['possession'], $fixture['state'], $fixture['rosters'], $fixture['setup']['actors']);
    $started = hrtime(true);
    $resolved = app(FullTeamLivePlayContract::class)->derive($context, $recording, $fixture['setup']);
    $validationMs = (hrtime(true) - $started) / 1e6;
    $snap = $fixture['state'];
    $accounted = app(ScrimmageAccounting::class)->apply($snap, $snap, $resolved['outcome'], $resolved['gain'], $resolved['flip'], $resolved['summary']);
    $play = $resolved;
    unset($play['recording'], $play['animation'], $play['elapsed_seconds']);
    $play['outcome'] = $accounted['outcome'];
    $play['summary'] = $accounted['summary'];
    $play += ['defense' => 'man_to_man', 'offense_formation' => 'shotgun', 'defense_formation' => 'base_4_3', 'target' => 0];
    $result = app(PlayFinalizer::class)->finish($accounted['state'], $snap, $play, $fixture['rosters'], $resolved['elapsed_seconds'], $resolved['animation']);

    return compact('resolved', 'result', 'validationMs');
}
