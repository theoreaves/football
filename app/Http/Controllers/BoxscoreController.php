<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Play;
use Illuminate\Support\Facades\DB;

class BoxscoreController extends Controller
{
    public function show(Game $game)
    {
        // Load teams (adjust relation names to match yours)
        $game->load(['homeTeam', 'awayTeam']);

        // Pull plays in order
        $plays = Play::where('game_id', $game->id)
            ->orderBy('seq')
            ->get();

        // Score by quarter (use game columns if you already store arrays)
        // If you store home_q/away_q arrays, keep that.
        $homeQ = $game->home_q ?? [0,0,0,0,0];
        $awayQ = $game->away_q ?? [0,0,0,0,0];

        // Scoring summary: anything that produced points or a TD/FG/try flag
        // Adjust columns to your schema (points, touchdown, type, etc.)
        $scoring = $plays
            ->filter(function ($p) {
                return (int)($p->points ?? 0) !== 0
                    || (int)($p->touchdown ?? 0) === 1
                    || in_array(($p->type ?? ''), ['FIELDGOAL', 'TRY'], true);
            })
            ->values();

        // Team stats (game-level aggregates from plays)
        $teamStats = $this->buildTeamStats($game, $plays);

        // Individual player stats (reuse your stats logic; this is the “boxscore view model” form)
        $playerStats = $this->buildPlayerStats($game);

        return view('games.boxscore', [
            'game'        => $game,
            'plays'       => $plays,
            'homeQ'       => $homeQ,
            'awayQ'       => $awayQ,
            'scoring'     => $scoring,
            'teamStats'   => $teamStats,
            'playerStats' => $playerStats,
        ]);
    }

    private function buildTeamStats(Game $game, $plays): array
    {
        // Map HOME/AWAY => team_id
        $sideToTeamId = [
            'HOME' => $game->home_team_id,
            'AWAY' => $game->away_team_id,
        ];

        // Helper to sum yards by play type and possession team
        $sum = function (string $side, array $types) use ($plays) {
            return $plays
                ->where('possession_before', $side)
                ->whereIn('type', $types)
                ->sum('yards');
        };

        $count = function (string $side, array $types) use ($plays) {
            return $plays
                ->where('possession_before', $side)
                ->whereIn('type', $types)
                ->count();
        };

        $passAtt = fn(string $side) => $count($side, ['PASS','INCOMPLETE','INT', 'INTERCEPTION']); // sacks excluded
        $passComp = fn(string $side) => $count($side, ['PASS']); // “PASS” is complete per your rules
        $passYds = fn(string $side) => $sum($side, ['PASS','INCOMPLETE','INT', 'INTERCEPTION']); // if you store yards on incompletions as 0 this is fine
        $rushAtt = fn(string $side) => $count($side, ['RUN']); // sacks are NOT rush attempts (per your rule)
        $rushYds = fn(string $side) => $sum($side, ['RUN']);

        $turnovers = function (string $side) use ($plays) {
            // turnover flag already exists on plays per your summary code
            return $plays->where('possession_before', $side)->where('turnover', 1)->count();
        };

        $sacksTaken = fn(string $side) => $plays->where('possession_before', $side)->where('type', 'SACK')->count();

        $tds = fn(string $side) => $plays->where('possession_before', $side)->where('touchdown', 1)->count();

        $firstDowns = fn(string $side) => $plays->where('possession_before', $side)->where('first_down', 1)->count();

        $home = [
            'side' => 'HOME',
            'team_id' => $sideToTeamId['HOME'],
            'rush_att' => $rushAtt('HOME'),
            'rush_yds' => $rushYds('HOME'),
            'pass_comp' => $passComp('HOME'),
            'pass_att' => $passAtt('HOME'),
            'pass_yds' => $passYds('HOME'),
            'first_downs' => $firstDowns('HOME'),
            'turnovers' => $turnovers('HOME'),
            'sacks_taken' => $sacksTaken('HOME'),
            'tds' => $tds('HOME'),
        ];

        $away = [
            'side' => 'AWAY',
            'team_id' => $sideToTeamId['AWAY'],
            'rush_att' => $rushAtt('AWAY'),
            'rush_yds' => $rushYds('AWAY'),
            'pass_comp' => $passComp('AWAY'),
            'pass_att' => $passAtt('AWAY'),
            'pass_yds' => $passYds('AWAY'),
            'first_downs' => $firstDowns('AWAY'),
            'turnovers' => $turnovers('AWAY'),
            'sacks_taken' => $sacksTaken('AWAY'),
            'tds' => $tds('AWAY'),
        ];

        return compact('home', 'away');
    }

    private function buildPlayerStats(Game $game): array
    {
        /**
         * This assumes your plays table has:
         * qb_team_player_id, ballcarrier_team_player_id, receiver_team_player_id,
         * tackled_by_team_player_id, intercepted_by_team_player_id, fumble_recovered_by_team_player_id
         *
         * We’ll compute:
         * - Passing: QB (comp/att/yds/td/int)
         * - Rushing: ballcarrier (att/yds/td)
         * - Receiving: receiver (rec/yds/td)
         * - Defense: tackled_by (tackles), intercepted_by (ints)
         *
         * If you already built a StatsService, swap this method to call it.
         */

        $plays = Play::where('game_id', $game->id)->get();

        $byTeam = [
            'HOME' => ['team_id' => $game->home_team_id, 'label' => 'HOME'],
            'AWAY' => ['team_id' => $game->away_team_id, 'label' => 'AWAY'],
        ];

        // quick fetch map team_player_id => display
        $tpRows = DB::table('team_players')
            ->join('players', 'players.id', '=', 'team_players.player_id')
            ->whereIn('team_players.team_id', [$game->home_team_id, $game->away_team_id])
            ->where('team_players.team_year', $game->season_year ?? $game->year ?? 2025) // adjust if needed
            ->select([
                'team_players.id as team_player_id',
                'team_players.team_id',
                'team_players.jersey_number',
                'players.firstname',
                'players.lastname',
                'team_players.position',
            ])
            ->get();

        $tp = $tpRows->keyBy('team_player_id');

        $init = fn() => [
            'passing' => [], 'rushing' => [], 'receiving' => [],
            'defense' => ['tackles' => [], 'ints' => []],
        ];

        $out = [
            'HOME' => $init(),
            'AWAY' => $init(),
        ];

        $getSideForTeamPlayer = function ($teamPlayerId) use ($tp, $game) {
            $row = $tp->get($teamPlayerId);
            if (!$row) return null;
            if ((int)$row->team_id === (int)$game->home_team_id) return 'HOME';
            if ((int)$row->team_id === (int)$game->away_team_id) return 'AWAY';
            return null;
        };

        $name = function ($teamPlayerId) use ($tp) {
            $row = $tp->get($teamPlayerId);
            if (!$row) return null;
            return trim("#{$row->jersey_number} {$row->firstname} {$row->lastname}");
        };

        // PASSING / RECEIVING
        foreach ($plays as $p) {
            $type = (string)($p->type ?? '');

            // Passing attempts are PASS, INCOMPLETE, INT (not SACK)
            if (in_array($type, ['PASS','INCOMPLETE','INT', 'INTERCEPTION'], true) && $p->qb_team_player_id) {
                $side = $getSideForTeamPlayer($p->qb_team_player_id);
                if ($side) {
                    $k = $p->qb_team_player_id;
                    $out[$side]['passing'][$k]['name'] ??= $name($k);
                    $out[$side]['passing'][$k]['att']  = ($out[$side]['passing'][$k]['att'] ?? 0) + 1;
                    $out[$side]['passing'][$k]['cmp']  = ($out[$side]['passing'][$k]['cmp'] ?? 0) + ($type === 'PASS' ? 1 : 0);
                    $out[$side]['passing'][$k]['yds']  = ($out[$side]['passing'][$k]['yds'] ?? 0) + (int)($p->yards ?? 0);
                    $out[$side]['passing'][$k]['td']   = ($out[$side]['passing'][$k]['td'] ?? 0) + ((int)($p->touchdown ?? 0) === 1 ? 1 : 0);
                    $out[$side]['passing'][$k]['int']  = ($out[$side]['passing'][$k]['int'] ?? 0) + (($type === 'INT' or $type === 'INTERCEPTION') ? 1 : 0);
                }
            }

            // Receiving (count only completions)
            if ($type === 'PASS' && $p->receiver_team_player_id) {
                $side = $getSideForTeamPlayer($p->receiver_team_player_id);
                if ($side) {
                    $k = $p->receiver_team_player_id;
                    $out[$side]['receiving'][$k]['name'] ??= $name($k);
                    $out[$side]['receiving'][$k]['rec']  = ($out[$side]['receiving'][$k]['rec'] ?? 0) + 1;
                    $out[$side]['receiving'][$k]['yds']  = ($out[$side]['receiving'][$k]['yds'] ?? 0) + (int)($p->yards ?? 0);
                    $out[$side]['receiving'][$k]['td']   = ($out[$side]['receiving'][$k]['td'] ?? 0) + ((int)($p->touchdown ?? 0) === 1 ? 1 : 0);
                }
            }

            // Rushing (RUN only; sacks excluded)
            if ($type === 'RUN' && $p->ballcarrier_team_player_id) {
                $side = $getSideForTeamPlayer($p->ballcarrier_team_player_id);
                if ($side) {
                    $k = $p->ballcarrier_team_player_id;
                    $out[$side]['rushing'][$k]['name'] ??= $name($k);
                    $out[$side]['rushing'][$k]['att']  = ($out[$side]['rushing'][$k]['att'] ?? 0) + 1;
                    $out[$side]['rushing'][$k]['yds']  = ($out[$side]['rushing'][$k]['yds'] ?? 0) + (int)($p->yards ?? 0);
                    $out[$side]['rushing'][$k]['td']   = ($out[$side]['rushing'][$k]['td'] ?? 0) + ((int)($p->touchdown ?? 0) === 1 ? 1 : 0);
                }
            }

            // Defense tackles (simple count)
            if ($p->tackled_by_team_player_id) {
                $side = $getSideForTeamPlayer($p->tackled_by_team_player_id);
                if ($side) {
                    $k = $p->tackled_by_team_player_id;
                    $out[$side]['defense']['tackles'][$k]['name'] ??= $name($k);
                    $out[$side]['defense']['tackles'][$k]['tkl'] = ($out[$side]['defense']['tackles'][$k]['tkl'] ?? 0) + 1;
                }
            }

            // Defense interceptions
            if (($type === 'INT' or $type === 'INTERCEPTION') && $p->intercepted_by_team_player_id) {
                $side = $getSideForTeamPlayer($p->intercepted_by_team_player_id);
                if ($side) {
                    $k = $p->intercepted_by_team_player_id;
                    $out[$side]['defense']['ints'][$k]['name'] ??= $name($k);
                    $out[$side]['defense']['ints'][$k]['int'] = ($out[$side]['defense']['ints'][$k]['int'] ?? 0) + 1;
                }
            }
        }

        // Convert associative arrays to sorted lists
        foreach (['HOME','AWAY'] as $side) {
            $out[$side]['passing']   = collect($out[$side]['passing'])->sortByDesc('yds')->values()->all();
            $out[$side]['rushing']   = collect($out[$side]['rushing'])->sortByDesc('yds')->values()->all();
            $out[$side]['receiving'] = collect($out[$side]['receiving'])->sortByDesc('yds')->values()->all();
            $out[$side]['defense']['tackles'] = collect($out[$side]['defense']['tackles'])->sortByDesc('tkl')->values()->all();
            $out[$side]['defense']['ints']    = collect($out[$side]['defense']['ints'])->sortByDesc('int')->values()->all();
        }

        return $out;
    }
}
