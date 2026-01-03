<?php

namespace App\Services;

use App\Models\OffensePlay;
use App\Models\DefensePlay;
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
}
