<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Play;

class GameStatService
{
    public function forGame(Game $game): array
    {
        $plays = Play::query()
            ->where('game_id', $game->id)
            ->with([
                'qbTeamPlayer.player',
                'ballcarrierTeamPlayer.player',
                'receiverTeamPlayer.player',
                'tackledByTeamPlayer.player',
                'interceptedByTeamPlayer.player',
                'fumbleRecoveredByTeamPlayer.player',
            ])
            ->orderBy('seq')
            ->get();

        // Helpers
        $blank = fn() => [
            'passing' => ['att' => 0, 'cmp' => 0, 'yds' => 0, 'td' => 0, 'int' => 0, 'sacks' => 0, 'sack_yds' => 0],
            'rushing' => ['att' => 0, 'yds' => 0, 'td' => 0],
            'receiving' => ['rec' => 0, 'yds' => 0, 'td' => 0],
            'defense' => ['tkl' => 0, 'sacks' => 0, 'int' => 0, 'int_yds' => 0, 'fum_rec' => 0],
        ];

        $players = []; // [team_player_id => ['meta'=>..., 'stats'=>...]]
        $teams = [
            'HOME' => $blank(),
            'AWAY' => $blank(),
        ];

        $sideForTeamId = function ($teamId) use ($game) {
            if ((int)$teamId === (int)$game->home_team_id) return 'HOME';
            if ((int)$teamId === (int)$game->away_team_id) return 'AWAY';
            return null;
        };

        $ensure = function ($tp) use (&$players, $blank, $sideForTeamId) {
            if (!$tp) return null;

            $id = (int) $tp->id;
            if (!isset($players[$id])) {
                $side = $sideForTeamId($tp->team_id);

                $players[$id] = [
                    'team_player_id' => $id,
                    'side' => $side,
                    'team_id' => $tp->team_id,
                    'jersey' => $tp->jersey_number,
                    'pos' => $tp->position,
                    'name' => trim(($tp->player->firstname ?? '') . ' ' . ($tp->player->lastname ?? '')),
                    'stats' => $blank(),
                ];
            }
            return $players[$id];
        };

        $addTeam = function (string $side, string $group, string $key, int $val) use (&$teams) {
            if (!isset($teams[$side])) return;
            $teams[$side][$group][$key] += $val;
        };

        $addPlayer = function ($tp, string $group, string $key, int $val) use (&$players, $ensure) {
            if (!$tp) return;
            $ensure($tp);
            $players[$tp->id]['stats'][$group][$key] += $val;
        };

        foreach ($plays as $p) {
            $type = strtoupper((string)$p->type);
            $yards = (int)($p->yards ?? 0);
            $td = (int)($p->touchdown ?? 0);

            // Common participants
            $qb   = $p->qbTeamPlayer;
            $bc   = $p->ballcarrierTeamPlayer;
            $wr   = $p->receiverTeamPlayer;
            $tkl  = $p->tackledByTeamPlayer;
            $intBy = $p->interceptedByTeamPlayer;
            $fumRec = $p->fumbleRecoveredByTeamPlayer;

            // Passing: PASS, INCOMPLETE, INT are attempts
            if (in_array($type, ['PASS', 'INCOMPLETE', 'INT'], true)) {
                if ($qb) {
                    $ensure($qb);
                    $side = $players[$qb->id]['side'];

                    $addPlayer($qb, 'passing', 'att', 1);
                    if ($side) $addTeam($side, 'passing', 'att', 1);

                    if ($type === 'PASS') {
                        $addPlayer($qb, 'passing', 'cmp', 1);
                        $addPlayer($qb, 'passing', 'yds', $yards);
                        $addPlayer($qb, 'passing', 'td', $td);
                        if ($side) {
                            $addTeam($side, 'passing', 'cmp', 1);
                            $addTeam($side, 'passing', 'yds', $yards);
                            $addTeam($side, 'passing', 'td', $td);
                        }

                        // receiving stats for completed pass
                        if ($wr) {
                            $ensure($wr);
                            $wrSide = $players[$wr->id]['side'];
                            $addPlayer($wr, 'receiving', 'rec', 1);
                            $addPlayer($wr, 'receiving', 'yds', $yards);
                            $addPlayer($wr, 'receiving', 'td', $td);
                            if ($wrSide) {
                                $addTeam($wrSide, 'receiving', 'rec', 1);
                                $addTeam($wrSide, 'receiving', 'yds', $yards);
                                $addTeam($wrSide, 'receiving', 'td', $td);
                            }
                        }
                    }

                    if ($type === 'INT') {
                        $addPlayer($qb, 'passing', 'int', 1);
                        if ($side) $addTeam($side, 'passing', 'int', 1);
                    }
                }

                // Tackles on pass/incomplete (you’re recording it) – counts as tackle if present
                if ($tkl) {
                    $ensure($tkl);
                    $tSide = $players[$tkl->id]['side'];
                    $addPlayer($tkl, 'defense', 'tkl', 1);
                    if ($tSide) $addTeam($tSide, 'defense', 'tkl', 1);
                }

                // Defensive INT + return yards: on INT play, yards should represent return yards (as you’ve been doing)
                if ($type === 'INT' && $intBy) {
                    $ensure($intBy);
                    $iSide = $players[$intBy->id]['side'];
                    $addPlayer($intBy, 'defense', 'int', 1);
                    $addPlayer($intBy, 'defense', 'int_yds', $yards);
                    if ($iSide) {
                        $addTeam($iSide, 'defense', 'int', 1);
                        $addTeam($iSide, 'defense', 'int_yds', $yards);
                    }
                }
            }

            // Rushing: only RUN counts as rushing (SACK does not)
            if ($type === 'RUN') {
                if ($bc) {
                    $ensure($bc);
                    $side = $players[$bc->id]['side'];
                    $addPlayer($bc, 'rushing', 'att', 1);
                    $addPlayer($bc, 'rushing', 'yds', $yards);
                    $addPlayer($bc, 'rushing', 'td', $td);
                    if ($side) {
                        $addTeam($side, 'rushing', 'att', 1);
                        $addTeam($side, 'rushing', 'yds', $yards);
                        $addTeam($side, 'rushing', 'td', $td);
                    }
                }

                if ($tkl) {
                    $ensure($tkl);
                    $tSide = $players[$tkl->id]['side'];
                    $addPlayer($tkl, 'defense', 'tkl', 1);
                    if ($tSide) $addTeam($tSide, 'defense', 'tkl', 1);
                }
            }

            // Sacks: count as QB sack taken + tackler sack made, but NOT QB rush attempt
            if ($type === 'SACK') {
                if ($qb) {
                    $ensure($qb);
                    $side = $players[$qb->id]['side'];
                    $addPlayer($qb, 'passing', 'sacks', 1);
                    $addPlayer($qb, 'passing', 'sack_yds', $yards); // usually negative
                    if ($side) {
                        $addTeam($side, 'passing', 'sacks', 1);
                        $addTeam($side, 'passing', 'sack_yds', $yards);
                    }
                }

                if ($tkl) {
                    $ensure($tkl);
                    $tSide = $players[$tkl->id]['side'];
                    $addPlayer($tkl, 'defense', 'sacks', 1);
                    if ($tSide) $addTeam($tSide, 'defense', 'sacks', 1);
                }
            }

            // Fumbles: only FUMBLE play type counts here
            if ($type === 'FUMBLE' && $fumRec) {
                $ensure($fumRec);
                $fSide = $players[$fumRec->id]['side'];
                $addPlayer($fumRec, 'defense', 'fum_rec', 1);
                if ($fSide) $addTeam($fSide, 'defense', 'fum_rec', 1);
            }
        }

        // Sort players: by side then jersey then name
        $playersSorted = collect($players)
            ->sortBy(fn($row) => sprintf('%s-%03d-%s', $row['side'] ?? 'Z', (int)$row['jersey'], $row['name']))
            ->values()
            ->all();

        return [
            'teams' => $teams,
            'players' => $playersSorted,
        ];
    }
}
