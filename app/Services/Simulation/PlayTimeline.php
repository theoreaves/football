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
        $pass = in_array($play['call'], ['slant', 'deep_pass'], true) && $play['outcome'] !== 'sack';
        $special = in_array($play['call'], ['punt', 'field_goal'], true);
        $endZ = $pass ? ($play['call'] === 'deep_pass' ? 9 : 20) : ($play['call'] === 'outside_run' ? 42 : 27);
        if (($play['defense'] ?? '') === 'blitz') {
            $defense['LB2'][0] = 2;
        }
        if (($play['defense'] ?? '') === 'run_commit') {
            $defense['LB1'][0] = 3;
            $defense['LB3'][0] = 3;
        }
        if (($play['defense'] ?? '') === 'coverage') {
            $defense['CB1'][0] = 6;
            $defense['CB2'][0] = 6;
        }
        $tracks = [];
        foreach ($offense as $role => [$x, $z]) {
            $path = [$point(0, $x, $z), $point(1, $x + ($role === 'QB' ? -2 : 1), $z), $point(6, $x + 2, $z)];
            if ($role === 'QB') {
                $path = [$point(0, -5, 26.7), $point(.6, -6, 26.7), $point(2.2, -7, 26.7), $point(6, -7, 26.7)];
            }
            if ($role === 'WR1' && $pass) {
                $end = $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'];
                $path = [$point(0, $x, $z), $point(2.2, $play['target'] * .45, $z), $point(3.8, $play['target'], $endZ), $point(5.3, $end, $endZ), $point(6, $end, $endZ)];
            }
            if ($role === 'RB' && ! $pass && ! $special && $play['carrier'] === 'RB') {
                $path = [$point(0, -7, 29), $point(1, -6, 27), $point(5.3, $play['gain'], $endZ), $point(6, $play['gain'], $endZ)];
            }
            if ($role === 'QB' && $play['carrier'] === 'QB') {
                $path = [$point(0, -5, 26.7), $point(2, -7, 26.7), $point(5.3, $play['gain'], 26.7), $point(6, $play['gain'], 26.7)];
                $endZ = 26.7;
            }
            if (in_array($role, ['C', 'LG', 'RG', 'LT', 'RT'], true)) {
                $path = [$point(0, $x, $z), $point(1.2, .3, $z), $point(5.3, $pass ? .3 : 2, $z), $point(6, $pass ? .3 : 2, $z)];
            }
            $person = $rosters[$side]['players'][$special && $role === 'QB' ? $play['carrier'] : $role];
            $tracks[] = array_merge(array_intersect_key($person, array_flip(['id', 'name', 'number'])), ['role' => $role, 'team' => 'offense', 'side' => $side, 'path' => $path]);
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
            $tracks[] = array_merge(array_intersect_key($rosters[$other]['players'][$role], array_flip(['id', 'name', 'number'])), ['role' => $role, 'team' => 'defense', 'side' => $other, 'path' => $path]);
        }
        $ball = [$point(0, -1, 26.7, 1), $point(.35, -5 - .35 / .6, 26.7, 1), $point(.6, -6, 26.7, 1)];
        if ($special) {
            $landing = $play['call'] === 'punt' ? $play['gain'] : 110 - $play['before']['spot'];
            $landingZ = $play['outcome'] === 'field_goal_missed' ? 39 : 26.7;
            $ball = array_merge($ball, [$point(1.2, -7, 26.7, 1), $point(3, $landing / 2, $landingZ, 14), $point(5.3, $landing, $landingZ, $play['outcome'] === 'field_goal_good' ? 5 : 0), $point(6, $landing, $landingZ, $play['outcome'] === 'field_goal_good' ? 5 : 0)]);
        } elseif ($pass) {
            $ball = array_merge($ball, [$point(2.2, -7, 26.7, 1), $point(3, ($play['target'] - 7) / 2, (26.7 + $endZ) / 2, 7),
                $point(3.8, $play['target'], $endZ, $play['outcome'] === 'incomplete' ? 0 : 1),
                $point(5.3, $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'], $endZ, $play['outcome'] === 'incomplete' ? 0 : 1),
                $point(6, $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'], $endZ, $play['outcome'] === 'incomplete' ? 0 : 1)]);
        } else {
            $ball = array_merge($ball, [$point(1, -6, 27, 1), $point(5.3, $play['gain'], $endZ, 1), $point(6, $play['gain'], $endZ, 1)]);
            if ($play['carrier'] === 'QB') {
                $ball = [$point(0, -1, 26.7, 1), $point(.35, -5.35, 26.7, 1), $point(2, -7, 26.7, 1), $point(5.3, $play['gain'], 26.7, 1), $point(6, $play['gain'], 26.7, 1)];
            }
        }
        $events = [[0, 'Snap'], [.6, $special ? 'Kick setup' : ($pass || $play['carrier'] === 'QB' ? 'Dropback' : 'Handoff')], [2.2, $special ? 'Kick in flight' : ($pass ? 'Pass in flight' : 'Run')], [3.8, $pass ? match ($play['outcome']) {
            'incomplete' => 'Incomplete pass', 'interception' => 'Intercepted', default => 'Catch'
        } : 'Pursuit'], [5.3, $play['summary']]];

        return ['duration' => 6, 'players' => $tracks, 'ball' => $ball, 'events' => $events, 'line' => $line,
            'firstDown' => max(10, min(110, $line + $direction * $play['before']['distance'])), 'possession' => $side];
    }
}
