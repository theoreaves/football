<?php

namespace App\Services\Arcade;

use App\Models\Exhibition;
use App\Services\Simulation\FieldOrientation;

/** Pinned initial assignments, not a second simulated outcome. */
final class FullTeamSetup
{
    public const CALLS = ['inside_run', 'outside_run', 'short_pass', 'medium_pass', 'deep_pass'];

    public const LIMITS = ['actors' => 22, 'frames' => 858, 'events' => 256, 'bytes' => 2097152, 'nodes' => 250000, 'seconds' => 20];

    public const OFFENSE = ['QB' => [-5, 26.7], 'C' => [-1, 26.7], 'LG' => [-1, 24.5], 'RG' => [-1, 28.9], 'LT' => [-1, 22.3], 'RT' => [-1, 31.1], 'RB' => [-7, 29], 'TE' => [-1, 34], 'WR1' => [-1, 9], 'WR2' => [-1, 43], 'WR3' => [-3, 16]];

    public const DEFENSE = ['DE1' => [1, 22], 'DT1' => [1, 25], 'DT2' => [1, 28], 'DE2' => [1, 31], 'LB1' => [5, 22], 'LB2' => [5, 27], 'LB3' => [5, 33], 'CB1' => [3, 9], 'CB2' => [3, 43], 'S1' => [11, 20], 'S2' => [12, 34]];

    public function build(Exhibition $game, array $state, array $rosters, string $call): array
    {
        abort_unless(in_array($call, self::CALLS, true), 422, 'Unsupported Arcade call.');
        $side = $state['possession'];
        $direction = FieldOrientation::direction($state);
        $line = FieldPosition::physical($state['spot'], $state);
        $actors = $players = $seen = [];
        foreach (['offense' => self::OFFENSE, 'defense' => self::DEFENSE] as $unit => $roles) {
            $team = $unit === 'offense' ? $side : ($side === 'home' ? 'away' : 'home');
            foreach ($roles as $role => [$offset, $z]) {
                $player = $rosters[$team]['players'][$role] ?? null;
                abort_unless($player && ($player['id'] ?? 0) > 0 && empty($player['emergency']) && ! isset($seen[$player['id']]), 409, 'A full Arcade lineup needs 22 distinct real active players. Continue in Coach.');
                $injury = $state['injuries'][$team][$player['id']] ?? null;
                abort_if($injury && ($injury['return_snap'] === null || ($state['personnel_snaps'] ?? 0) < $injury['return_snap']), 409, 'An assigned player is unavailable. Continue in Coach.');
                $seen[$player['id']] = true;
                $key = $unit.'_'.$role;
                $x = max(0, min(120, $line + $direction * $offset));
                $actors[$key] = ['replay_key' => $key, 'player_id' => (int) $player['id'], 'side' => $team, 'role' => $role, 'x' => $x, 'y' => $z];
                $players[] = array_replace($player, ['key' => $key, 'side' => $team, 'role' => $role, 'x' => $x, 'z' => $z]);
            }
        }
        $running = in_array($call, ['inside_run', 'outside_run'], true);

        return ['fullTeam' => true, 'contractVersion' => 'live-recording-2', 'catalogVersion' => 'arcade-fullteam-1', 'limits' => self::LIMITS,
            'kind' => $running ? 'run' : 'pass', 'mode' => $running ? 'running' : 'passing', 'call' => $call, 'offenseSide' => $side,
            'direction' => $direction, 'seed' => LivePlaySeed::forState($state), 'speedScale' => .925, 'routeSet' => $running ? null : $call,
            'offenseFormation' => 'shotgun', 'defenseFormation' => 'base_4_3', 'eligibleReceiverKeys' => ['offense_WR1', 'offense_WR2', 'offense_WR3', 'offense_TE', 'offense_RB'],
            'actors' => $actors, 'players' => $players, 'appearance' => $game->state['team_appearance'] ?? [],
            'exhibition' => ['startX' => $line, 'goalX' => FieldPosition::physical(100, $state), 'rearX' => FieldPosition::physical(0, $state), 'firstDownX' => FieldPosition::physical(min(100, $state['spot'] + $state['distance']), $state)]];
    }
}
