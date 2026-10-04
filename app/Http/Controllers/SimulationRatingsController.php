<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Simulation\PlayerRatings;
use Illuminate\Http\Request;

class SimulationRatingsController extends Controller
{
    public function edit(Team $team, PlayerRatings $ratings)
    {
        $year = $team->players()->max('team_players.team_year');
        $players = $team->players()->wherePivot('team_year', $year)->orderBy('players.position')->get();

        return view('teams.simulation-ratings', compact('team', 'players', 'ratings', 'year'));
    }

    public function update(Request $request, Team $team)
    {
        $rules = ['ratings' => ['required', 'array']];
        foreach (PlayerRatings::FIELDS as $field) {
            $rules['ratings.*.'.$field] = ['required', 'integer', 'between:1,99'];
        }
        $data = $request->validate($rules);
        $year = $team->players()->max('team_players.team_year');
        $players = $team->players()->wherePivot('team_year', $year)->get()->keyBy('id');
        foreach (array_keys($data['ratings']) as $id) {
            abort_unless($players->has($id), 404);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $players) {
            foreach ($data['ratings'] as $id => $values) {
                $players[$id]->update(['simulation_ratings' => array_intersect_key($values, array_flip(PlayerRatings::FIELDS))]);
            }
        });

        return back()->with('status', 'Ratings saved. Start a new exhibition to use the updated roster.');
    }
}
