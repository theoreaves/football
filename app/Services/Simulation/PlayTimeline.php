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
        // Visual follow-through is separate from the recorded scoring yardage.
        if ($play['outcome'] === 'touchdown') {
            $play['gain'] = min(108 - $play['before']['spot'], max($play['gain'], $play['target']) + 3);
        } elseif ($play['outcome'] === 'interception' && $play['before']['spot'] + $play['target'] >= 100) {
            $play['gain'] = $play['target'];
        }
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
        if ($offenseFormation === 'i_form') {
            $offense['QB'][0] = -2;
            $offense['RB'] = [-7, 26.7];
            $offense['WR3'] = [-4, 26.7];
        }
        if ($offenseFormation === 'pistol') {
            $offense['QB'][0] = -4;
            $offense['RB'] = [-8, 26.7];
        }
        if ($offenseFormation === 'trips') {
            $offense['WR1'][1] = 40;
            $offense['WR2'][1] = 47;
            $offense['WR3'] = [-2, 35];
        }
        if (($play['defense_formation'] ?? '') === 'base_3_4') {
            $defense['DT2'] = [4, 33];
            $defense['LB3'] = [4, 17];
        }
        if (($play['defense_formation'] ?? '') === 'dime') {
            $defense['LB2'] = [7, 39];
            $defense['LB3'] = [7, 16];
        }
        if (($play['defense_formation'] ?? '') === 'base_3_4' && isset($rosters[$other]['players']['LB4'])) {
            $defense['LB4'] = $defense['DT2'];
            unset($defense['DT2']);
        }
        if (($play['defense_formation'] ?? '') === 'dime') {
            foreach (['LB3' => 'CB3', 'LB2' => 'CB4'] as $old => $new) {
                if (isset($rosters[$other]['players'][$new])) {
                    $defense[$new] = $defense[$old];
                    unset($defense[$old]);
                }
            }
        }
        $motion = $play['motion'] ?? 'none';
        $motionStart = isset($offense[$motion]) ? $offense[$motion][1] : null;
        if ($motionStart !== null) {
            $offense[$motion][1] = $motionStart < 26.7 ? 36 : 17;
            $offense[$motion][0] = min(-2, $offense[$motion][0]);
        }
        $motionDefender = null;
        $motionDefenderStart = null;
        if ($motionStart !== null && in_array($play['defense'] ?? '', ['man_to_man', 'blitz'], true)) {
            $assignments = match ($motion) {
                'WR1' => ['CB1'],
                'WR2' => ['CB2'],
                'WR3' => ['CB3', 'LB3', 'S1'],
                'TE' => ['S2', 'LB5', 'LB3', 'CB3'],
                'RB' => ['LB2', 'CB4', 'LB1'],
                default => [],
            };
            foreach ($assignments as $role) {
                if (isset($defense[$role])) {
                    $motionDefender = $role;
                    // Keep inside leverage as the assigned defender travels with the receiver.
                    $leverage = $motionStart < 26.7 ? 1 : -1;
                    $motionDefenderStart = $motionStart + $leverage;
                    $defense[$role][1] = $offense[$motion][1] + $leverage;
                    break;
                }
            }
        }
        $qbZ = ($play['pressure'] ?? false) && $play['outcome'] !== 'sack' ? 30.7 : 26.7;
        $qbStart = $offense['QB'][0];
        $qbSet = $qbStart - 2;
        $handoff = $qbStart - 1;
        $pass = in_array($play['call'], ['slant', 'short_pass', 'medium_pass', 'deep_pass', 'two_point_pass'], true) && $play['carrier'] !== 'QB' && ! ($play['throwaway'] ?? false);
        $receiverRole = $play['receiver_role'] ?? 'WR1';
        // Select a reproducible animation style from immutable play details. No extra
        // random calls: simulation outcomes, RNG sequence and saved replays stay intact.
        $visualSeed = (int) sprintf('%u', crc32(implode(':', [
            $play['before']['quarter'] ?? 1, $play['before']['clock'] ?? 0,
            $play['before']['spot'] ?? 0, $play['before']['down'] ?? 1,
            $play['call'] ?? '', $play['outcome'] ?? '', $play['gain'] ?? 0,
            $receiverRole, $play['receiver_id'] ?? 0,
        ])));
        $visualVariant = $visualSeed % 12;
        $special = in_array($play['call'], ['punt', 'field_goal', 'kickoff', 'extra_point'], true);
        // Run toward a catch point near the actual receiver, not WR1's fixed sideline.
        $receiverZ = $offense[$receiverRole][1] ?? $offense['WR1'][1];
        $inside = $receiverZ < 26.7 ? 1 : -1;
        $routeStyle = $visualVariant % 6;
        $routeBreak = [0, 3.5, -3.5, 6, -6, 1.5][$routeStyle] * $inside;
        $endZ = $pass ? max(3, min(50, $receiverZ + $inside * match ($play['call']) {
            'slant' => 6, 'medium_pass' => 3, 'deep_pass' => 2, default => 1,
        } + $routeBreak)) : ($play['call'] === 'outside_run' ? 42 : 27);
        // Running lanes vary while remaining within the hash marks and sideline.
        if (! $pass && ! $special && in_array($play['call'], ['inside_run', 'outside_run', 'draw', 'two_point_run'], true)) {
            $endZ = $play['call'] === 'outside_run'
                ? [7, 12, 17, 36, 42, 47][$visualVariant % 6]
                : [21, 24, 26, 28, 30, 33][$visualVariant % 6];
        }
        if ($play['out_of_bounds'] ?? false) {
            $endZ = $endZ < 26.7 ? 0 : 53.33;
        }
        if ((($play['defense'] ?? '') === 'blitz' || ($play['blitz'] ?? false))) {
            $defense['LB1'][0] = 2;
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
        $runnerPath = null;
        $tracks = [];
        foreach ($offense as $role => [$x, $z]) {
            $path = [$point(0, $x, $z), $point(1, $x + ($role === 'QB' ? -2 : 1), $z), $point(6, $x + 2, $z)];
            if ($role === 'QB') {
                $path = [$point(0, $qbStart, 26.7), $point(.6, $handoff, 26.7), $point(2.2, $qbSet, $qbZ), $point(6, $qbSet, $qbZ)];
            }
            if ($role === $receiverRole && $pass) {
                $end = $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'];
                $breakX = $play['target'] * [.32, .42, .5, .6][$visualVariant % 4];
                $breakZ = max(2, min(51, $z + ($endZ - $z) * [.15, .35, .65, .85][$visualVariant % 4]));
                $path = [$point(0, $x, $z), $point(1.35, $breakX * .55, $z),
                    $point(2.2, $breakX, $breakZ), $point(3.8, $play['target'], $endZ),
                    $point(5.3, $end, $endZ), $point(6, $end, $endZ)];
            }
            if ($role === 'RB' && ! $pass && ! $special && $play['carrier'] === 'RB') {
                $cutZ = [22, 25, 29, 32, 35, 39][$visualVariant % 6];
                $path = [$point(0, $x, $z), $point(1, $handoff, 27),
                    $point(2.4, max(0, $play['gain'] * .25), $cutZ),
                    $point(4.1, $play['gain'] * .72, $endZ + ($cutZ - $endZ) * .25),
                    $point(5.3, $play['gain'], $endZ), $point(6, $play['gain'], $endZ)];
            }
            if ($role === 'RB' && ! $pass && ! $special && $play['carrier'] === 'RB') {
                // Keep the ball on the exact RB track once the handoff completes.
                $runnerPath = $path;
            }
            if ($role === 'QB' && $play['carrier'] === 'QB') {
                $path = [$point(0, $qbStart, 26.7), $point(2, $qbSet, 26.7), $point(5.3, $play['gain'], 26.7), $point(6, $play['gain'], 26.7)];
                $endZ = 26.7;
            }
            if (in_array($role, ['C', 'LG', 'RG', 'LT', 'RT'], true)) {
                $path = [$point(0, $x, $z), $point(1.2, .3, $z), $point(5.3, $pass ? .3 : 2, $z), $point(6, $pass ? .3 : 2, $z)];
            }
            $person = $rosters[$side]['players'][$special && $role === 'QB' ? $play['carrier'] : $role];
            $tracks[] = array_merge(array_intersect_key($person, array_flip(['id', 'name', 'lastname', 'number', 'height_inches', 'weight_pounds', 'skin_tone', 'appearance'])), ['role' => $role, 'team' => 'offense', 'side' => $side, 'path' => $path]);
        }
        foreach ($defense as $role => [$x, $z]) {
            $tackler = $pass ? 'CB1' : 'LB2';
            $end = $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'];
            $path = [$point(0, $x, $z), $point(3, $x + ($pass ? 5 : -1), $z), $point(6, $x + 3, $z)];
            if (in_array($role, ['DE1', 'DT1', 'DT2', 'DE2'], true)) {
                $path = [$point(0, $x, $z), $point(1.2, .8, $z), $point(5.3, $pass ? .8 : 2.5, $z), $point(6, $pass ? .8 : 2.5, $z)];
            }
            if ($role === $tackler && ! $special && ($pass || $play['outcome'] !== 'incomplete')) {
                $path = [$point(0, $x, $z), $point(3.8, $pass ? $play['target'] : $end * .7, $endZ + ($play['outcome'] === 'interception' ? 0 : 1)), $point(5.3, $end, $endZ), $point(6, $end, $endZ)];
            }
            if ($role === 'DE1' && ($play['pressure'] ?? false)) {
                $path = [$point(0, $x, $z), $point(1.7, $qbSet + 2, 25), $point(2.2, $qbSet + .7, 26.7), $point(5.3, $play['carrier'] === 'QB' ? $end : $qbSet + 1, $play['carrier'] === 'QB' ? 26.7 : $qbZ), $point(6, $play['carrier'] === 'QB' ? $end : $qbSet + 1, $play['carrier'] === 'QB' ? 26.7 : $qbZ)];
            }
            // Phase 2: deterministic support pursuit; keep the existing primary
            // tackler's track and all ball/receiver tracks exactly as recorded.
            // Defensive returns and special teams use their dedicated paths.
            if (! $special && ! ($play['defensive_return'] ?? false)
                && ! in_array($play['outcome'], ['incomplete', 'sack'], true)
                && ! ($play['throwaway'] ?? false) && $role !== $tackler
                && ! ($role === 'DE1' && ($play['pressure'] ?? false))) {
                $supportRoles = $pass
                    ? (in_array($receiverRole, ['WR2', 'TE'], true) ? ['CB2', 'S2', 'LB3'] : ['S1', 'CB2', 'LB1'])
                    : ['LB1', 'LB3', 'S1', 'S2', 'CB1', 'CB2'];
                $supportIndex = array_search($role, $supportRoles, true);
                if ($supportIndex !== false && $supportIndex < 3) {
                    // Support defenders converge toward, but do not overlap,
                    // the carrier. Offset and reaction vary per saved play.
                    $laneSign = ($supportIndex % 2 === 0) ? 1 : -1;
                    $lag = 0.56 + (($visualVariant + $supportIndex) % 3) * 0.22;
                    $offsetZ = $laneSign * (2.5 + $supportIndex * 1.65);
                    $offsetX = $supportIndex === 0 ? -1.4 : (1.2 + $supportIndex);
                    $pursuitX = $pass ? $play['target'] : $end;
                    $path = [
                        $point(0, $x, $z),
                        $point(1.3 + $lag, $x + (($pursuitX - $x) * .13), $z),
                        $point(3.5 + $lag * .4, $x + (($pursuitX - $x) * .62), $z + (($endZ + $offsetZ - $z) * .57)),
                        $point(5.3, $end + $offsetX, $endZ + $offsetZ),
                        $point(6, $end + $offsetX, $endZ + $offsetZ),
                    ];
                }
            }
            $tracks[] = array_merge(array_intersect_key($rosters[$other]['players'][$role], array_flip(['id', 'name', 'lastname', 'number', 'height_inches', 'weight_pounds', 'skin_tone', 'appearance'])), ['role' => $role, 'team' => 'defense', 'side' => $other, 'path' => $path]);
        }
        $ball = [$point(0, -1, 26.7, 1), $point(.35, $qbStart - .35 / .6, 26.7, 1), $point(.6, $handoff, 26.7, 1)];
        if ($special) {
            $landing = $play['call'] === 'punt' ? $play['gain'] : 110 - $play['before']['spot'];
            $landingZ = $play['outcome'] === 'field_goal_missed' ? 39 : 26.7;
            $ball = array_merge($ball, [$point(1.2, $qbSet, 26.7, 1), $point(3, $landing / 2, $landingZ, 14), $point(5.3, $landing, $landingZ, $play['outcome'] === 'field_goal_good' ? 5 : 0), $point(6, $landing, $landingZ, $play['outcome'] === 'field_goal_good' ? 5 : 0)]);
        } elseif ($play['throwaway'] ?? false) {
            $ball = array_merge($ball, [$point(1.8, $qbSet, $qbZ, 1.8), $point(2.2, $qbSet + .5, $qbZ, 2), $point(3, $qbSet + 2, 42, 5), $point(3.8, $qbSet + 3, 53.33, 0), $point(6, $qbSet + 3, 53.33, 0)]);
        } elseif ($pass) {
            $ball = array_merge($ball, [$point(1.8, $qbSet, $qbZ, 1.8), $point(2.2, $qbSet + .5, $qbZ, 2), $point(3, ($play['target'] + $qbSet) / 2, (26.7 + $endZ) / 2, 7),
                $point(3.8, $play['target'], $endZ, $play['outcome'] === 'incomplete' ? 0 : 1),
                $point(5.3, $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'], $endZ, $play['outcome'] === 'incomplete' ? 0 : 1),
                $point(6, $play['outcome'] === 'incomplete' ? $play['target'] : $play['gain'], $endZ, $play['outcome'] === 'incomplete' ? 0 : 1)]);
        } else {
            $ball = array_merge($ball, [$point(1, $handoff, 27, 1), $point(5.3, $play['gain'], $endZ, 1), $point(6, $play['gain'], $endZ, 1)]);
            if ($play['carrier'] === 'QB') {
                $ball = [$point(0, -1, 26.7, 1), $point(.35, $qbStart - .35, 26.7, 1), $point(2, $qbSet, 26.7, 1), $point(5.3, $play['gain'], 26.7, 1), $point(6, $play['gain'], 26.7, 1)];
            }
        }
        // The runner may cut multiple times. Use the same sampled waypoints for
        // ball and runner instead of drawing a straight ball-only line.
        // Keep the snap-to-handoff keyframes (0-.6s), then synchronize at 1s.
        if ($runnerPath !== null) {
            $ball = array_merge(
                array_values(array_filter($ball, fn ($frame) => $frame[0] < 1)),
                array_map(
                    fn ($frame) => [$frame[0], $frame[1], 1, $frame[3]],
                    array_values(array_filter($runnerPath, fn ($frame) => $frame[0] >= 1))
                )
            );
        }
        $events = [[0, 'Snap'], [.6, $special ? 'Kick setup' : ($pass || ($play['throwaway'] ?? false) || $play['carrier'] === 'QB' ? 'Dropback' : 'Handoff')], [2.2, $special ? 'Kick in flight' : ($pass || ($play['throwaway'] ?? false) ? 'Pass in flight' : (($play['scramble'] ?? false) ? 'QB scramble' : 'Run'))], [3.8, $pass ? match ($play['outcome']) {
            'incomplete' => 'Incomplete pass', 'interception' => 'Intercepted', default => 'Catch'
        } : (($play['throwaway'] ?? false) ? 'Thrown away' : 'Pursuit')], [5.3, $play['summary']]];

        $holders = [[0, 'offense', 'C'], [.01, null, null], [.35, 'offense', 'QB']];
        if ($special) {
            $holders[] = [1.2, null, null];
        } elseif ($play['throwaway'] ?? false) {
            $holders[] = [2.2, null, null];
        } elseif ($pass) {
            $holders[] = [2.2, null, null];
            $holders[] = [3.8, $play['outcome'] === 'incomplete' ? null : ($play['outcome'] === 'interception' ? 'defense' : 'offense'), $play['outcome'] === 'interception' ? 'CB1' : $receiverRole];
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
            $goal = $side === 'home' ? 7 : 113;
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

        // Visual-only contact style. Recorded outcomes and ball paths are unchanged.
        $contactStyle = in_array($play['outcome'], ['tackle', 'fumble'], true)
            && ! ($play['out_of_bounds'] ?? false)
            ? ['wrap', 'side', 'lunge'][$visualVariant % 3]
            : null;
        $contactDefender = $pass ? 'CB1' : 'LB2';

        return ['tackle_style' => $contactStyle, 'tackler_role' => $contactDefender, 'motion_defender' => $motionDefender, 'motion_defender_start' => $motionDefenderStart, 'motion_defender_end' => $motionDefender !== null ? $defense[$motionDefender][1] : null, 'motion' => $motion, 'motion_start' => $motionStart, 'motion_end' => $motionStart !== null ? $offense[$motion][1] : null, 'contact_at' => in_array($play['outcome'], ['tackle', 'sack', 'fumble'], true) && ! ($play['out_of_bounds'] ?? false) ? 5.3 : null, 'carrier' => $play['carrier'], 'dropback' => in_array($play['call'], ['slant', 'short_pass', 'medium_pass', 'deep_pass', 'two_point_pass'], true), 'passing' => $pass || ($play['throwaway'] ?? false), 'throw_at' => 2.2, 'call' => $play['call'], 'duration' => 6, 'players' => $tracks, 'ball' => $ball, 'ballHolders' => $holders, 'events' => $events, 'line' => $line,
            'firstDown' => max(10, min(110, $line + $direction * $play['before']['distance'])),
            'possession' => $side, 'animation_variant' => $visualVariant];
    }
}
