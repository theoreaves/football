<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Team;
use App\Support\Football\RatingsFromSeasonStat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamPlayerRatingsController extends Controller
{
    public function fromSeason(
        Request $request,
        Team $team,
        Player $player,
        int $seasonYear,
        RatingsFromSeasonStat $generator
    ): RedirectResponse
    {
        $year = (string) $request->query('year', $seasonYear);

        $stat = DB::table('player_season_stats')
            ->where('player_id', $player->id)
            ->where('season_year', $seasonYear)
            ->first();

        if (!$stat) {
            return back()->with('status', "No stats found for {$player->firstname} {$player->lastname} in {$seasonYear}.");
        }

        $ratings = $generator->build($stat, strtoupper(trim($player->position ?? '')));

        $player->fill($ratings);
        $player->save();

        return redirect()
            ->route('teams.editor.teams.players.edit', [$team, $player, 'year' => $year])
            ->with('status', "Generated ratings from {$seasonYear} season stats.");
    }

}
