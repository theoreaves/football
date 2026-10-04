<?php

namespace App\Services\Simulation;

use App\Models\Exhibition;

class ExhibitionBoxScore
{
    public function build(Exhibition $game): array
    {
        $quarters = ['home' => array_fill(1, 4, 0), 'away' => array_fill(1, 4, 0)];
        $teams = [];
        $players = ['home' => [], 'away' => []];
        $scoring = [];
        foreach (['home', 'away'] as $side) {
            $teams[$side] = ['plays' => 0, 'yards' => 0, 'rushing_yards' => 0, 'passing_yards' => 0, 'first_downs' => 0, 'turnovers' => 0, 'penalties' => 0, 'penalty_yards' => 0, 'possession_seconds' => 0];
            foreach ($game->rosters[$side]['players'] as $role => $person) {
                $players[$side][$role] = array_merge(array_intersect_key($person, array_flip(['id', 'name', 'number'])), ['role' => $role, 'pass_attempts' => 0, 'completions' => 0, 'passing_yards' => 0, 'passing_td' => 0, 'interceptions' => 0, 'rushes' => 0, 'rushing_yards' => 0, 'rushing_td' => 0, 'receptions' => 0, 'receiving_yards' => 0, 'receiving_td' => 0, 'sacks' => 0, 'fg_attempts' => 0, 'fg_made' => 0, 'xp_attempts' => 0, 'xp_made' => 0, 'punts' => 0, 'punt_yards' => 0, 'returns' => 0, 'return_yards' => 0, 'return_td' => 0, 'tackles' => 0, 'defensive_sacks' => 0, 'defensive_interceptions' => 0, 'fumble_recoveries' => 0, 'fumbles_lost' => 0]);
            }
        }
        foreach ($game->history as $play) {
            $before = $play['before'];
            $after = $play['after'];
            $side = $before['possession'];
            $other = $side === 'home' ? 'away' : 'home';
            $quarter = $before['quarter'];
            $points = false;
            foreach (['home', 'away'] as $team) {
                $delta = $after[$team.'_score'] - $before[$team.'_score'];
                $quarters[$team][$quarter] += $delta;
                $points = $points || $delta > 0;
                $teams[$team]['penalties'] += ($after['stats'][$team]['penalties'] ?? 0) - ($before['stats'][$team]['penalties'] ?? 0);
                $teams[$team]['penalty_yards'] += ($after['stats'][$team]['penalty_yards'] ?? 0) - ($before['stats'][$team]['penalty_yards'] ?? 0);
                $teams[$team]['turnovers'] += $after['stats'][$team]['turnovers'] - $before['stats'][$team]['turnovers'];
            }
            if ($points) {
                $scoring[] = ['quarter' => $quarter, 'clock' => $after['quarter'] === $quarter ? $after['clock'] : 0, 'summary' => $play['summary'], 'home_score' => $after['home_score'], 'away_score' => $after['away_score']];
            }
            $teams[$side]['possession_seconds'] += min($before['clock'], $play['clock_seconds'] ?? 0);
            $defensiveFirst = ($play['penalty']['accepted'] ?? false) && $play['penalty']['team'] !== $side
                && (in_array($play['penalty']['type'], ['defensive_pass_interference', 'face_mask'], true) || $play['penalty']['yards'] >= $before['distance']);
            if ($after['possession'] === $side && $after['down'] === 1 && (($before['phase'] ?? 'scrimmage') === 'scrimmage')
                && ($defensiveFirst || (! (($play['no_snap'] ?? false) || $play['outcome'] === 'penalty' || $play['outcome'] === 'interception') && $play['gain'] >= $before['distance'] && ! in_array($play['call'], ['punt', 'field_goal'], true)))) {
                $teams[$side]['first_downs']++;
            }
            if (($play['no_snap'] ?? false) || $play['outcome'] === 'penalty') {
                continue;
            }
            $call = $play['call'];
            $outcome = $play['outcome'];
            $gain = $play['gain'];
            $carrier = $play['carrier'];
            $pass = in_array($call, ['slant', 'short_pass', 'medium_pass', 'deep_pass', 'spike'], true);
            if ($pass || in_array($call, ['inside_run', 'outside_run', 'kneel'], true)) {
                $teams[$side]['plays']++;
                $yards = in_array($outcome, ['interception', 'incomplete', 'spike'], true) ? 0 : $gain;
                $teams[$side]['yards'] += $yards;
                $teams[$side][$pass ? 'passing_yards' : 'rushing_yards'] += $yards;
                if ($pass) {
                    $qb = &$players[$side]['QB'];
                    if ($outcome === 'sack' || ($carrier === 'QB' && $call !== 'spike')) {
                        $qb['sacks']++;
                    } else {
                        $qb['pass_attempts']++;
                        if (! in_array($outcome, ['incomplete', 'interception', 'spike'], true)) {
                            $qb['completions']++;
                            $qb['passing_yards'] += $gain;
                            $players[$side][$carrier]['receptions']++;
                            $players[$side][$carrier]['receiving_yards'] += $gain;
                        }
                        if ($outcome === 'interception') {
                            $qb['interceptions']++;
                        }
                        if ($outcome === 'touchdown') {
                            $qb['passing_td']++;
                            $players[$side][$carrier]['receiving_td']++;
                        }
                    }
                    unset($qb);
                } else {
                    $players[$side][$carrier]['rushes']++;
                    $players[$side][$carrier]['rushing_yards'] += $gain;
                    if ($outcome === 'touchdown') {
                        $players[$side][$carrier]['rushing_td']++;
                    }
                }
            }
            if ($pass || in_array($call, ['inside_run', 'outside_run'], true)) {
                $tackler = $carrier === 'WR1' ? 'CB1' : 'LB2';
                if (in_array($outcome, ['tackle', 'sack', 'fumble', 'safety'], true) && ! ($play['out_of_bounds'] ?? false) && $call !== 'spike') {
                    $players[$other][$tackler]['tackles']++;
                }
                if ($carrier === 'QB' && $call !== 'spike') {
                    $players[$other][$tackler]['defensive_sacks']++;
                }
                if ($outcome === 'interception') {
                    $players[$other]['CB1']['defensive_interceptions']++;
                }
                if ($outcome === 'fumble') {
                    $players[$other][$tackler]['fumble_recoveries']++;
                    $players[$side][$carrier]['fumbles_lost']++;
                }
            }
            if ($call === 'field_goal') {
                $players[$side]['K']['fg_attempts']++;
                $players[$side]['K']['fg_made'] += (int) ($outcome === 'field_goal_good');
            }
            if ($call === 'extra_point') {
                $players[$side]['K']['xp_attempts']++;
                $players[$side]['K']['xp_made'] += (int) ($outcome === 'extra_point_good');
            }
            if ($call === 'punt') {
                $players[$side]['P']['punts']++;
                $players[$side]['P']['punt_yards'] += $gain;
            }
            if (in_array($outcome, ['punt_return', 'kickoff_return', 'punt_return_touchdown', 'kickoff_return_touchdown'], true)) {
                $players[$other]['CB1']['returns']++;
                $players[$other]['CB1']['return_yards'] += $play['return_yards'];
                $players[$other]['CB1']['return_td'] += (int) str_ends_with($outcome, '_touchdown');
            }
        }

        return compact('quarters', 'teams', 'players', 'scoring');
    }
}
