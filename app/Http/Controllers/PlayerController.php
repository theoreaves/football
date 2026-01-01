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

        return view('players.show', compact('player'));
    }
}
