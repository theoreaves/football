<?php

namespace App\Services\Simulation;

use LogicException;

class ExhibitionEngine
{
    public const OFFENSE = ['inside_run', 'outside_run', 'draw', 'screen', 'slant', 'short_pass', 'medium_pass', 'deep_pass', 'punt', 'field_goal', 'kickoff', 'extra_point', 'two_point_run', 'two_point_pass', 'spike', 'kneel'];

    public const OFFENSE_FORMATIONS = ['singleback' => 'Singleback', 'shotgun' => 'Shotgun', 'spread' => 'Spread', 'i_form' => 'I formation', 'pistol' => 'Pistol', 'trips' => 'Trips'];

    public const DEFENSE_FORMATIONS = ['base_4_3' => 'Base 4–3', 'base_3_5' => '3–5', 'nickel' => 'Nickel · 4–2–5', 'two_high' => '4–3 · two high safeties', 'single_high' => '4–3 · single high safety', 'base_3_4' => '3–4', 'dime' => 'Dime · 4–1–6'];

    public const DEFENSE = ['man_to_man', 'run_stop', 'zone', 'blitz', 'punt_return', 'field_goal_block', 'kickoff_return'];

    public function initial(int $quarterLength = 180, ?int $seed = null, bool $openingKickoff = true): array
    {
        return ['quarter' => 1, 'clock' => $quarterLength, 'quarter_length' => $quarterLength, 'possession' => $openingKickoff ? 'away' : 'home', 'phase' => $openingKickoff ? 'kickoff' : 'scrimmage',
            'spot' => $openingKickoff ? 35 : 25, 'down' => 1, 'distance' => 10, 'home_score' => 0, 'away_score' => 0,
            'timeouts' => ['home' => 3, 'away' => 3], 'clock_running' => false, 'warnings' => ['2' => false, '4' => false], 'untimed_down' => false, 'rules' => ['penalties' => true],
            'status' => 'playing', 'version' => 0, 'seed' => $seed ?? random_int(1, 2147483647),
            'stats' => ['home' => ['plays' => 0, 'yards' => 0, 'turnovers' => 0], 'away' => ['plays' => 0, 'yards' => 0, 'turnovers' => 0]]];
    }

    public function resolve(array $state, array $rosters, string $call, string $defense, string $offenseFormation = 'shotgun', string $defenseFormation = 'base_4_3', string $tempo = 'normal', string $clockStrategy = 'normal', string $expect = 'balanced', bool $blitz = false, string $motion = 'none'): array
    {
        if ($state['status'] !== 'playing' || ! in_array($call, self::OFFENSE, true) || ! in_array($defense, self::DEFENSE, true) || ! array_key_exists($offenseFormation, self::OFFENSE_FORMATIONS) || ! array_key_exists($defenseFormation, self::DEFENSE_FORMATIONS)) {
            throw new LogicException('This game cannot accept that play.');
        }
        if (! in_array($call, self::callsForState($state), true) || ! in_array($defense, self::defensesForCall($call), true)) {
            throw new LogicException('Choose a call for the current phase.');
        }
        if (! in_array($tempo, ['normal', 'hurry', 'drain'], true) || ! in_array($clockStrategy, ['normal', 'sideline'], true)) {
            throw new LogicException('Choose a valid tempo and clock strategy.');
        }
        if (! in_array($expect, ['balanced', 'run', 'pass'], true) || ! in_array($motion, ['none', 'WR1', 'WR2', 'WR3', 'TE', 'RB'], true)) {
            throw new LogicException('Choose valid defensive expectations and motion.');
        }
        if (app(Overtime::class)->pending($state)) {
            throw new LogicException('Finish the overtime coin toss before calling a play.');
        }
        $design = $call;
        $call = match ($call) {
            'draw' => 'inside_run', 'screen' => 'short_pass', default => $call
        };
        $rosters = app(GamePersonnel::class)->active($rosters, $state);
        if (in_array($call, ['two_point_run', 'two_point_pass'], true)) {
            return $this->twoPoint($state, $rosters, $call, $defense, $offenseFormation, $defenseFormation, $expect, $blitz, $motion);
        }
        if ($call === 'extra_point') {
            $state['spot'] = 85 + ($state['try_adjustment'] ?? 0);
        }
        $clock = app(GameClock::class);
        $state = $clock->normalize($state);
        $original = $state;
        $runoff = $clock->runoff($state, $tempo);
        if ($clock->warningDue($state, $runoff)) {
            $state['clock'] = 120;

            return $this->finish($state, $original, $this->stoppage('Official timeout', 'two_minute_warning') + ['two_minute_warning' => true, 'runoff_seconds' => $original['clock'] - 120], $rosters, 0);
        }
        if ($runoff >= $state['clock'] && $runoff > 0 && ! $state['untimed_down']) {
            $state['clock'] = 0;

            return $this->finish($state, $original, $this->stoppage('Clock expires before the snap', 'clock_expired') + ['runoff_seconds' => $original['clock']], $rosters, 0);
        }
        $state['clock'] -= $runoff;
        $state['_clock_context'] = ['before' => $original, 'runoff' => $runoff, 'tempo' => $tempo, 'strategy' => $clockStrategy];
        $prePenalty = app(PenaltyRules::class)->preSnap($state);
        if ($prePenalty) {
            $result = app(PenaltyRules::class)->enforce($state, $state, $this->stoppage('No snap', 'penalty'), $prePenalty);

            $finished = $this->finish($result['state'], $state, $result['play'], $rosters, 0);
            if (app(CpuCoach::class)->controls($state)[$result['play']['penalty']['beneficiary']] === 'human') {
                $declined = app(PenaltyRules::class)->enforce($state, $state, $this->stoppage('No snap', 'penalty'), $prePenalty, false);
                $finished['play']['penalty_options'] = ['accept' => $finished, 'decline' => $this->finish($declined['state'], $state, $declined['play'], $rosters, 0)];
                $finished['state']['penalty_pending'] = true;
                $finished['play']['after'] = $finished['state'];
            }

            return $finished;
        }
        if (in_array($call, ['spike', 'kneel'], true)) {
            return $this->clockPlay($state, $rosters, $call, $defense, $offenseFormation, $defenseFormation);
        }
        if (in_array($call, ['punt', 'field_goal', 'kickoff', 'extra_point'], true)) {
            return app(SpecialTeams::class)->resolve($state, $rosters, $call, $defense, $offenseFormation, $defenseFormation);
        }
        $state['phase'] = 'scrimmage';
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
        $frontRoles = match ($defenseFormation) {
            'base_3_5' => ['DE1', 'DT1', 'DE2', 'LB1', 'LB2', 'LB3', isset($def['LB4']) ? 'LB4' : 'DT2', isset($def['LB5']) ? 'LB5' : 'S2'],
            'base_3_4' => ['DE1', 'DT1', 'DE2', 'LB1', 'LB2', 'LB3', isset($def['LB4']) ? 'LB4' : 'DT2'],
            'dime' => ['DE1', 'DT1', 'DT2', 'DE2', 'LB1'],
            'nickel' => ['DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2'],
            default => ['DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3'],
        };
        $front = $mean($def, $frontRoles, 'tackling');
        $coverage = $mean($def, ['CB1', 'CB2', 'S1', 'S2'], 'coverage');
        $front += match ($defenseFormation) {
            'single_high' => 3, 'two_high' => -2, 'base_3_5' => 2, 'nickel' => -4, default => 0
        };
        $coverage += in_array($defenseFormation, ['nickel', 'dime'], true) ? ($defenseFormation === 'dime' ? 8 : 5) : ($defenseFormation === 'base_3_5' ? -3 : 0);
        $block += $design === 'draw' ? ($blitz || $defense === 'blitz' ? 8 : -3) : 0;
        $coverage += $design === 'screen' ? ($blitz || $defense === 'blitz' ? -8 : 3) : 0;
        $front += $expect === 'run' ? 8 : ($expect === 'pass' ? -4 : 0);
        $coverage += $expect === 'pass' ? 8 : ($expect === 'run' ? -8 : 0);
        $pressure = false;
        $scramble = false;
        $throwaway = false;
        $gain = 0;
        $outcome = 'tackle';
        $target = 0;
        $carrier = 'RB';
        $flip = false;
        if (in_array($call, ['slant', 'short_pass', 'medium_pass', 'deep_pass'], true)) {
            $carrier = 'WR1';
            $pressureChance = max(.01, min(.35, .08 + ($front - $block) * .003 + ($defense === 'blitz' || $blitz ? .12 : 0) + ($offenseFormation === 'singleback' ? .02 : -.01)));
            $pressure = $roll() < min(.65, $pressureChance + .10);
            $escape = max(.15, min(.8, .65 + ($off['QB']['ratings']['speed'] - 50) * .006 + ($off['QB']['ratings']['awareness'] - 50) * .003));
            $decision = $pressure ? $roll() : 1;
            if ($pressure && $decision < 1 - $escape) {
                $gain = -$yards($roll(), 3, 9);
                $outcome = 'sack';
                $carrier = 'QB';
            } elseif ($pressure && $roll() < .45) {
                $scramble = true;
                $carrier = 'QB';
                $gain = (int) round(-2 + $roll() * 12 + ($off['QB']['ratings']['speed'] - $front) * .08);
            } elseif ($pressure && $roll() < .3) {
                $throwaway = true;
                $outcome = 'incomplete';
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
                    } : 0) - ($pressure ? .08 : 0) - ($blitz ? .025 : 0) - ($defense === 'zone' ? .1 : 0) + ($defense === 'run_stop' ? .12 : 0)));
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
                'singleback', 'i_form' => 1, 'pistol' => .5, 'spread', 'trips' => -1.5, default => 0
            };
            $matchup += match ($defense) {
                'run_stop' => -3, 'zone' => 2, 'blitz' => -1, default => 0
            };
            $gain = (int) round(-2 + $roll() * 10 + $matchup);
            if ($roll() < .06 + max(0, $runner['speed'] - $front) * .002) {
                $gain += $yards($roll(), 8, 25);
            }
        }
        $outOfBounds = false;
        if ($clockStrategy === 'sideline' && $clock->lateHalf($before) && $outcome === 'tackle') {
            $chance = $carrier === 'WR1' ? .75 : ($call === 'outside_run' ? .65 : .3);
            $outOfBounds = $roll() < $chance;
            if ($outOfBounds) {
                $gain = max(0, $gain - 2);
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
        $name = fn ($player) => $player['name'].(isset($player['number']) ? ' (#'.$player['number'].')' : '');
        $qb = $name($off['QB']);
        $runner = $name($off[$carrier]);
        $receiver = $name($off['WR1']);
        $tackler = $name($def[$carrier === 'WR1' ? 'CB1' : 'LB2']);
        $interceptor = $name($def['CB1']);
        $yardage = $gain < 0 ? 'a loss of '.abs($gain).' yards' : "{$gain} yards";
        $completed = "{$qb} completes to {$receiver} for {$yardage}";
        $run = $scramble ? "{$qb} escapes pressure and scrambles for {$yardage}" : "{$qb} hands off to {$runner} for {$yardage}";
        $tackle = $deadSpot >= 100 ? '' : "; tackled by {$tackler}";
        $summary = str_replace('_', ' ', $design).': '.match ($outcome) {
            'incomplete' => $throwaway ? "{$qb} escapes the pocket and throws the ball away" : "{$qb}'s pass intended for {$receiver} is incomplete",
            'interception' => "{$qb}'s pass intended for {$receiver} is intercepted by {$interceptor} {$gain} yards downfield",
            'fumble' => ($carrier === 'WR1' ? $completed : ($carrier === 'QB' && ! $scramble ? "{$runner} is sacked by {$tackler} for {$yardage}" : $run))."; {$runner} fumbles, recovered by {$tackler}",
            'sack' => "{$qb} is sacked by {$tackler} for {$yardage}",
            default => ($carrier === 'WR1' ? $completed : $run).$tackle,
        };
        if ($flip) {
            $state['stats'][$side]['turnovers']++;
            if ($deadSpot <= 0) {
                $state[$other.'_score'] += 6;
                $summary .= ' · defensive touchdown';
                $this->possession($state, $other, 85);
                $state['phase'] = 'extra_point';
            } else {
                $this->possession($state, $other, $deadSpot >= 100 ? 20 : 100 - $deadSpot);
            }
        } elseif ($outcome !== 'incomplete' && $deadSpot >= 100) {
            $outcome = 'touchdown';
            $summary .= ' · TOUCHDOWN';
            $state[$side.'_score'] += 6;
            $this->possession($state, $side, 85);
            $state['phase'] = 'extra_point';
        } elseif ($outcome !== 'incomplete' && $deadSpot <= 0) {
            $outcome = 'safety';
            $summary .= ' · SAFETY';
            $state[$other.'_score'] += 2;
            $this->possession($state, $side, 20);
            $state['phase'] = 'kickoff';
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
        if ($outOfBounds && $outcome !== 'touchdown') {
            $summary .= ' · out of bounds, clock stopped';
        }
        $seconds = $yards($roll(), 5, 9);

        return $this->finish($state, $before, [
            'design' => $design, 'pressure' => $pressure, 'scramble' => $scramble, 'throwaway' => $throwaway, 'expect' => $expect, 'blitz' => $blitz, 'motion' => $motion,
            'call' => $call, 'defense' => $defense, 'offense_formation' => $offenseFormation, 'defense_formation' => $defenseFormation,
            'out_of_bounds' => $outOfBounds && $outcome !== 'touchdown', 'outcome' => $outcome, 'gain' => $gain, 'target' => $target, 'carrier' => $carrier, 'summary' => $summary,
        ], $rosters, $seconds);
    }

    public static function callsForState(array $state): array
    {
        return match ($state['phase'] ?? 'scrimmage') {
            'kickoff' => ['kickoff'], 'extra_point' => ['extra_point', 'two_point_run', 'two_point_pass'], default => array_values(array_diff(self::OFFENSE, ['kickoff', 'extra_point', 'two_point_run', 'two_point_pass'])),
        };
    }

    public static function defensesForCall(string $call): array
    {
        return match ($call) {
            'kickoff' => ['kickoff_return'], 'extra_point' => ['field_goal_block'],
            'punt' => ['punt_return', 'man_to_man', 'blitz'], 'field_goal' => ['field_goal_block', 'man_to_man', 'blitz'],
            default => ['man_to_man', 'run_stop', 'zone', 'blitz'],
        };
    }

    public function finish(array $state, array $before, array $play, array $rosters, int $seconds): array
    {
        $state = app(GameClock::class)->normalize($state);
        $snapBefore = app(GameClock::class)->normalize($before);
        $context = $before['_clock_context'] ?? [];
        $historyBefore = $context['before'] ?? $snapBefore;
        $play += ['runoff_seconds' => $context['runoff'] ?? 0, 'tempo' => $context['tempo'] ?? 'normal', 'clock_strategy' => $context['strategy'] ?? 'normal', 'snap_clock' => $snapBefore['clock']];
        $penalty = app(PenaltyRules::class)->live($snapBefore, $play);
        $decisionOptions = null;
        if ($penalty) {
            $result = app(PenaltyRules::class)->enforce($snapBefore, $state, $play, $penalty);
            $beneficiary = $result['play']['penalty']['beneficiary'];
            if (app(CpuCoach::class)->controls($state)[$beneficiary] === 'human') {
                foreach (['accept' => true, 'decline' => false] as $decision => $choice) {
                    $option = app(PenaltyRules::class)->enforce($snapBefore, $state, $play, $penalty, $choice);
                    $decisionOptions[$decision] = $this->finish($option['state'], $before, $option['play'], $rosters, $seconds);
                }
            }
            $state = $result['state'];
            $play = $result['play'];
        }
        $state = app(GameClock::class)->advance($state, $snapBefore, $play, $seconds);
        unset($state['_clock_context'], $historyBefore['_clock_context']);
        $before = $historyBefore;
        $state['version']++;
        $play += ['number' => $state['version'], 'before' => $before, 'after' => $state, 'duration' => 6];
        $play['summary'] = ucfirst($play['summary']);
        $animationPlay = array_merge($play, ['outcome' => $play['live_outcome'] ?? $play['outcome']]);
        $play['animation'] = ($play['no_snap'] ?? false) ? app(StoppageTimeline::class)->build($play, $rosters) : (in_array($play['call'], ['punt', 'field_goal', 'kickoff', 'extra_point'], true)
            ? app(SpecialTeamsTimeline::class)->build($animationPlay, $rosters) : app(PlayTimeline::class)->build($animationPlay, $rosters));

        $play['animation'] = FieldOrientation::animation($play['animation'], $before);

        $personnel = app(GamePersonnel::class)->afterPlay($state, $before, $play, $rosters);
        $state = $personnel['state'];
        $play['personnel_notices'] = $personnel['notices'];
        $play['after'] = $state;
        if ($personnel['notices']) {
            $play['summary'] .= ' · '.implode(' · ', $personnel['notices']);
        }

        if ($decisionOptions) {
            $play['penalty_options'] = $decisionOptions;
            $state['penalty_pending'] = true;
            $play['after'] = $state;
            $play['summary'] = 'FLAG: '.ucwords(str_replace('_', ' ', $play['penalty']['type'])).' · awaiting '.$play['penalty']['beneficiary'].' decision';
            $play['animation']['events'][count($play['animation']['events']) - 1][1] = $play['summary'];
        }

        return ['state' => $state, 'play' => $play];
    }

    public function timeout(array $state, array $rosters, string $side): array
    {
        $rosters = app(GamePersonnel::class)->active($rosters, $state);
        $state = app(GameClock::class)->normalize($state);
        if ($state['status'] !== 'playing' || ! in_array($side, ['home', 'away'], true) || $state['timeouts'][$side] <= 0 || ! $state['clock_running']) {
            throw new LogicException('A timeout needs a running clock and an available timeout.');
        }
        $before = $state;
        $state['timeouts'][$side]--;
        $state['clock_running'] = false;

        return $this->finish($state, $before, $this->stoppage(ucfirst($side).' timeout · '.$state['timeouts'][$side].' remaining this half', 'timeout'), $rosters, 0);
    }

    private function stoppage(string $summary, string $outcome): array
    {
        return ['call' => 'clock_event', 'defense' => 'man_to_man', 'offense_formation' => 'shotgun', 'defense_formation' => 'base_4_3', 'outcome' => $outcome, 'carrier' => 'RB', 'gain' => 0, 'target' => 0, 'summary' => $summary, 'no_snap' => true];
    }

    private function clockPlay(array $state, array $rosters, string $call, string $defense, string $offenseFormation, string $defenseFormation): array
    {
        $before = $state;
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $gain = $call === 'kneel' ? -1 : 0;
        $state['stats'][$side]['plays']++;
        $state['stats'][$side]['yards'] += $gain;
        $state['spot'] = max(0, $state['spot'] + $gain);
        $state['distance'] -= $gain;
        $state['down']++;
        $summary = $rosters[$side]['players']['QB']['name'].($call === 'kneel' ? ' takes a knee · loss of 1 yard' : ' spikes the ball · clock stopped');
        if ($state['spot'] === 0) {
            $state[$other.'_score'] += 2;
            $this->possession($state, $side, 20);
            $state['phase'] = 'kickoff';
            $summary .= ' · SAFETY';
        } elseif ($state['down'] > 4) {
            $this->possession($state, $other, 100 - $state['spot']);
            $summary .= ' · turnover on downs';
        }

        return $this->finish($state, $before, ['call' => $call, 'defense' => $defense, 'offense_formation' => $offenseFormation, 'defense_formation' => $defenseFormation, 'outcome' => $call, 'gain' => $gain, 'target' => 0, 'carrier' => 'QB', 'summary' => $summary], $rosters, $call === 'spike' ? 1 : 2);
    }

    private function twoPoint(array $state, array $rosters, string $call, string $defense, string $offenseFormation, string $defenseFormation, string $expect, bool $blitz, string $motion): array
    {
        $before = $state;
        $before['spot'] = 98 + ($state['try_adjustment'] ?? 0);
        $before['distance'] = 100 - $before['spot'];
        $before['clock_running'] = false;
        $simulation = array_merge($before, ['phase' => 'scrimmage', 'clock' => 180, 'quarter' => 1, 'untimed_down' => false, '_personnel_simulation' => true]);
        $resolved = $this->resolve($simulation, $rosters, $call === 'two_point_pass' ? 'short_pass' : 'inside_run', $defense, $offenseFormation, $defenseFormation, 'normal', 'normal', $expect, $blitz, $motion);

        return $this->finishTry($state, $before, $resolved, $rosters, $call);
    }

    private function finishTry(array $state, array $before, array $resolved, array $rosters, string $call): array
    {
        $play = $resolved['play'];
        $options = $play['penalty_options'] ?? null;
        $good = $play['outcome'] === 'touchdown';
        $retry = ($play['no_snap'] ?? false) || (($play['penalty']['accepted'] ?? false) && ! $good);
        $side = $state['possession'];
        $state[$side.'_score'] += $good ? 2 : 0;
        $other = $side === 'home' ? 'away' : 'home';
        if (! $retry && $play['outcome'] === 'safety') {
            $state[$other.'_score']++;
        }
        if (! $retry && in_array($play['outcome'], ['interception', 'fumble'], true)) {
            $returnRoll = (int) sprintf('%u', crc32($state['seed'].':'.$state['version'].':try_return')) % 100;
            if ($returnRoll < 3 || $resolved['state'][$other.'_score'] > $before[$other.'_score']) {
                $state[$other.'_score'] += 2;
                $play['defensive_return'] = true;
                $play['summary'] .= ' · defensive return for TWO POINTS';
            }
        }
        $state['phase'] = $retry ? 'extra_point' : 'kickoff';
        $state['spot'] = $retry ? $resolved['state']['spot'] : 35;
        $state['down'] = 1;
        $state['distance'] = $retry ? 100 - $state['spot'] : 10;
        $state['clock_running'] = false;
        unset($state['penalty_pending']);
        if ($retry) {
            $state['try_adjustment'] = $state['spot'] - 98;
        } else {
            unset($state['try_adjustment']);
        }
        foreach (['home', 'away'] as $team) {
            foreach (['penalties', 'penalty_yards'] as $stat) {
                $state['stats'][$team][$stat] = $resolved['state']['stats'][$team][$stat];
            }
        }
        $play = array_intersect_key($play, array_flip(['defense', 'offense_formation', 'defense_formation', 'gain', 'target', 'carrier', 'outcome', 'live_outcome', 'summary', 'penalty', 'no_snap', 'defensive_return']));
        if (isset($play['penalty'])) {
            $play['penalty']['explanation'] = str_replace('an automatic first down', 'a replay of the try', $play['penalty']['explanation']);
            $play['summary'] = str_replace('automatic first down', 'replay try', $play['summary']);
        }
        $play['call'] = $call;
        $play['conversion'] = true;
        $play['summary'] = 'Two-point try · '.$play['summary'].' · '.($retry ? 'Replay the try' : ($good ? 'GOOD (2 points)' : 'NO GOOD'));
        $finished = $this->finish($state, $before, $play, $rosters, 0);
        if ($options) {
            foreach ($options as $decision => $option) {
                $finished['play']['penalty_options'][$decision] = $this->finishTry($before, $before, $option, $rosters, $call);
            }
            $finished['state']['penalty_pending'] = true;
            $finished['play']['after'] = $finished['state'];
        }

        return $finished;
    }

    private function possession(array &$state, string $side, int $spot): void
    {
        $state['possession'] = $side;
        $state['spot'] = $spot;
        $state['down'] = 1;
        $state['distance'] = min(10, 100 - $spot);
    }
}
