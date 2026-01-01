<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamSheetController extends Controller
{
    public function show(Request $request, Team $team, ?string $year = null)
    {
        // Pick year:
        // - If provided, use it
        // - Else use most recent year in pivot for this team
        $year = $year ?: DB::table('team_players')
            ->where('team_id', $team->id)
            ->max('team_year');

        if (! $year) {
            abort(404, 'No roster found for this team.');
        }

        $rows = DB::table('team_players')
            ->join('players', 'players.id', '=', 'team_players.player_id')
            ->where('team_players.team_id', $team->id)
            ->where('team_players.team_year', $year)
            ->select([
                'team_players.*',
                'players.firstname',
                'players.lastname',
                'players.age',
                'players.position as player_position',
            ])
            ->get();

        // Helpers for display
        $fmtRange = function ($from, $to) {
            $from = (int) $from;
            $to   = (int) $to;
            if ($from <= 0 || $to <= 0) return '';
            return $from === $to ? (string)$from : "{$from}-{$to}";
        };

        // Group into sections like the Chiefs PDF style:
        $offense = $rows->filter(fn($r) => in_array(strtoupper($r->position), ['QB','RB','WR','TE','FB'], true));
        $defense = $rows->filter(fn($r) => in_array(strtoupper($r->position), ['DL','LB','CB','S','DB'], true));
        $special = $rows->filter(fn($r) => in_array(strtoupper($r->position), ['K','P'], true));

        // Returns are sometimes duplicated players; show based on KR/PR depth fields
        $returns = $rows->filter(fn($r) => ($r->kick_return_depth_chart_position ?? '') !== '' || ($r->punt_return_depth_chart_position ?? '') !== '');

        // Sort order (simple but effective)
        $sortDepth = function ($a, $b) {
            return strcmp((string)$a->depth_chart_position, (string)$b->depth_chart_position);
        };

        $offense = $offense->sort($sortDepth)->values();
        $defense = $defense->sort($sortDepth)->values();
        $special = $special->sort($sortDepth)->values();
        $returns = $returns->values();

        return view('teams.sheet', compact(
            'team', 'year', 'offense', 'defense', 'special', 'returns', 'fmtRange'
        ));
    }
}
