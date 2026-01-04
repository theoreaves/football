<?php

namespace App\Services;

use App\Models\OffensePlay;
use App\Models\DefensePlay;
use App\Models\Player;
use App\Models\Team;

class GamePlayEngine
{

    public $offensivePlayers = [
        'QB1',
        'RB1',
        'RB2',
        'RB3',
        'RB4',
        'WR1',
        'WR2',
        'WR3',
        'WR4',
        'TE1',
        'TE2',
    ];

    public $defensivePlayers = [
        'DL1',
        'DL2',
        'DL3',
        'DL4',
        'LB1',
        'LB2',
        'LB3',
        'CB1',
        'CB2',
        'S1',
        'S2',
    ];


    /**
     * Resolve the play result based on play codes, rolls, team IDs, dice, and redzone.
     *
     * @param string $offenseCode
     * @param string $defenseCode
     * @param int $resultRoll
     * @param int $skillRoll
     * @param int $offenseTeamId
     * @param int $defenseTeamId
     * @param int $playerDie
     * @param int $tacklerDie
     * @param int $disrupterDie
     * @param bool $redzone
     * @param bool $offenseIsHome
     * @return array|string
     */
    public function resolvePlayResult(
        string $offenseCode,
        string $defenseCode,
        int $resultRoll,
        int $skillRoll,
        int $offenseTeamId,
        int $defenseTeamId,
        int $playerDie = 0,
        int $tacklerDie = 0,
        int $disrupterDie = 0,
        bool $redzone = false,
        bool $offenseIsHome = false
    ): array|string {
        $offensePlay = OffensePlay::with('rolls')->where('code', $offenseCode)->first();
        if (!$offensePlay) {
            return 'Invalid offense play code.';
        }
        $offenseRoll = $offensePlay->rolls->first(function ($roll) use ($resultRoll) {
            return $resultRoll >= $roll->roll_min && $resultRoll <= $roll->roll_max;
        });
        if (!$offenseRoll) {
            return 'No matching offense roll.';
        }
        $playerType = strtoupper($offenseRoll->player);
        $playType = strtoupper($offenseRoll->play->play_type);
        if ($playerType === 'DEF') {
            $defensePlay = DefensePlay::with('rolls')->where('code', $defenseCode)->first();
            if (!$defensePlay) {
                return 'Invalid defense play code.';
            }
            $defenseRoll = $defensePlay->rolls->first(function ($roll) use ($resultRoll) {
                return $resultRoll >= $roll->roll_min && $resultRoll <= $roll->roll_max;
            });
            if (!$defenseRoll) {
                return 'No matching defense roll.';
            }
            if (strtoupper($defenseRoll->roll_label) === 'PRESSURE') {
                $pressurePlay = OffensePlay::with('rolls')->where('code', 'PRESSURE')->first();
                if (!$pressurePlay) {
                    return 'No PRESSURE offense play.';
                }
                $pressureRoll = $pressurePlay->rolls->first(function ($roll) use ($resultRoll) {
                    return $resultRoll >= $roll->roll_min && $resultRoll <= $roll->roll_max;
                });
                if (!$pressureRoll) {
                    return 'No matching PRESSURE roll.';
                }
                $offenseRoll = $pressureRoll;
                $playerType = strtoupper($offenseRoll->player);
            } else {
                $offenseRoll = $defenseRoll;
                $playerType = strtoupper($defenseRoll->player);
            }
        }
        if ($playerType === 'PENALITY') {
            return 'Penality: ' . $offenseRoll->rating . ' ' . $offenseRoll->skill_pass . ' ' . $offenseRoll->skill_fail;
        }
        if ($playerType === 'AUTO') {
            $ratingKey = strtoupper($offenseRoll->rating);
            if ($ratingKey === 'HOME') {
                // If offense team is home, pass; else fail
                return $offenseIsHome ? $offenseRoll->skill_pass : $offenseRoll->skill_fail;
            }
            if ($ratingKey === 'REDZONE') {
                return !$redzone ? $offenseRoll->skill_pass : $offenseRoll->skill_fail;
            }
            return 'AUTO';
        }
//        $teamId = $playerType === 'DEF' ? $defenseTeamId : $offenseTeamId;
        if (in_array($playerType, $this->offensivePlayers)) {
            $teamId = $offenseTeamId;
            $otherTeamID = $defenseTeamId;
        } else {
            $teamId = $defenseTeamId;
            $otherTeamID = $offenseTeamId;
        }
        $team = Team::with('players')->find($teamId);
        $otherTeam = Team::with('players')->find($otherTeamID);

        if (!$team) {
            return 'Team not found.';
        }

        // Special handling for OL (offensive line) - must come before player lookup
        if ($playerType === 'OL') {
            $olRatingMap = [
                'RUSH' => 'ol_rush',
                'POWER' => 'ol_power',
                'PASS' => 'ol_pass',
                'PROTECT' => 'ol_protect',
            ];
            $ratingKey = strtoupper($offenseRoll->rating);
            $olField = $olRatingMap[$ratingKey] ?? null;
            if ($olField && isset($team->$olField)) {
                $olValue = $team->$olField;
                $pass = $skillRoll <= $olValue;
                return [
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'ol_rating_field' => $olField,
                    'ol_rating_value' => $olValue,
                    'skill_roll' => $skillRoll,
                    'result' => $pass ? $offenseRoll->skill_pass : $offenseRoll->skill_fail,
                ];
            } else {
                return [
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'ol_rating_field' => $olField,
                    'message' => 'OL rating not found for rating: ' . $offenseRoll->rating,
                ];
            }
        }

        // Only look up a player if not OL
        $player = $team->players->first(function ($p) use ($offenseRoll) {
            return $p->pivot->depth_chart_position === $offenseRoll->player;
        });
        if (!$player) {
            return 'Player not found for position: ' . $offenseRoll->player;
        }
        $ratingMap = [
            'RUSH' => 'rush',
            'TACKLE' => 'tackle',
            'STRIP' => 'strip',
            'REC' => 'receive',
            'ACC' => 'pass_accuracy',
            'CTRL' => 'pass_control',
            'SACK' => 'sack',
            'COVER' => 'cover',
            'PDEEP' => 'pass_deep',
            'RDEEP' => 'receive_deep',
            'INT' => 'interception',
            'EVADE' => 'pass_evade',
            'POWER' => 'rush_power'
        ];
        $ratingKey = strtoupper($offenseRoll->rating);
        $playerSkillField = $ratingMap[$ratingKey] ?? null;
        if ($playerSkillField && isset($player->$playerSkillField)) {
            $playerSkill = $player->$playerSkillField;
            $pass = $skillRoll <= $playerSkill;
            $result =  $pass ? $offenseRoll->skill_pass : $offenseRoll->skill_fail;
            $yards = YardageParserService::parseYards($result, $skillRoll);


            if (in_array($playerType, $this->defensivePlayers)) {
                $tackler = $player;
                $offense_player = null;

                if ($playType === 'PASS') {
                    $offense_player = $otherTeam->players->first(function ($p) use ($playerDie) {
                        return $playerDie >= $p->pivot->catch_from && $playerDie <= $p->pivot->catch_to;
                    });
                    $quarterback = $otherTeam->players->first(function ($p) {
                        return $p->pivot->depth_chart_position === 'QB1';
                    });
                } elseif ($playType === 'PASS+') {
                    $offense_player = $otherTeam->players->first(function ($p) use ($playerDie) {
                        return $playerDie >= $p->pivot->catch_plus_from && $playerDie <= $p->pivot->catch_plus_to;
                    });
                } elseif ($playType === 'RUN') {
                    $offense_player = $otherTeam->players->first(function ($p) use ($playerDie) {
                        return $playerDie >= $p->pivot->rush_from && $playerDie <= $p->pivot->rush_to;
                    });
                } else {
                    $offense_player = $otherTeam->players->first(function ($p) use ($playerDie) {
                        return $p->pivot->depth_chart_position === $playerDie;
                    });
                }
            }

            if (in_array($playerType, $this->offensivePlayers)) {
                $offense_player = $player;
                $tackler = null;

                $tackler = $otherTeam->players->first(function ($p) use ($playerDie) {
                    return $playerDie >= $p->pivot->tackle_from && $playerDie <= $p->pivot->tackle_to;
                });
                $quarterback = $team->players->first(function ($p) {
                    return $p->pivot->depth_chart_position === 'QB1';
                });
            }


            return [
                'play_type' => $playType,
                'player_id' => $player->id,
                'player_name' => $player->firstname . ' ' . $player->lastname,
                'jersey_number' => $player->current_jersey_number,
                'position' => $offenseRoll->player,
                'rating' => $offenseRoll->rating,
                'player_skill' => $playerSkill,
                'skill_roll' => $skillRoll,
                'result' => $pass ? $offenseRoll->skill_pass : $offenseRoll->skill_fail,
                'roll_label' => $offenseRoll->roll_label,
                'yards' => $yards,
                'tackler_id' => isset($tackler) ? $tackler->id : null,
                'tackler_name' => isset($tackler) ? $tackler->firstname . ' ' . $tackler->lastname : null,
                'tackler_jersey_number' => isset($tackler) ? $tackler->current_jersey_number : null,
                'offense_player_id' => isset($offense_player) ? $offense_player->id : null,
                'offense_player_name' => isset($offense_player) ? $offense_player->firstname . ' ' . $offense_player->lastname : null,
                'offense_player_jersey_number' => isset($offense_player) ? $offense_player->current_jersey_number : null,
                'offense_player_speed' => isset($offense_player) ? $offense_player->speed : null,
                'quarterback_id' => isset($quarterback) ? $quarterback->id : null,
                'quarterback_name' => isset($quarterback) ? $quarterback->firstname . ' ' . $quarterback->lastname : null,
                'quarterback_jersey_number' => isset($quarterback) ? $quarterback->current_jersey_number : null,
            ];
        }
        return [
            'player_id' => $player->id,
            'player_name' => $player->firstname . ' ' . $player->lastname,
            'position' => $offenseRoll->player,
            'rating' => $offenseRoll->rating,
            'skill_pass' => $offenseRoll->skill_pass,
            'skill_fail' => $offenseRoll->skill_fail,
            'roll_label' => $offenseRoll->roll_label,
            'ratingKey' => $ratingKey,
            'playerSkillField' => $playerSkillField,
            'message' => 'Player skill not found for rating: ' . $offenseRoll->rating
        ];
    }
    public function breakaway(int $resultRoll, int $blueDie, int $speed): array
    {
        $speedRoll = $resultRoll + $speed;
        if ($speedRoll < 23) {
            return [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => $resultRoll,
            ];
        }

        if ($speedRoll > 30) {
            return [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => 100, //touchdown
            ];
        }

        return match ($speedRoll) {
            23, 24 => [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => 20 + $blueDie,
            ],
            25, 31, => [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => 100, //touchdown
            ],
            26 => [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => 30 + $blueDie,
            ],
            27 => [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => 40 + $blueDie,
            ],
            28 => [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => 50 + $blueDie,
            ],
            29 => [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => 70 + $blueDie,
            ],
            30 => [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => 90 + $blueDie,
            ],
            default => [
                'speed' => $speed,
                'result_roll' => $resultRoll,
                'speed_roll' => $speedRoll,
                'blue_die' => $blueDie,
                'yards' => $resultRoll, // fallback to resultRoll as yards
            ],
        };
    }

    /**
     * Helper to parse and evaluate kickoff formulas.
     *
     * @param string $formula
     * @param array $vars
     * @return int|string
     */
    protected function parseResultFormula(string $formula, array $vars): int|string
    {
        $formula = str_replace(['red', 'white', 'blue', 'KR'], [
            $vars['red'], $vars['white'], $vars['blue'], $vars['KR']
        ], $formula);
        $formula = trim($formula);

        // Handle special cases
        if (in_array($formula, ['B!', 'TB', 'OB', '40YL'])) {
            return $formula;
        }

        // If the formula is a valid math expression (only numbers, math operators, parentheses, spaces), evaluate it
        if (preg_match('/^[\d+\-*\/().\s]+$/', $formula)) {
//            if (preg_match('/^[\d+\-*/().\s]+$/', $formula)) {
            try {
                $result = eval('return ' . $formula . ';');
                return (int)$result;
            } catch (\Throwable $e) {
                return $formula;
            }
        }

        // Otherwise, return as-is (contains non-math characters)
        return $formula;
    }

    public function kickoff(Player $kickReturner, int $redDie, int $whiteDie, int $blueDie): array
    {
        $resultRoll = ((string)$redDie . (string)$whiteDie) * 1;
        $kickoffConfig = config('special_teams.kickoffs');
        $kickResult = null;
        $returnResult = null;
        foreach ($kickoffConfig as $range => $values) {
            if (preg_match('/^(\\d+)$/', $range, $m)) {
                if ($resultRoll == (int)$m[1]) {
                    $kickResult = $values['kick'];
                    $returnResult = $values['return'];
                    break;
                }
            } elseif (preg_match('/^(\\d+)-(\\d+)$/', $range, $m)) {
                $min = (int)$m[1];
                $max = (int)$m[2];
                if ($resultRoll >= $min && $resultRoll <= $max) {
                    $kickResult = $values['kick'];
                    $returnResult = $values['return'];
                    break;
                }
            }
        }
        if ($kickResult === null || $returnResult === null) {
            return [
                'result_roll' => $resultRoll,
                'kick' => null,
                'return' => null,
                'message' => 'No kickoff result found for roll: ' . $resultRoll,
            ];
        }
        $vars = [
            'red' => $redDie,
            'white' => $whiteDie,
            'blue' => $blueDie,
            'KR' => $kickReturner->return_speed ?? 0,
        ];
        $kickYards = $this->parseResultFormula($kickResult, $vars);
        $returnYards = $this->parseResultFormula($returnResult, $vars);
        $isBreakaway = ($kickYards === 'B!' || $returnYards === 'B!');
        return [
            'result_roll' => $resultRoll,
            'kick_formula' => $kickResult,
            'return_formula' => $returnResult,
            'kick' => $kickYards,
            'return' => $returnYards,
            'breakaway' => $isBreakaway,
        ];
    }

    public function punt($resultRoll): array
    {
        $puntConfig = config('special_teams.punts');
        foreach ($puntConfig as $range => $values) {
            if (preg_match('/^(\\d+)$/', $range, $m)) {
                if ($resultRoll == (int)$m[1]) {
                    $distance = $values['distance'];
                    $type = $values['type'];
                    break;
                }
            } elseif (preg_match('/^(\\d+)-(\\d+)$/', $range, $m)) {
                $min = (int)$m[1];
                $max = (int)$m[2];
                if ($resultRoll >= $min && $resultRoll <= $max) {
                    $distance = $values['distance'];
                    $type = $values['type'];
                    break;
                }
            }
        }
        return [
            'result_roll' => $resultRoll,
            'distance' => $distance ?? null,
            'type' => $type ?? null,
        ];
    }

    public function puch_punt(Player $punter, $resultRoll, $skillRoll): array
    {
        $poochConfig = config('special_teams.pooch_punts');
        $result = null;
        foreach ($poochConfig as $range => $values) {
            if (preg_match('/^(\d+)-(\d+)$/', $range, $m)) {
                $min = (int)$m[1];
                $max = (int)$m[2];
                if ($resultRoll >= $min && $resultRoll <= $max) {
                    $result = $values;
                    break;
                }
            } elseif (preg_match('/^(\d+)\+$/', $range, $m)) {
                $min = (int)$m[1];
                if ($resultRoll >= $min) {
                    $result = $values;
                    break;
                }
            } elseif (preg_match('/^(\d+)$/', $range, $m)) {
                if ($resultRoll == (int)$m[1]) {
                    $result = $values;
                    break;
                }
            }
        }
        if (!$result) {
            return [
                'result_roll' => $resultRoll,
                'spot' => null,
                'type' => null,
                'message' => 'No pooch punt result found for roll: ' . $resultRoll,
            ];
        }
        $key = ($punter->punt_pooch > $skillRoll) ? 'skill_greater' : 'skill_less';
        $outcome = $result[$key] ?? null;
        if (!$outcome) {
            return [
                'result_roll' => $resultRoll,
                'spot' => null,
                'type' => null,
                'message' => 'No outcome found for key: ' . $key,
            ];
        }
        return [
            'result_roll' => $resultRoll,
            'spot' => $outcome['spot'] ?? null,
            'type' => $outcome['type'] ?? null,
        ];
    }

    public function punt_return(Player $kickReturner, $resultRoll, $skillRoll): array
    {
        $puntReturnConfig = config('special_teams.punt_returns');
        foreach ($puntReturnConfig as $range => $values) {
            if (preg_match('/^(\\d+)$/', $range, $m)) {
                if ($resultRoll == (int)$m[1]) {
                    $yards = $values['yards'];
                    break;
                }
            } elseif (preg_match('/^(\\d+)-(\\d+)$/', $range, $m)) {
                $min = (int)$m[1];
                $max = (int)$m[2];
                if ($resultRoll >= $min && $resultRoll <= $max) {
                    $yards = $values['yards'];
                    break;
                }
            }
        }
        $vars = [
            'red' => 0,
            'white' => 0,
            'blue' => $skillRoll,
            'KR' => $kickReturner->return_speed ?? 0,
        ];
        $kickYards = $this->parseResultFormula($yards, $vars);
        return [
            'result_roll' => $resultRoll,
            'return' => $yards ?? null,
            'yards' => $kickYards
        ];
    }

    public function field_goal_attempt($yards, $resultRoll): bool
    {
        $fgConfig = config('special_teams.field_goals');
        $requiredRoll = null;
        foreach ($fgConfig as $range => $value) {
            if (strpos($range, '-') !== false) {
                [$min, $max] = explode('-', $range);
                if ($yards >= (int)$min && $yards <= (int)$max) {
                    $requiredRoll = (int)$value;
                    break;
                }
            } elseif (strpos($range, '+') !== false) {
                $min = (int)str_replace('+', '', $range);
                if ($yards >= $min) {
                    $requiredRoll = (int)$value;
                    break;
                }
            } else {
                if ($yards == (int)$range) {
                    $requiredRoll = (int)$value;
                    break;
                }
            }
        }
        if ($requiredRoll === null) {
            return false; // Out of range, treat as miss
        }
        return $resultRoll >= $requiredRoll;
    }
}
