<?php

namespace App\Services\Simulation;

use LogicException;

class ExhibitionEngine
{
    public const OFFENSE = ['inside_run', 'outside_run', 'slant', 'short_pass', 'medium_pass', 'deep_pass', 'punt', 'field_goal'];

    public const OFFENSE_FORMATIONS = ['singleback' => 'Singleback', 'shotgun' => 'Shotgun', 'spread' => 'Spread'];

    public const DEFENSE_FORMATIONS = ['base_4_3' => 'Base 4–3', 'two_high' => '4–3 · two high safeties', 'single_high' => '4–3 · single high safety'];

    public const DEFENSE = ['balanced', 'run_commit', 'coverage', 'blitz'];

    public function initial(int $quarterLength = 180, ?int $seed = null): array
    {
        return ['quarter' => 1, 'clock' => $quarterLength, 'quarter_length' => $quarterLength, 'possession' => 'home',
            'spot' => 25, 'down' => 1, 'distance' => 10, 'home_score' => 0, 'away_score' => 0,
            'status' => 'playing', 'version' => 0, 'seed' => $seed ?? random_int(1, 2147483647),
            'stats' => ['home' => ['plays' => 0, 'yards' => 0, 'turnovers' => 0], 'away' => ['plays' => 0, 'yards' => 0, 'turnovers' => 0]]];
    }

    public function resolve(array $state, array $rosters, string $call, string $defense, string $offenseFormation = 'shotgun', string $defenseFormation = 'base_4_3'): array
    {
        if ($state['status'] !== 'playing' || ! in_array($call, self::OFFENSE, true) || ! in_array($defense, self::DEFENSE, true) || ! array_key_exists($offenseFormation, self::OFFENSE_FORMATIONS) || ! array_key_exists($defenseFormation, self::DEFENSE_FORMATIONS)) {
            throw new LogicException('This game cannot accept that play.');
        }
        $before = $state;
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $off = $rosters[$side]['players'];
        $def = $rosters[$other]['players'];
        $rollIndex = 0;
        $roll = function () use ($state, &$rollIndex) {
            return ((int) sprintf('%u', crc32($state['seed'].':'.$state['version'].':'.$rollIndex++)) % 10000) / 10000;
        };
        $yards = fn (float $roll, int $min, int $max) => $min + (int) floor($roll * ($max - $min + 1));
        $mean = fn ($players, $roles, $rating) => array_sum(array_map(fn ($role) => $players[$role]['ratings'][$rating], $roles)) / count($roles);
        $block = $mean($off, ['C', 'LG', 'RG', 'LT', 'RT'], 'blocking');
        $front = $mean($def, ['DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3'], 'tackling');
        $coverage = $mean($def, ['CB1', 'CB2', 'S1', 'S2'], 'coverage');
        $front += match ($defenseFormation) {
            'single_high' => 3, 'two_high' => -2, default => 0
        };
        $gain = 0;
        $outcome = 'tackle';
        $target = 0;
        $carrier = 'RB';
        $flip = false;
        if ($call === 'punt') {
            $gain = min(100 - $state['spot'], (int) round(28 + $off['P']['ratings']['kicking'] * .2 + $roll() * 12));
            $outcome = 'punt';
            $flip = true;
            $carrier = 'P';
        } elseif ($call === 'field_goal') {
            $distance = 117 - $state['spot'];
            $good = $distance <= 65 && $roll() < max(.02, min(.98, 1.15 - max(0, $distance - 20) * .019 + ($off['K']['ratings']['kicking'] - 60) * .006));
            $outcome = $good ? 'field_goal_good' : 'field_goal_missed';
            $flip = true;
            $carrier = 'K';
            if ($good) {
                $state[$side.'_score'] += 3;
            }
        } elseif (in_array($call, ['slant', 'short_pass', 'medium_pass', 'deep_pass'], true)) {
            $carrier = 'WR1';
            $sackChance = max(.01, min(.35, .08 + ($front - $block) * .003 + ($defense === 'blitz' ? .12 : 0) + ($offenseFormation === 'singleback' ? .02 : -.01)));
            if ($roll() < $sackChance) {
                $gain = -$yards($roll(), 3, 9);
                $outcome = 'sack';
                $carrier = 'QB';
            } else {
                $target = match ($call) {
                    'short_pass' => $yards($roll(), 2, 7),
                    'slant' => $yards($roll(), 5, 12),
                    'medium_pass' => $yards($roll(), 10, 20),
                    default => $yards($roll(), 18, 35),
                };
                $target = min($target, 100 - $state['spot']);
                $complete = max(.15, min(.93, .64 + ($off['QB']['ratings']['throwing'] + $off['WR1']['ratings']['catching'] - 2 * $coverage) * .004
                    - match ($call) {
                        'deep_pass' => .2, 'medium_pass' => .1, 'short_pass' => -.06, default => 0
                    }
                    + ($offenseFormation === 'spread' ? .035 : 0)
                    + ($call === 'deep_pass' ? match ($defenseFormation) {
                        'two_high' => -.07, 'single_high' => .04, default => 0
                    } : 0) - ($defense === 'coverage' ? .1 : 0) + ($defense === 'run_commit' ? .12 : 0)));
                if ($roll() < max(.005, min(.12, .025 + ($coverage - $off['QB']['ratings']['awareness']) * .001 + ($call === 'deep_pass' ? .02 : 0)))) {
                    $outcome = 'interception';
                    $gain = $target;
                    $flip = true;
                } elseif ($roll() > $complete) {
                    $outcome = 'incomplete';
                } else {
                    $gain = $target + $yards($roll(), 0, 9) + (int) max(0, ($off['WR1']['ratings']['speed'] - $def['CB1']['ratings']['speed']) / 5);
                }
            }
        } else {
            $runner = $off['RB']['ratings'];
            $matchup = ($block - $front) * .09 + ($runner['strength'] - 60) * .03;
            if ($call === 'outside_run') {
                $matchup += ($runner['speed'] + $runner['acceleration'] - $def['LB1']['ratings']['speed'] - $def['LB1']['ratings']['acceleration']) * .06;
            }
            $matchup += match ($offenseFormation) {
                'singleback' => 1, 'spread' => -1.5, default => 0
            };
            $matchup += match ($defense) {
                'run_commit' => -3, 'coverage' => 2, 'blitz' => -1, default => 0
            };
            $gain = (int) round(-2 + $roll() * 10 + $matchup);
            if ($roll() < .06 + max(0, $runner['speed'] - $front) * .002) {
                $gain += $yards($roll(), 8, 25);
            }
        }
        $gain = max(-$before['spot'], min(100 - $before['spot'], $gain));
        if (in_array($outcome, ['tackle', 'sack'], true) && $gain < 100 - $before['spot']
            && $roll() < max(.004, .045 - $off[$carrier]['ratings']['ball_security'] * .0004)) {
            $outcome = 'fumble';
            $flip = true;
        }
        $state['stats'][$side]['plays']++;
        if (! in_array($outcome, ['punt', 'field_goal_good', 'field_goal_missed', 'interception'], true)) {
            $state['stats'][$side]['yards'] += $gain;
        }
        $deadSpot = $before['spot'] + $gain;
        $summary = str_replace('_', ' ', $call).': '.match ($outcome) {
            'incomplete' => 'incomplete pass', 'interception' => "intercepted {$gain} yards downfield", 'fumble' => "fumble recovered by defense after {$gain} yards",
            'sack' => 'sack for '.abs($gain).' yards lost', 'punt' => "punt {$gain} yards", 'field_goal_good' => "{$distance}-yard field goal good",
            'field_goal_missed' => "{$distance}-yard field goal missed", default => "{$gain} yards",
        };
        if ($outcome === 'field_goal_good') {
            $this->possession($state, $other, 25);
        } elseif ($outcome === 'field_goal_missed') {
            $this->possession($state, $other, min(99, max(20, 100 - ($before['spot'] - 7))));
        } elseif ($outcome === 'punt') {
            $this->possession($state, $other, $deadSpot >= 100 ? 20 : 100 - $deadSpot);
        } elseif ($flip) {
            $state['stats'][$side]['turnovers']++;
            if ($deadSpot <= 0) {
                $state[$other.'_score'] += 7;
                $summary .= ' · defensive touchdown (+ automatic extra point)';
                $this->possession($state, $side, 25);
            } else {
                $this->possession($state, $other, $deadSpot >= 100 ? 20 : 100 - $deadSpot);
            }
        } elseif ($outcome !== 'incomplete' && $deadSpot >= 100) {
            $outcome = 'touchdown';
            $summary .= ' · TOUCHDOWN (+ automatic extra point)';
            $state[$side.'_score'] += 7;
            $this->possession($state, $other, 25);
        } elseif ($outcome !== 'incomplete' && $deadSpot <= 0) {
            $outcome = 'safety';
            $summary .= ' · SAFETY';
            $state[$other.'_score'] += 2;
            $this->possession($state, $other, 35);
        } else {
            $state['spot'] = $deadSpot;
            if ($gain >= $before['distance']) {
                $state['down'] = 1;
                $state['distance'] = min(10, 100 - $deadSpot);
                $summary .= ' · first down';
            } else {
                $state['down']++;
                $state['distance'] -= $gain;
                if ($state['down'] > 4) {
                    $summary .= ' · turnover on downs';
                    $this->possession($state, $other, 100 - $deadSpot);
                }
            }
        }
        $seconds = in_array($outcome, ['incomplete', 'interception', 'punt', 'field_goal_good', 'field_goal_missed'], true) ? $yards($roll(), 6, 12) : $yards($roll(), 28, 42);
        $state['clock'] = max(0, $state['clock'] - $seconds);
        if ($state['clock'] === 0) {
            if ($state['quarter'] === 4) {
                $state['status'] = 'final';
                $summary .= ' · FINAL';
            } else {
                $state['quarter']++;
                $state['clock'] = $state['quarter_length'];
                if ($state['quarter'] === 3) {
                    $this->possession($state, 'away', 25);
                    $summary .= ' · halftime, away receives';
                }
            }
        }
        $state['version']++;
        $play = ['number' => $state['version'], 'call' => $call, 'defense' => $defense,
            'offense_formation' => $offenseFormation, 'defense_formation' => $defenseFormation, 'outcome' => $outcome,
            'gain' => $gain, 'target' => $target, 'carrier' => $carrier, 'summary' => ucfirst($summary), 'before' => $before,
            'after' => $state, 'duration' => 6];
        $play['animation'] = app(PlayTimeline::class)->build($play, $rosters);

        return ['state' => $state, 'play' => $play];
    }

    private function possession(array &$state, string $side, int $spot): void
    {
        $state['possession'] = $side;
        $state['spot'] = $spot;
        $state['down'] = 1;
        $state['distance'] = min(10, 100 - $spot);
    }
}
