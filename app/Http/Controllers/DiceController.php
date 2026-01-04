<?php

namespace App\Http\Controllers;

use App\Models\DefensePlay;
use App\Models\Game;
use App\Models\OffensePlay;
use App\Services\GamePlayEngine;
use Illuminate\Http\Request;

class DiceController extends Controller
{
    public function index(Game $game)
    {
        $diceOnly = false;

        $possessionTeam = $game->possessionTeam();
        $offensePlays = OffensePlay::where('code', '!=', 'PRESSURE')->get();
        $defensePlays = DefensePlay::all();

        return view('dice.index', [
            'game' => $game,
            'possessionTeam' => $possessionTeam,
            'offensePlays' => $offensePlays,
            'defensePlays' => $defensePlays,
            'diceOnly' => $diceOnly,
        ]);
    }

    public function resolve(Request $request, Game $game, GamePlayEngine $engine)
    {
        $data = $request->validate([
            'offense_play_id' => ['required', 'integer', 'exists:offense_plays,id'],
            'defense_play_id' => ['required', 'integer', 'exists:defense_plays,id'],

            // result roll pieces
            'red'   => ['required', 'integer', 'min:1', 'max:6'],
            'white' => ['required', 'integer', 'min:0', 'max:9'], // tens digit

            // rolls (recommend 1..10, see Blade section below)
            'blue'   => ['required', 'integer', 'min:1', 'max:10'],
            'green'  => ['required', 'integer', 'min:1', 'max:20'],
            'orange' => ['required', 'integer', 'min:1', 'max:20'],
            'purple' => ['required', 'integer', 'min:1', 'max:10'],

            // optional flags if you want to pass them; otherwise derive from game state
            'redzone' => ['sometimes', 'boolean'],
        ]);

        $offensePlay = OffensePlay::find($data['offense_play_id']);
        $defensePlay = DefensePlay::find($data['defense_play_id']);

        // Derive offense/defense team from game possession
        $offenseTeam = $game->possessionTeam();

        // You need a consistent way to determine defense team.
        // If you have homeTeam/awayTeam relations like shown in your Blade:
        $defenseTeam = ($offenseTeam->id === $game->home_team_id)
            ? $game->awayTeam
            : $game->homeTeam;

        $resultRoll = (int) ((string)$data['red'] . (string)$data['white']); // e.g. 5 + 7 => "57"
        $skillRoll  = (int) $data['blue'];

        $redzone = (bool)($data['redzone'] ?? false);

        $offenseIsHome = ($offenseTeam->id === $game->home_team_id);

        $resolved = $engine->resolvePlayResult(
            offenseCode: $offensePlay->code,
            defenseCode: $defensePlay->code,
            resultRoll: $resultRoll,
            skillRoll: $skillRoll,
            offenseTeamId: $offenseTeam->id,
            defenseTeamId: $defenseTeam->id,
            playerDie: (int)$data['green'],
            tacklerDie: (int)$data['purple'],
            disrupterDie: (int)$data['orange'],
            redzone: $redzone,
            offenseIsHome: $offenseIsHome
        );

        return response()->json([
            'ok' => true,
            'inputs' => [
                'offense_code' => $offensePlay->code,
                'defense_code' => $defensePlay->code,
                'result_roll' => $resultRoll,
                'skill_roll' => $skillRoll,
                'offense_team_id' => $offenseTeam->id,
                'defense_team_id' => $defenseTeam->id,
            ],
            'resolved' => $resolved,
        ]);
    }
}
