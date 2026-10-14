<?php

namespace App\Services\Simulation;

class OnsideKick
{
    public function resolve(array $state, array $rosters, string $defense, string $offenseFormation, string $defenseFormation): array
    {
        $before = $state;
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $off = $rosters[$side]['players'];
        $def = $rosters[$other]['players'];
        // Own calculation: 5 recoveries / 52 tagged regular-season onside kicks
        // in nflverse 2025 pbp. This is a baseline, not a guaranteed success rate.
        $chance = max(.04, min(.16, 5 / 52 + ($off['K']['ratings']['kicking'] - $def['CB1']['ratings']['catching']) * .0004));
        $recovered = SpecialTeamsTricks::roll($state, 'onside-recovery') < $chance;
        $landing = min(99, $before['spot'] + 10 + (int) floor(SpecialTeamsTricks::roll($state, 'onside-distance') * 5));
        $return = $recovered ? 0 : (int) floor(SpecialTeamsTricks::roll($state, 'onside-return') * 9);
        $state['possession'] = $recovered ? $side : $other;
        $state['spot'] = $recovered ? $landing : min(99, 100 - $landing + $return);
        $state['phase'] = 'scrimmage';
        $state['down'] = 1;
        $state['distance'] = min(10, 100 - $state['spot']);
        $person = $recovered ? $off['TE'] : $def['CB1'];
        $summary = 'Declared onside kick: '.$off['K']['name'].' (#'.$off['K']['number'].') kicks '.($landing - $before['spot']).' yards; '.$person['name'].' (#'.$person['number'].') '.($recovered ? 'recovers for the kicking team' : 'secures the ball for the receiving team'.($return ? " and returns {$return} yards" : ''));

        return app(ExhibitionEngine::class)->finish($state, $before, [
            'call' => 'onside_kick', 'defense' => $defense, 'offense_formation' => $offenseFormation, 'defense_formation' => $defenseFormation,
            'carrier' => 'K', 'gain' => $landing - $before['spot'], 'target' => 0, 'summary' => $summary,
            'outcome' => $recovered ? 'onside_recovered' : 'onside_failed', 'landing' => $landing, 'return_yards' => $return,
            'declared_onside' => true, 'recovery_side' => $state['possession'], 'recovery_role' => $recovered ? 'TE' : 'CB1',
            'recovery_chance' => $chance,
        ], $rosters, $recovered ? 1 : 2 + (int) ceil($return / 6));
    }

    public function animation(array $play, array $rosters): array
    {
        $base = app(PlayTimeline::class)->build($play, $rosters);
        $side = $play['before']['possession'];
        $direction = $side === 'home' ? 1 : -1;
        $kick = $side === 'home' ? 10 + $play['before']['spot'] : 110 - $play['before']['spot'];
        $landing = $kick + $direction * ($play['landing'] - $play['before']['spot']);
        $end = $landing - $direction * $play['return_yards'];
        $point = fn ($t, $x, $y, $z) => [$t, $x, $y, $z];
        $receiver = $play['recovery_role'];
        $receiving = 0;
        $kicking = 0;
        foreach ($base['players'] as &$player) {
            $offense = $player['team'] === 'offense';
            if ($offense && $player['role'] === 'QB') {
                $person = $rosters[$side]['players']['K'];
                $player = array_replace($player, array_intersect_key($person, array_flip(['id', 'name', 'number', 'height_inches', 'weight_pounds', 'skin_tone', 'appearance'])));
                $player['path'] = [$point(0, $kick - 4 * $direction, 0, 26.7), $point(1.2, $kick, 0, 26.7), $point(6, $kick + 3 * $direction, 0, 26.7)];

                continue;
            }
            $i = $offense ? $kicking++ : $receiving++;
            // Five kicking teammates on either side; eight receivers in the
            // 10-to-25-yard setup zone, with three deeper hands-team players.
            $z = $offense ? ($i < 5 ? 4 + $i * 4.1 : 32.9 + ($i - 5) * 4.1) : 3 + $i * 4.7;
            $x = $kick + ($offense ? -.3 : ($i < 8 ? 10 + ($i % 3) * 3 : 27)) * $direction;
            $winner = $player['side'] === $play['recovery_side'] && $player['role'] === $receiver;
            $finishZ = $winner ? 22 : 22 + (($i % 5) - 2) * 1.4;
            $finishX = $winner ? $end : $landing + (($i % 3) - 1) * $direction;
            $player['path'] = [$point(0, $x, 0, $z), $point(1.2, $x, 0, $z), $point(3.6, $landing, 0, $finishZ), $point(6, $finishX, 0, $finishZ)];
        }
        unset($player);
        $base['ball'] = [$point(0, $kick, .25, 26.7), $point(1.2, $kick, .25, 26.7), $point(1.8, $kick + 3 * $direction, .1, 25.5), $point(2.5, $kick + 7 * $direction, 1.3, 24), $point(3.6, $landing, .4, 22), $point(6, $end, .8, 22)];
        $base['events'] = [[0, 'Onside kick declared · Referee notifies receiving team'], [1.2, 'Onside kick'], [3.6, 'Recovery'], [6, $play['summary']]];
        $base['ballHolders'] = [[0, null, null], [3.6, $play['recovery_side'] === $side ? 'offense' : 'defense', $receiver]];
        $base['firstDown'] = null;
        $base['carrier'] = $receiver;
        $base['passing'] = false;
        $base['declared_onside'] = true;
        $base['contact_at'] = null;
        $base['tackle_style'] = null;

        return $base;
    }
}
