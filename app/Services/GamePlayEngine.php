<?php

namespace App\Services;

use App\Models\OffensePlay;
use App\Models\DefensePlay;
use App\Models\Team;

class GamePlayEngine
{
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
        bool $redzone = false
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
                $isHome = $offenseTeamId < $defenseTeamId; // You may want to adjust this logic based on your home/away system
                return $isHome ? $offenseRoll->skill_pass : $offenseRoll->skill_fail;
            }
            if ($ratingKey === 'REDZONE') {
                return !$redzone ? $offenseRoll->skill_pass : $offenseRoll->skill_fail;
            }
            return 'AUTO';
        }
        $teamId = $playerType === 'DEF' ? $defenseTeamId : $offenseTeamId;
        $team = Team::with('players')->find($teamId);
        if (!$team) {
            return 'Team not found.';
        }
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
            return [
                'player' => $player->name ?? $player->id,
                'position' => $offenseRoll->player,
//                'rating' => $offenseRoll->rating,
                'player_skill' => $playerSkill,
//                'skill_roll' => $skillRoll,
                'result' => $pass ? $offenseRoll->skill_pass : $offenseRoll->skill_fail,
//                'roll_label' => $offenseRoll->roll_label,
            ];
        }
        return [
            'player' => $player->name ?? $player->id,
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
