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
        $teams = Team::orderBy('city')->orderBy('name')->orderBy('id')->get();
        $home = $request->filled('home') ? $teams->firstWhere('id', $request->integer('home')) : $teams->first();
        $away = $request->filled('away') ? $teams->firstWhere('id', $request->integer('away')) : $teams->first(fn (Team $team) => $team->id !== $home?->id);
        abort_if(($request->filled('home') && ! $home) || ($request->filled('away') && ! $away), 404);
        if ($home && $away && $home->id === $away->id) {
            throw ValidationException::withMessages(['away' => 'Choose two different teams for the running drill.']);
        }
        $appearance = $practice->matchup($home, $away);
        $runners = $roster->runners($home);
        $runner = $request->filled('runner') ? collect($runners)->firstWhere('id', $request->integer('runner')) : ($runners[0] ?? null);
        abort_if($request->filled('runner') && ! $runner, 404);
        $defenders = $roster->defenders($away);
        $setupMessage = match (true) {
            $teams->count() < 2 => 'Add at least two teams to this world before starting the running drill.',
            ! $runner => 'Add an RB or FB to the home team’s latest roster before starting the running drill.',
            ! $defenders => 'Add an LB or defensive back to the away team’s latest roster before starting the running drill.',
            default => null,
        };

        return view('arcade.drill', compact('teams', 'home', 'away', 'appearance', 'runners', 'runner', 'defenders', 'setupMessage'));
    }
}
