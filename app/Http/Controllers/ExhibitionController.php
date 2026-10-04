<?php

namespace App\Http\Controllers;

use App\Models\Exhibition;
use App\Models\Team;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\RosterBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExhibitionController extends Controller
{
    public function index()
    {
        return view('exhibitions.index', ['teams' => Team::orderBy('city')->get(), 'games' => Exhibition::with(['homeTeam', 'awayTeam'])->latest()->get()]);
    }

    public function store(Request $request, RosterBuilder $builder, ExhibitionEngine $engine)
    {
        $data = $request->validate(['home' => ['required', 'integer'], 'away' => ['required', 'integer', 'different:home'], 'quarter_length' => ['required', Rule::in([180, 900])]]);
        $home = Team::findOrFail($data['home']);
        $away = Team::findOrFail($data['away']);
        $game = DB::transaction(fn () => Exhibition::create([
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'state' => $engine->initial((int) $data['quarter_length']),
            'rosters' => ['home' => $builder->build($home), 'away' => $builder->build($away)], 'history' => [],
        ]));

        return redirect()->route('exhibitions.show', $game);
    }

    public function show(Exhibition $exhibition)
    {
        $exhibition->load(['homeTeam', 'awayTeam']);
        $appearance = ['home' => app(PracticeController::class)->appearance($exhibition->homeTeam, 'home'),
            'away' => app(PracticeController::class)->appearance($exhibition->awayTeam, 'away')];
        $history = $exhibition->history;
        $last = $history ? $history[array_key_last($history)] : null;
        $preview = $last ?? ['before' => $exhibition->state, 'call' => 'inside_run', 'outcome' => 'tackle', 'carrier' => 'RB', 'gain' => 0, 'target' => 0, 'summary' => 'Ready for the snap'];
        $animation = $last['animation'] ?? app(\App\Services\Simulation\PlayTimeline::class)->build($preview, $exhibition->rosters);

        return view('exhibitions.show', compact('exhibition', 'appearance', 'animation', 'last'));
    }

    public function play(Request $request, Exhibition $exhibition, ExhibitionEngine $engine)
    {
        $data = $request->validate(['call' => ['required', Rule::in(ExhibitionEngine::OFFENSE)],
            'defense' => ['required', Rule::in(ExhibitionEngine::DEFENSE)],
            'offense_formation' => ['sometimes', 'required', Rule::in(array_keys(ExhibitionEngine::OFFENSE_FORMATIONS))],
            'defense_formation' => ['sometimes', 'required', Rule::in(array_keys(ExhibitionEngine::DEFENSE_FORMATIONS))], 'version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($exhibition, $engine, $data) {
            $game = Exhibition::whereKey($exhibition->id)->lockForUpdate()->firstOrFail();
            abort_if($game->state['version'] !== (int) $data['version'], 409, 'This play was already processed. Reload the game.');
            abort_if($game->state['status'] !== 'playing', 409, 'This game is final.');
            $result = $engine->resolve($game->state, $game->rosters, $data['call'], $data['defense'], $data['offense_formation'] ?? 'shotgun', $data['defense_formation'] ?? 'base_4_3');
            $history = $game->history;
            $history[] = $result['play'];
            $game->update(['state' => $result['state'], 'history' => $history]);
        }, 3);

        return redirect()->route('exhibitions.show', ['exhibition' => $exhibition, 'watch' => 1]);
    }
}
