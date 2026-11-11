<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArcadeDrillRequest;
use App\Models\Team;
use App\Services\Arcade\DrillRoster;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

class ArcadeController extends Controller
{
    public function __invoke(ArcadeDrillRequest $request, DrillRoster $roster, PracticeController $practice): View
    {
        $mode = $request->validated('mode') ?: 'running';
        $teams = Team::orderBy('city')->orderBy('name')->orderBy('id')->get();
        $home = $request->filled('home') ? $teams->firstWhere('id', $request->integer('home')) : $teams->first();
        $away = $request->filled('away') ? $teams->firstWhere('id', $request->integer('away')) : $teams->first(fn (Team $team) => $team->id !== $home?->id);
        abort_if(($request->filled('home') && ! $home) || ($request->filled('away') && ! $away), 404);
        if ($home && $away && $home->id === $away->id) {
            throw ValidationException::withMessages(['away' => 'Choose two different teams for the '.$mode.' drill.']);
        }
        $appearance = $practice->matchup($home, $away);
        $runners = $mode === 'running' ? $roster->runners($home) : [];
        $runner = $request->filled('runner') ? collect($runners)->firstWhere('id', $request->integer('runner')) : ($runners[0] ?? null);
        abort_if($mode === 'running' && $request->filled('runner') && ! $runner, 404);
        $quarterbacks = $mode === 'passing' ? $roster->quarterbacks($home) : [];
        $quarterback = $request->filled('quarterback') ? collect($quarterbacks)->firstWhere('id', $request->integer('quarterback')) : ($quarterbacks[0] ?? null);
        abort_if($mode === 'passing' && $request->filled('quarterback') && ! $quarterback, 404);
        $receivers = $mode === 'passing' ? $roster->receivers($home) : [];
        $defenders = $mode === 'passing' ? $roster->coverageDefenders($away) : $roster->defenders($away);
        $setupMessage = match (true) {
            $teams->count() < 2 => 'Add at least two teams to this world before starting the '.$mode.' drill.',
            $mode === 'passing' && ! $quarterback => 'Add a QB to the home team’s latest roster before starting the passing drill.',
            $mode === 'passing' && ! $receivers => 'Add a WR or TE to the home team’s latest roster before starting the passing drill.',
            $mode === 'running' && ! $runner => 'Add an RB or FB to the home team’s latest roster before starting the running drill.',
            ! $defenders => 'Add an LB or defensive back to the away team’s latest roster before starting the '.$mode.' drill.',
            default => null,
        };

        return view('arcade.drill', compact('mode', 'teams', 'home', 'away', 'appearance', 'runners', 'runner', 'quarterbacks', 'quarterback', 'receivers', 'defenders', 'setupMessage'));
    }
}
