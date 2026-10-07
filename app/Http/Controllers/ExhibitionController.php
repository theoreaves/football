<?php

namespace App\Http\Controllers;

use App\Models\Exhibition;
use App\Models\Team;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GameClock;
use App\Services\Simulation\RosterBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExhibitionController extends Controller
{
    public function index()
    {
        // Sort narrow rows: MySQL can otherwise copy large replay JSON into its sort buffer.
        $games = Exhibition::select('id')->latest()->orderByDesc('id')->paginate(20);
        $summaries = Exhibition::select(['id', 'world_id', 'home_team_id', 'away_team_id', 'state'])
            ->with(['homeTeam', 'awayTeam'])->whereIn('id', $games->getCollection()->pluck('id'))
            ->get()->keyBy('id');
        $games->setCollection($games->getCollection()->map(fn ($game) => $summaries->get($game->id))->filter()->values());

        return view('exhibitions.index', ['teams' => Team::orderBy('city')->get(), 'games' => $games]);
    }

    public function store(Request $request, RosterBuilder $builder, ExhibitionEngine $engine)
    {
        $data = $request->validate(['home' => ['required', 'integer'], 'away' => ['required', 'integer', 'different:home'], 'quarter_length' => ['required', Rule::in([180, 900])], 'home_control' => ['sometimes', 'required', Rule::in(['human', 'cpu'])], 'away_control' => ['sometimes', 'required', Rule::in(['human', 'cpu'])], 'penalties' => ['sometimes', 'boolean'], 'injuries' => ['sometimes', 'boolean'], 'crowd_fullness' => ['sometimes', 'required', 'integer', 'between:0,100'], 'visiting_fans' => ['sometimes', 'required', 'integer', 'between:0,100']]);
        $home = Team::findOrFail($data['home']);
        $away = Team::findOrFail($data['away']);
        $game = DB::transaction(fn () => Exhibition::create([
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'state' => array_merge($engine->initial((int) $data['quarter_length']), ['crowd' => ['fullness' => (int) ($data['crowd_fullness'] ?? 80), 'visitors' => (int) ($data['visiting_fans'] ?? 10), 'seed' => random_int(1, 2147483647)], 'rules' => ['penalties' => (bool) ($data['penalties'] ?? true), 'injuries' => (bool) ($data['injuries'] ?? true)], 'controls' => ['home' => $data['home_control'] ?? 'human', 'away' => $data['away_control'] ?? 'human']]),
            'rosters' => ['home' => $builder->build($home), 'away' => $builder->build($away)], 'history' => [],
        ]));

        return redirect()->route('exhibitions.show', $game);
    }

    public function show(Exhibition $exhibition)
    {
        $exhibition->load(['homeTeam', 'awayTeam']);
        $appearance = ['home' => app(PracticeController::class)->appearance($exhibition->homeTeam, 'home'),
            'away' => app(PracticeController::class)->appearance($exhibition->awayTeam, 'away')];
        $exhibition->state = app(GameClock::class)->normalize($exhibition->state);
        $history = $exhibition->history;
        $last = $history ? $history[array_key_last($history)] : null;
        $preview = $last ?? ['before' => $exhibition->state, 'call' => 'inside_run', 'outcome' => 'tackle', 'carrier' => 'RB', 'gain' => 0, 'target' => 0, 'summary' => 'Ready for the snap'];
        $animation = $last['animation'] ?? app(\App\Services\Simulation\PlayTimeline::class)->build($preview, $exhibition->rosters);

        $calls = ExhibitionEngine::callsForState($exhibition->state);
        $defenseOptions = array_combine(ExhibitionEngine::OFFENSE, array_map(ExhibitionEngine::defensesForCall(...), ExhibitionEngine::OFFENSE));

        $coach = app(CpuCoach::class);
        $controls = $coach->controls($exhibition->state);
        $offenseSide = $exhibition->state['possession'];
        $defenseSide = $offenseSide === 'home' ? 'away' : 'home';
        $cpuOffense = $controls[$offenseSide] === 'cpu';
        $cpuDefense = $controls[$defenseSide] === 'cpu';
        $personnel = app(\App\Services\Simulation\GamePersonnel::class)->active($exhibition->rosters, $exhibition->state);
        $cpuPlan = $cpuOffense ? $coach->offense($exhibition->state, $personnel) : null;
        $coachSuggestion = $coach->offense($exhibition->state, $personnel);
        $coachDefense = $coach->defense($exhibition->state, $coachSuggestion['call']);
        $plannedCall = $cpuPlan['call'] ?? $calls[0];
        $humanDefenseOptions = ExhibitionEngine::defensesForCall($plannedCall);

        $boxScore = app(\App\Services\Simulation\ExhibitionBoxScore::class)->build($exhibition);

        return view('exhibitions.show', compact('exhibition', 'appearance', 'animation', 'last', 'calls', 'defenseOptions', 'controls', 'offenseSide', 'defenseSide', 'cpuOffense', 'cpuDefense', 'cpuPlan', 'humanDefenseOptions', 'boxScore', 'personnel', 'coachSuggestion', 'coachDefense'));
    }

    public function play(Request $request, Exhibition $exhibition, ExhibitionEngine $engine)
    {
        $controls = app(CpuCoach::class)->controls($exhibition->state);
        $side = $exhibition->state['possession'];
        $rules = ['version' => ['required', 'integer', 'min:0']];
        $action = $request->input('action', 'play');
        $rules['action'] = ['sometimes', Rule::in(['play', 'timeout', 'penalty'])];
        if ($action === 'timeout') {
            $rules['timeout_team'] = ['required', Rule::in(['home', 'away'])];
        }
        if ($action === 'play' && $controls[$side] === 'human') {
            $rules['call'] = ['required', Rule::in(ExhibitionEngine::OFFENSE)];
            $rules['tempo'] = ['sometimes', Rule::in(['normal', 'hurry', 'drain'])];
            $rules['clock_strategy'] = ['sometimes', Rule::in(['normal', 'sideline'])];
            $rules['offense_formation'] = ['sometimes', 'required', Rule::in(array_keys(ExhibitionEngine::OFFENSE_FORMATIONS))];
        }
        if ($action === 'play' && $controls[$side === 'home' ? 'away' : 'home'] === 'human') {
            $rules['defense'] = ['required', Rule::in(ExhibitionEngine::DEFENSE)];
            $rules['defense_formation'] = ['sometimes', 'required', Rule::in(array_keys(ExhibitionEngine::DEFENSE_FORMATIONS))];
        }
        if ($action === 'penalty') {
            $rules['decision'] = ['required', Rule::in(['accept', 'decline'])];
        }
        $rules['expect'] = ['sometimes', Rule::in(['balanced', 'run', 'pass'])];
        $rules['blitz'] = ['sometimes', 'boolean'];
        $rules['motion'] = ['sometimes', Rule::in(['none', 'WR1', 'WR2', 'WR3', 'TE', 'RB'])];
        $data = $request->validate($rules);
        DB::transaction(function () use ($exhibition, $engine, $data, $request) {
            $game = Exhibition::whereKey($exhibition->id)->lockForUpdate()->firstOrFail();
            abort_if($game->state['version'] !== (int) $data['version'], 409, 'This play was already processed. Reload the game.');
            if ($request->input('action') === 'penalty') {
                $history = $game->history;
                $index = count($history) - 1;
                $last = $history[$index] ?? [];
                abort_unless($game->state['penalty_pending'] ?? false, 409, 'This penalty was already decided.');
                $beneficiary = $last['penalty']['beneficiary'];
                abort_unless(app(CpuCoach::class)->controls($game->state)[$beneficiary] === 'human', 422, 'The CPU decides this penalty.');
                $option = $last['penalty_options'][$data['decision']];
                $option['play']['penalty']['decided'] = true;
                $history[$index] = $option['play'];
                $game->update(['state' => $option['state'], 'history' => $history]);

                return;
            }
            abort_if($game->state['penalty_pending'] ?? false, 409, 'Accept or decline the penalty before continuing.');
            abort_if($game->state['status'] !== 'playing', 409, 'This game is final.');
            $coach = app(CpuCoach::class);
            $controls = $coach->controls($game->state);
            $side = $game->state['possession'];
            $other = $side === 'home' ? 'away' : 'home';
            $game->state = app(GameClock::class)->normalize($game->state);
            $timeoutTeam = $request->input('action') === 'timeout' ? $data['timeout_team'] : $coach->timeoutTeam($game->state);
            if ($timeoutTeam) {
                if ($request->input('action') === 'timeout') {
                    abort_if($controls[$timeoutTeam] !== 'human', 422, 'CPU teams manage their own timeouts.');
                }
                abort_if(! $game->state['clock_running'] || $game->state['timeouts'][$timeoutTeam] <= 0, 422, 'No timeout is available, or the clock is already stopped.');
                $result = $engine->timeout($game->state, $game->rosters, $timeoutTeam);
            } else {
                if ($controls[$side] === 'cpu') {
                    $offense = $coach->offense($game->state, app(\App\Services\Simulation\GamePersonnel::class)->active($game->rosters, $game->state));
                } else {
                    $human = $request->validate(['call' => ['required', Rule::in(ExhibitionEngine::callsForState($game->state))],
                        'offense_formation' => ['sometimes', 'required', Rule::in(array_keys(ExhibitionEngine::OFFENSE_FORMATIONS))]]);
                    $offense = ['call' => $human['call'], 'formation' => $human['offense_formation'] ?? 'shotgun'];
                }
                if ($controls[$other] === 'cpu') {
                    $defense = $coach->defense($game->state, $offense['call']);
                } else {
                    $human = $request->validate(['defense' => ['required', Rule::in(ExhibitionEngine::defensesForCall($offense['call']))],
                        'defense_formation' => ['sometimes', 'required', Rule::in(array_keys(ExhibitionEngine::DEFENSE_FORMATIONS))]]);
                    $defense = ['call' => $human['defense'], 'formation' => $human['defense_formation'] ?? 'base_4_3'];
                }
                $management = $controls[$side] === 'cpu' ? $coach->management($game->state) : ['tempo' => $data['tempo'] ?? 'normal', 'clock_strategy' => $data['clock_strategy'] ?? 'normal'];
                $result = $engine->resolve($game->state, $game->rosters, $offense['call'], $defense['call'], $offense['formation'], $defense['formation'], $management['tempo'], $management['clock_strategy'], $controls[$other] === 'human' ? ($data['expect'] ?? 'balanced') : ($game->state['distance'] >= 8 ? 'pass' : ($game->state['distance'] <= 2 ? 'run' : 'balanced')), $controls[$other] === 'human' && (bool) ($data['blitz'] ?? false), $controls[$side] === 'human' ? ($data['motion'] ?? 'none') : 'none');
            }
            $history = $game->history;
            $history[] = $result['play'];
            $game->update(['state' => $result['state'], 'history' => $history]);
        }, 3);

        return redirect()->route('exhibitions.show', ['exhibition' => $exhibition, 'watch' => $action === 'penalty' ? 0 : 1]);
    }
}
