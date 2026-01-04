<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameLookupController extends Controller
{
    public function lookup(Game $game, Request $request)
    {
        $num   = (int) $request->query('number');
        $side  = strtoupper((string) $request->query('side', 'HOME')); // HOME or AWAY

        // however you store these on games:
        $teamId = $side === 'AWAY' ? $game->away_team_id : $game->home_team_id;
        $year   = $game->season_year ?? $game->year ?? '2025'; // adjust to your actual column

        $row = DB::table('team_players')
            ->join('players', 'players.id', '=', 'team_players.player_id')
            ->where('team_players.team_id', $teamId)
            ->where('team_players.team_year', $year)
            ->where('team_players.jersey_number', $num)
            ->select([
                'team_players.id as team_player_id',
                'team_players.jersey_number',
                'team_players.position',
                'team_players.depth_chart_position',
                'players.firstname',
                'players.lastname',
            ])
            ->first();

        return response()->json([
            'found' => (bool) $row,
            'player' => $row,
        ]);
    }
}
