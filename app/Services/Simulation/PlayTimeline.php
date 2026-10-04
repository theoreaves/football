<?php

namespace App\Services\Simulation;

class PlayTimeline
{
    public function build(array $play, array $rosters): array
    {
        $side = $play['before']['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $direction = $side === 'home' ? 1 : -1;
        $line = $side === 'home' ? 10 + $play['before']['spot'] : 110 - $play['before']['spot'];
        $point = fn ($t, $x, $z, $y = 0) => [$t, max(0, min(120, $line + $direction * $x)), $y, max(0, min(53.33, $z))];
        $offense = ['QB' => [-5, 26.7], 'C' => [-1, 26.7], 'LG' => [-1, 24.5], 'RG' => [-1, 28.9], 'LT' => [-1, 22.3], 'RT' => [-1, 31.1], 'RB' => [-7, 29], 'TE' => [-1, 34], 'WR1' => [-1, 9], 'WR2' => [-1, 43], 'WR3' => [-3, 16]];
        $defense = ['DE1' => [1, 22], 'DT1' => [1, 25], 'DT2' => [1, 28], 'DE2' => [1, 31], 'LB1' => [5, 22], 'LB2' => [5, 27], 'LB3' => [5, 33], 'CB1' => [3, 9], 'CB2' => [3, 43], 'S1' => [11, 20], 'S2' => [12, 34]];
        $offenseFormation = $play['offense_formation'] ?? 'shotgun';
        if ($offenseFormation === 'singleback') {
            $offense['QB'][0] = -2;
            $offense['RB'] = [-7, 26.7];
        }
        if ($offenseFormation === 'spread') {
            $offense['WR1'][1] = 4;
            $offense['WR2'][1] = 49;
            $offense['TE'][1] = 39;
        }
        if (($play['defense_formation'] ?? '') === 'two_high') {
            $defense['S1'] = [14, 16];
            $defense['S2'] = [14, 38];
        }
        if (($play['defense_formation'] ?? '') === 'single_high') {
            $defense['S1'] = [14, 26.7];
            $defense['S2'] = [4, 34];
        }
        if (($play['defense_formation'] ?? '') === 'base_3_5') {
            $defense['DE1'] = [1, 21];
            $defense['DT1'] = [1, 27];
            $defense['DE2'] = [1, 33];
            $defense['DT2'] = [4, 16];
            $defense['S2'] = [4, 38];
        }
        if (($play['defense_formation'] ?? '') === 'nickel') {
            $defense['LB3'] = [5, 16];
        }
        if (($play['defense_formation'] ?? '') === 'base_3_5') {
            foreach (['DT2' => 'LB4', 'S2' => 'LB5'] as $old => $new) {
                if (isset($rosters[$other]['players'][$new])) {
                    $defense[$new] = $defense[$old];
                    unset($defense[$old]);
                }
            }
        }
        if (($play['defense_formation'] ?? '') === 'nickel' && isset($rosters[$other]['players']['CB3'])) {
            $defense['CB3'] = $defense['LB3'];
            unset($defense['LB3']);
        }
        $qbStart = $offense['QB'][0];
        $qbSet = $qbStart - 2;
        $handoff = $qbStart - 1;
        $pass = in_array($play['call'], ['slant', 'short_pass', 'medium_pass', 'deep_pass', 'two_point_pass'], true) && $play['carrier'] !== 'QB';
        $special = in_array($play['call'], ['punt', 'field_goal', 'kickoff', 'extra_point'], true);
        $endZ = $pass ? match ($play['call']) {
            'deep_pass' => 9, 'short_pass' => 8, 'medium_pass' => 14, default => 20
        } : ($play['call'] === 'outside_run' ? 42 : 27);
        if ($play['out_of_bounds'] ?? false) {
            $endZ = $endZ < 26.7 ? 0 : 53.33;
        }
        if (($play['defense'] ?? '') === 'blitz') {
            $defense['LB2'][0] = 2;
        }
        if (($play['defense'] ?? '') === 'run_stop') {
            foreach (array_keys($defense) as $role) {
                if (str_starts_with($role, 'LB') || $role === 'S2') {
                    $defense[$role][0] = 1.5;
                }
            }
        }
        if (($play['defense'] ?? '') === 'zone') {
            $defense['CB1'][0] = 6;
            $defense['CB2'][0] = 6;
        }
        $tracks = [];
        foreach ($offense as $role => [$x, $z]) {
            $path = [$point(0, $x, $z), $point(1, $x + ($role === 'QB' ? -2 : 1), $z), $point(6, $x + 2, $z)];
            if ($role === 'QB') {
                $path = [$point(0, $qbStart, 26.7), $point(.6, $handoff, 26.7), $point(2.2, $qbSet, 26.7), $point(6, $qbSet, 26.7)];
            }
            if ($role === 'WR1' && $pass) {
                $end = $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'];
                $path = [$point(0, $x, $z), $point(2.2, $play['target'] * .45, $z), $point(3.8, $play['target'], $endZ), $point(5.3, $end, $endZ), $point(6, $end, $endZ)];
            }
            if ($role === 'RB' && ! $pass && ! $special && $play['carrier'] === 'RB') {
                $path = [$point(0, $x, $z), $point(1, $handoff, 27), $point(5.3, $play['gain'], $endZ), $point(6, $play['gain'], $endZ)];
            }
            if ($role === 'QB' && $play['carrier'] === 'QB') {
                $path = [$point(0, $qbStart, 26.7), $point(2, $qbSet, 26.7), $point(5.3, $play['gain'], 26.7), $point(6, $play['gain'], 26.7)];
                $endZ = 26.7;
            }
            if (in_array($role, ['C', 'LG', 'RG', 'LT', 'RT'], true)) {
                $path = [$point(0, $x, $z), $point(1.2, .3, $z), $point(5.3, $pass ? .3 : 2, $z), $point(6, $pass ? .3 : 2, $z)];
            }
            $person = $rosters[$side]['players'][$special && $role === 'QB' ? $play['carrier'] : $role];
            $tracks[] = array_merge(array_intersect_key($person, array_flip(['id', 'name', 'number', 'height_inches', 'weight_pounds', 'skin_tone'])), ['role' => $role, 'team' => 'offense', 'side' => $side, 'path' => $path]);
        }
        foreach ($defense as $role => [$x, $z]) {
            $tackler = $pass ? 'CB1' : 'LB2';
            $end = $play['gain'];
            $path = [$point(0, $x, $z), $point(3, $x + ($pass ? 5 : -1), $z), $point(6, $x + 3, $z)];
            if (in_array($role, ['DE1', 'DT1', 'DT2', 'DE2'], true)) {
                $path = [$point(0, $x, $z), $point(1.2, .8, $z), $point(5.3, $pass ? .8 : 2.5, $z), $point(6, $pass ? .8 : 2.5, $z)];
            }
            if ($role === $tackler && ! $special && $play['outcome'] !== 'incomplete') {
                $path = [$point(0, $x, $z), $point(3.8, $pass ? $play['target'] : $end * .7, $endZ + ($play['outcome'] === 'interception' ? 0 : 1)), $point(5.3, $end, $endZ), $point(6, $end, $endZ)];
            }
            $tracks[] = array_merge(array_intersect_key($rosters[$other]['players'][$role], array_flip(['id', 'name', 'number', 'height_inches', 'weight_pounds', 'skin_tone'])), ['role' => $role, 'team' => 'defense', 'side' => $other, 'path' => $path]);
        }
        $ball = [$point(0, -1, 26.7, 1), $point(.35, $qbStart - .35 / .6, 26.7, 1), $point(.6, $handoff, 26.7, 1)];
        if ($special) {
            $landing = $play['call'] === 'punt' ? $play['gain'] : 110 - $play['before']['spot'];
            $landingZ = $play['outcome'] === 'field_goal_missed' ? 39 : 26.7;
            $ball = array_merge($ball, [$point(1.2, $qbSet, 26.7, 1), $point(3, $landing / 2, $landingZ, 14), $point(5.3, $landing, $landingZ, $play['outcome'] === 'field_goal_good' ? 5 : 0), $point(6, $landing, $landingZ, $play['outcome'] === 'field_goal_good' ? 5 : 0)]);
        } elseif ($pass) {
            $ball = array_merge($ball, [$point(1.8, $qbSet, 26.7, 1.8), $point(2.2, $qbSet + .5, 26.7, 2), $point(3, ($play['target'] + $qbSet) / 2, (26.7 + $endZ) / 2, 7),
                $point(3.8, $play['target'], $endZ, $play['outcome'] === 'incomplete' ? 0 : 1),
                $point(5.3, $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'], $endZ, $play['outcome'] === 'incomplete' ? 0 : 1),
                $point(6, $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'], $endZ, $play['outcome'] === 'incomplete' ? 0 : 1)]);
        } else {
            $ball = array_merge($ball, [$point(1, $handoff, 27, 1), $point(5.3, $play['gain'], $endZ, 1), $point(6, $play['gain'], $endZ, 1)]);
            if ($play['carrier'] === 'QB') {
                $ball = [$point(0, -1, 26.7, 1), $point(.35, $qbStart - .35, 26.7, 1), $point(2, $qbSet, 26.7, 1), $point(5.3, $play['gain'], 26.7, 1), $point(6, $play['gain'], 26.7, 1)];
            }
        }
        $events = [[0, 'Snap'], [.6, $special ? 'Kick setup' : ($pass || $play['carrier'] === 'QB' ? 'Dropback' : 'Handoff')], [2.2, $special ? 'Kick in flight' : ($pass ? 'Pass in flight' : 'Run')], [3.8, $pass ? match ($play['outcome']) {
            'incomplete' => 'Incomplete pass', 'interception' => 'Intercepted', default => 'Catch'
        } : 'Pursuit'], [5.3, $play['summary']]];

        $holders = [[0, 'offense', 'C'], [.01, null, null], [.35, 'offense', 'QB']];
        if ($special) {
            $holders[] = [1.2, null, null];
        } elseif ($pass) {
            $holders[] = [2.2, null, null];
            $holders[] = [3.8, $play['outcome'] === 'incomplete' ? null : ($play['outcome'] === 'interception' ? 'defense' : 'offense'), $play['outcome'] === 'interception' ? 'CB1' : 'WR1'];
        } elseif ($play['carrier'] === 'RB') {
            $holders[] = [.6, null, null];
            $holders[] = [1, 'offense', 'RB'];
        }
        if ($play['outcome'] === 'fumble') {
            $holders[] = [5.3, 'defense', $play['carrier'] === 'WR1' ? 'CB1' : 'LB2'];
        }

        if ($play['call'] === 'spike') {
            $ball = [$point(0, -1, 26.7, 1), $point(.35, $qbStart, 26.7, 1), $point(1, $qbStart, 26.7, .25), $point(6, $qbStart, 26.7, .25)];
            $holders = [[0, 'offense', 'C'], [.01, null, null], [.35, 'offense', 'QB'], [1, null, null]];
            $events = [[0, 'Snap'], [1, 'Spike · clock stopped'], [5.3, $play['summary']]];
        } elseif ($play['call'] === 'kneel') {
            $events = [[0, 'Snap'], [2, 'Quarterback takes a knee'], [5.3, $play['summary']]];
        }

        if ($play['defensive_return'] ?? false) {
            $returner = $pass ? 'CB1' : 'LB2';
            $goal = $side === 'home' ? 10 : 110;
            foreach ($tracks as &$track) {
                if ($track['team'] === 'defense' && $track['role'] === $returner) {
                    $track['path'] = [$track['path'][0], $point(3.8, $pass ? $play['target'] : $play['gain'], $endZ), [5.3, $goal, 0, $endZ], [6, $goal, 0, $endZ]];
                }
            }
            unset($track);
            $ball = array_values(array_filter($ball, fn ($p) => $p[0] < 3.8));
            $ball[] = $point(3.8, $pass ? $play['target'] : $play['gain'], $endZ, 1);
            $ball[] = [5.3, $goal, 1, $endZ];
            $ball[] = [6, $goal, 1, $endZ];
            $holders = array_values(array_filter($holders, fn ($p) => $p[0] < 3.8));
            $holders[] = [3.8, 'defense', $returner];
        }

        return ['dropback' => in_array($play['call'], ['slant', 'short_pass', 'medium_pass', 'deep_pass', 'two_point_pass'], true), 'passing' => $pass, 'throw_at' => 2.2, 'call' => $play['call'], 'duration' => 6, 'players' => $tracks, 'ball' => $ball, 'ballHolders' => $holders, 'events' => $events, 'line' => $line,
            'firstDown' => max(10, min(110, $line + $direction * $play['before']['distance'])), 'possession' => $side];
    }
}
