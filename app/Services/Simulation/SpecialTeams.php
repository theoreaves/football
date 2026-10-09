<?php

namespace App\Services\Simulation;

class SpecialTeams
{
    public function resolve(array $state, array $rosters, string $call, string $defense, string $offenseFormation, string $defenseFormation): array
    {
        $before = $state;
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $off = $rosters[$side]['players'];
        $def = $rosters[$other]['players'];
        $index = 0;
        $roll = function () use ($state, &$index) {
            return ((int) sprintf('%u', crc32($state['seed'].':'.$state['version'].':special:'.$index++)) % 10000) / 10000;
        };
        $name = fn ($p) => $p['name'].' (#'.$p['number'].')';
        $carrier = $call === 'punt' ? 'P' : 'K';
        $gain = 0;
        $return = 0;
        $landing = $before['spot'];
        $blocked = false;
        if ($call !== 'extra_point') {
            $state['stats'][$side]['plays']++;
        }
        if ($call === 'extra_point') {
            unset($state['try_adjustment']);
        }
        $set = function ($team, $spot, $phase = 'scrimmage') use (&$state) {
            $state['possession'] = $team;
            $state['spot'] = $spot;
            $state['phase'] = $phase;
            $state['down'] = 1;
            $state['distance'] = min(10, 100 - $spot);
        };
        if (in_array($call, ['field_goal', 'extra_point'], true)) {
            $distance = $call === 'extra_point' ? 33 - ($before['try_adjustment'] ?? 0) : 117 - $before['spot'];
            $blocked = $defense === 'field_goal_block' && $roll() < max(.005, .025 + ($def['DE1']['ratings']['strength'] - $off['C']['ratings']['blocking']) * .001);
            $chance = $call === 'extra_point' ? min(.99, .85 + $off['K']['ratings']['kicking'] * .0015) : max(.02, min(.98, 1.15 - max(0, $distance - 20) * .019 + ($off['K']['ratings']['kicking'] - 60) * .006));
            $good = ! $blocked && $distance <= 65 && $roll() < $chance;
            $outcome = $call.($blocked ? '_blocked' : ($good ? '_good' : '_missed'));
            $summary = $name($off['K']).($blocked ? " has the {$distance}-yard kick blocked by ".$name($def['DE1']) : ($good ? " makes a {$distance}-yard " : " misses a {$distance}-yard ").($call === 'extra_point' ? 'extra point' : 'field goal'));
            if ($good) {
                $state[$side.'_score'] += $call === 'extra_point' ? 1 : 3;
            }
            if ($call === 'extra_point' || $good) {
                $set($side, 35, 'kickoff');
            } else {
                $set($other, $blocked ? min(99, 100 - max(1, $before['spot'] - 9)) : min(99, max(20, 100 - ($before['spot'] - 7))));
            }
            $landing = $blocked ? max(1, $before['spot'] - 9) : 110;
            $seconds = $call === 'extra_point' ? 0 : 7;
        } else {
            $gain = $call === 'punt' ? (int) round(28 + $off['P']['ratings']['kicking'] * .2 + $roll() * 12) : (int) round(45 + $off['K']['ratings']['kicking'] * .2 + $roll() * 14);
            $landing = min(110, $before['spot'] + $gain);
            $gain = $landing - $before['spot'];
            $returnCall = in_array($defense, ['punt_return', 'kickoff_return'], true);
            $returner = $def['CB1'];
            if ($landing >= 100) {
                $outcome = $call.'_touchback';
                $set($other, $call === 'punt' ? 20 : 25);
            } else {
                $outcome = $returnCall ? $call.'_return' : 'punt_fair_catch';
                $return = $returnCall ? max(0, (int) round($roll() * ($call === 'punt' ? 16 : 28) + ($returner['ratings']['speed'] - $off['TE']['ratings']['tackling']) * .1)) : 0;
                if ($returnCall && $roll() < .02) {
                    $return += 80;
                }
                $return = min($landing, $return);
                if ($return >= $landing) {
                    $state[$other.'_score'] += 6;
                    $set($other, 85, 'extra_point');
                    $outcome = $call.'_return_touchdown';
                } else {
                    $set($other, 100 - $landing + $return);
                }
            }
            $summary = $name($off[$carrier]).($call === 'punt' ? ' punts' : ' kicks off')." {$gain} yards; ".match ($outcome) {
                'punt_touchback', 'kickoff_touchback' => 'touchback', 'punt_fair_catch' => $name($returner).' calls for a fair catch',
                default => $name($returner)." returns {$return} yards".($state['phase'] === 'extra_point' ? ' · TOUCHDOWN' : '; tackled by '.$name($off['TE'])),
            };
            $seconds = $landing >= 100 ? ($call === 'kickoff' ? 0 : 6) : 10;
        }

        return app(ExhibitionEngine::class)->finish($state, $before, [
            'call' => $call, 'defense' => $defense, 'offense_formation' => $offenseFormation, 'defense_formation' => $defenseFormation,
            'carrier' => $carrier, 'gain' => $gain, 'target' => 0, 'outcome' => $outcome, 'summary' => $summary,
            'landing' => $landing, 'return_yards' => $return,
        ], $rosters, $seconds);
    }
}
