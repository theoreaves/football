<?php

// app/Http/Controllers/GameSetupController.php
namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Team;
use Illuminate\Http\Request;

class GameSetupController extends Controller
{
    public function index()
    {
        $games = Game::all();
        return view('games.index', compact('games'));
    }
    public function create()
    {
        $teams = Team::orderBy('city')->orderBy('name')->get();
        return view('games.create', compact('teams'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'home_team_id' => ['required','exists:teams,id'],
            'away_team_id' => ['required','exists:teams,id','different:home_team_id'],
            'home_team_white' => 'nullable'
        ]);

        $fieldUrl = Team::find($data['home_team_id'])->game_field_image ?? null;

        $game = Game::create([
            'home_team_id' => $data['home_team_id'],
            'away_team_id' => $data['away_team_id'],

            // safe defaults (keeps your kickoff-selection flow)
            'quarter' => 1,
            'clock'   => 15 * 60,
            'home_q'  => [0,0,0,0,0],
            'away_q'  => [0,0,0,0,0],
            'phase'   => 'KICKOFF',
            'kick_team' => null,
            'first_kick_team' => 'HOME',
            'field_bg_url' => $fieldUrl,
            'home_team_white' => $data['home_team_white'] ?? false,
        ]);

        return redirect()->route('games.show', $game->id);
    }
}
