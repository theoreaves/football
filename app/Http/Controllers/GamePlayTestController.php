<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GamePlayEngine;
use App\Models\Team;

class GamePlayTestController extends Controller
{
    public function showForm(): \Illuminate\View\View
    {
        $teams = Team::all(['id', 'name']);
        return view('gameplay.test', [
            'teams' => $teams,
            'result' => null,
        ]);
    }

    public function submitForm(Request $request, GamePlayEngine $engine): \Illuminate\View\View
    {
        $data = $request->validate([
            'offense_code' => 'required|string',
            'defense_code' => 'required|string',
            'result_roll' => 'required|integer',
            'skill_roll' => 'required|integer',
            'offense_team_id' => 'required|integer|exists:teams,id',
            'defense_team_id' => 'required|integer|exists:teams,id',
            'player_die' => 'nullable|integer',
            'tackler_die' => 'nullable|integer',
            'disrupter_die' => 'nullable|integer',
            'redzone' => 'nullable|boolean',
            'offense_is_home' => 'nullable|boolean',
        ]);

        $result = $engine->resolvePlayResult(
            $data['offense_code'],
            $data['defense_code'],
            $data['result_roll'],
            $data['skill_roll'],
            $data['offense_team_id'],
            $data['defense_team_id'],
            $data['player_die'] ?? 0,
            $data['tackler_die'] ?? 0,
            $data['disrupter_die'] ?? 0,
            (bool)($data['redzone'] ?? false),
            (bool)($data['offense_is_home'] ?? false)
        );

        $teams = Team::all(['id', 'name']);
        return view('gameplay.test', [
            'teams' => $teams,
            'result' => $result,
            'input' => $data,
        ]);
    }
}
