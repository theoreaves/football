<?php

namespace App\Http\Controllers;

use App\Models\Team;

class TeamController extends Controller
{
    public function index()
    {
        $teams = Team::orderBy('city')->orderBy('name')->get();

        return view('teams.index', compact('teams'));
    }

    public function sheet(Team $team)
    {
        // eager-load players + pivot data
        $team->load('players');

        return view('teams.sheet', compact('team'));
    }
}
