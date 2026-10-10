<?php

namespace App\Services\Simulation;

class SpecialTeamsTimeline
{
    public function build(array $play, array $rosters): array
    {
        $base = app(PlayTimeline::class)->build($play, $rosters);
        $side = $play['before']['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $direction = $side === 'home' ? 1 : -1;
        $absolute = fn ($spot) => $side === 'home' ? 10 + $spot : 110 - $spot;
        $point = fn ($t, $x, $y, $z) => [$t, max(0, min(120, $x)), $y, $z];
        $kickoff = $play['call'] === 'kickoff';
        $kick = $absolute($play['before']['spot']) - ($kickoff ? 0 : 7 * $direction);
        // Older saved touchbacks capped the landing at the goal line.
        $landingSpot = str_ends_with($play['outcome'], '_touchback') ? max(103, $play['landing']) : $play['landing'];
        $landing = $absolute($landingSpot);
        $return = $play['return_yards'];
        $end = $landing - $direction * $return;
        $blocked = str_ends_with($play['outcome'], '_blocked');
        $goalKick = in_array($play['call'], ['field_goal', 'extra_point'], true);
        $missed = str_ends_with($play['outcome'], '_missed');
        $endZ = $missed ? 40 : 26.7;
        foreach ($base['players'] as $i => &$player) {
            $initial = $player['path'][0];
            if ($kickoff) {
                $z = 3 + ($i % 11) * 4.7;
                $x = $player['team'] === 'offense' ? $absolute($play['before']['spot']) - 1 * $direction : $absolute(65 + ($i % 3) * 8);
                $player['path'] = [$point(0, $x, 0, $z), $point(2, $x + $direction * 12, 0, $z), $point(6, $end + ($i % 4) * $direction, 0, $z)];
            }
            if ($player['role'] === 'QB') {
                $person = $rosters[$side]['players'][$play['carrier']];
                $player = array_merge($player, array_intersect_key($person, array_flip(['id', 'name', 'number', 'height_inches', 'weight_pounds', 'skin_tone', 'appearance'])));
                $player['path'] = $goalKick
                    ? [
                        $point(0, $kick - 5 * $direction, 0, 26.7),
                        $point(.45, $kick - 3.5 * $direction, 0, 26.7),
                        $point(.85, $kick - 1.8 * $direction, 0, 26.7),
                        $point(1.2, $kick + .55 * $direction, 0, 26.7),
                        $point(2.0, $kick + 2.5 * $direction, 0, 26.7),
                        $point(6, $kick + 3 * $direction, 0, 26.7),
                    ]
                    : [
                        // A kickoff starts with a run-up to the ball on a tee.
                        // Do not reuse the stationary punt-style kick motion.
                        $point(0, $kick - 5 * $direction, 0, 26.7),
                        $point(.55, $kick - 3.1 * $direction, 0, 26.7),
                        $point(.95, $kick - 1.25 * $direction, 0, 26.7),
                        $point(1.2, $kick, 0, 26.7),
                        $point(1.65, $kick + 1.25 * $direction, 0, 26.7),
                        $point(6, $kick + 3 * $direction, 0, 26.7),
                    ];
            }
            if ($player['role'] === 'CB1' && ! $goalKick) {
                $player['path'] = [$point(0, $landing, 0, 26.7), $point(3.5, $landing, 0, 26.7), $point(5.3, $end, 0, 26.7), $point(6, $end, 0, 26.7)];
            }
            if ($player['role'] === 'TE' && $player['team'] === 'offense' && ! $goalKick) {
                $player['path'] = [$initial, $point(3.5, $end - 8 * $direction, 0, 27), $point(5.3, $end, 0, 26.7), $point(6, $end, 0, 26.7)];
            }
            if ($player['role'] === 'DE1' && $blocked) {
                $player['path'] = [$initial, $point(1.2, $kick + $direction, 0, 26.7), $point(6, $landing, 0, 26.7)];
            }
        } unset($player);
        if ($goalKick) {
            // The QB track is repurposed as the kicker above. Use the RB track for
            // the holder, retaining its own player identity and uniform.
            foreach ($base['players'] as &$player) {
                if ($player['team'] !== 'offense' || $player['role'] !== 'RB') {
                    continue;
                }
                $player['pose'] = 'holder-kneel';
                $player['path'] = [
                    $point(0, $kick - .95 * $direction, 0, 27.65),
                    $point(.7, $kick - .95 * $direction, 0, 27.65),
                    $point(1.2, $kick - .95 * $direction, 0, 27.65),
                    $point(6, $kick - .95 * $direction, 0, 27.65),
                ];
                break;
            }
            unset($player);
        }
        $height = $goalKick && str_ends_with($play['outcome'], '_good') ? 5 : ($goalKick || str_ends_with($play['outcome'], '_touchback') ? .25 : 1);
        $base['ball'] = [$point(0, $kickoff ? $kick : $absolute($play['before']['spot']) - $direction, $kickoff ? .25 : 1, 26.7),
            $point(1.2, $kick, .5, 26.7), $point(2.4, ($kick + $landing) / 2, $blocked ? 1 : 14, (26.7 + $endZ) / 2),
            $point(3.5, $landing, $height, $endZ), $point(5.3, $end, $height, $endZ), $point(6, $end, $height, $endZ)];
        $base['events'] = [[0, $kickoff ? 'Kickoff setup' : 'Snap'], [1.2, 'Kick'], [2.4, $blocked ? 'Kick blocked' : 'Kick in flight'],
            [3.5, $goalKick ? 'Kick reaches the goal' : ($return ? 'Return' : 'Kick lands')], [5.3, $play['summary']]];
        $base['ballHolders'] = $kickoff || $goalKick ? [[0, null, null]] : [[0, 'offense', 'C'], [.01, null, null], [.35, 'offense', 'QB'], [1.2, null, null]];
        if (! $goalKick && ! str_ends_with($play['outcome'], '_touchback')) {
            $base['ballHolders'][] = [3.5, 'defense', 'CB1'];
        }
        $base['firstDown'] = null;

        return $base;
    }
}
