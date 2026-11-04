<?php

namespace App\Services\Simulation;

/** Presentation only: recorded gain, clock, statistics and history stay authoritative. */
class KneelAnimation
{
    public static function apply(array $animation, array $play): array
    {
        if ($play['call'] !== 'kneel' || ($play['no_snap'] ?? false)) {
            return $animation;
        }
        $direction = $animation['possession'] === 'home' ? 1 : -1;
        $line = $animation['line'];
        $end = max(0, min(120, $line + $direction * $play['gain']));
        $qbStart = $line - $direction * .6;
        foreach ($animation['players'] as &$player) {
            $start = $player['path'][0];
            $x = $start[1];
            $z = $start[3];
            if ($player['team'] === 'offense' && $player['role'] === 'QB') {
                $player['path'] = [[0, $qbStart, 0, 26.7], [.6, $qbStart, 0, 26.7], [1.1, $end, 0, 26.7], [6, $end, 0, 26.7]];
            } else {
                if ($player['team'] === 'offense' && in_array($player['role'], ['C', 'LG', 'RG', 'LT', 'RT'], true)) {
                    $x = $line;
                }
                // A short set-and-settle movement, never a rush into the kneeling QB.
                $step = $player['team'] === 'defense' ? -.20 : 0;
                $settled = max(0, min(120, $x + $direction * $step));
                $player['path'] = [[0, $x, 0, $z], [.6, $x, 0, $z], [1.1, $settled, 0, $z], [6, $settled, 0, $z]];
            }
        }
        unset($player);
        $animation['ball'] = [[0, $line, .48, 26.7], [.35, $qbStart, 1.15, 26.7], [.6, $qbStart, 1.15, 26.7], [1.1, $end, 1.15, 26.7], [1.7, $end, .60, 26.7], [6, $end, .60, 26.7]];
        $animation['ballHolders'] = [[0, 'offense', 'C'], [.01, null, null], [.35, 'offense', 'QB']];
        $animation['events'] = [[0, 'Snap'], [.6, 'Quarterback protects the ball'], [1.7, 'Quarterback takes a knee'], [5.3, $play['summary']]];
        $animation['kneel_at'] = 1.1;
        $animation['contact_at'] = $animation['tackle_style'] = $animation['tackler_role'] = null;
        $animation['motion'] = 'none';
        $animation['motion_start'] = $animation['motion_end'] = null;
        $animation['passing'] = $animation['dropback'] = false;

        return $animation;
    }
}
