<?php

namespace App\Http\Controllers;

use App\Models\Player;

class PlayerController extends Controller
{
    public function show(Player $player)
    {
        // Load teams and pivot data (team/year/positions/ranges)
        $player->load(['teams' => function ($q) {
            $q->orderBy('city')->orderBy('name');
        }]);

//        $player = Player::query()
//            ->with([
//                'teams', // keep what you already use
//                'seasonStats' => fn ($q) => $q->orderByDesc('season_year'),
//            ])
//            ->findOrFail($id);
//
//        return view('players.show', compact('player'));


        return view('players.show', compact('player'));
    }
}
