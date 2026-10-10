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
        $games = Exhibition::select('id')->whereDoesntHave('seasonFixture')->latest()->orderByDesc('id')->paginate(20);
        $summaries = Exhibition::select(['id', 'world_id', 'home_team_id', 'away_team_id', 'state'])
            ->with(['homeTeam', 'awayTeam'])->whereIn('id', $games->getCollection()->pluck('id'))
            ->get()->keyBy('id');
        $games->setCollection($games->getCollection()->map(fn ($game) => $summaries->get($game->id))->filter()->values());

        return view('exhibitions.index', ['teams' => Team::orderBy('city')->get(), 'games' => $games,
            'world' => \App\Models\World::findOrFail(app(\App\Support\CurrentWorld::class)->id)]);
    }

    public function destroy(Exhibition $exhibition)
    {
        abort_if($exhibition->seasonFixture()->exists(), 409, 'Season games cannot be deleted as exhibitions.');
        $exhibition->delete();

        return redirect()->route('exhibitions.index')->with('status', 'Exhibition deleted.');
    }

    public function store(Request $request, RosterBuilder $builder, ExhibitionEngine $engine, CpuCoach $coach)
    {
        $data = $request->validate(['home' => ['required', 'integer'], 'away' => ['required', 'integer', 'different:home'], 'quarter_length' => ['required', Rule::in([180, 300, 600, 900])], 'overtime' => ['sometimes', Rule::in(['none', 'traditional', 'modern', 'traditional_playoff', 'modern_playoff'])], 'quick_sim' => ['sometimes', 'boolean'], 'home_control' => ['sometimes', 'required', Rule::in(['human', 'cpu'])], 'away_control' => ['sometimes', 'required', Rule::in(['human', 'cpu'])], 'penalties' => ['sometimes', 'boolean'], 'injuries' => ['sometimes', 'boolean'], 'coin_call' => ['sometimes', 'required', Rule::in(['heads', 'tails'])], 'crowd_fullness' => ['sometimes', 'required', 'integer', 'between:0,100'], 'visiting_fans' => ['sometimes', 'required', 'integer', 'between:0,100']]);
        $home = Team::findOrFail($data['home']);
        $away = Team::findOrFail($data['away']);
        $coin = random_int(0, 1) === 0 ? 'heads' : 'tails';
        $coinCall = $data['coin_call'] ?? 'heads';
        $winner = $coin === $coinCall ? 'away' : 'home';
        $initial = $engine->initial((int) $data['quarter_length']);
        $controls = ['home' => $data['home_control'] ?? 'human', 'away' => $data['away_control'] ?? 'human'];
        $quickSim = (bool) ($data['quick_sim'] ?? false);
        if ($quickSim) {
            $controls = ['home' => 'cpu', 'away' => 'cpu'];
        }
        // Older clients without a coin call retain the receive default.
        $pending = $controls[$winner] === 'human' && isset($data['coin_call']);
        $choice = $controls[$winner] === 'cpu' ? $coach->coinChoice($initial) : ($pending ? null : 'receive');
        $receiver = $choice === 'kick' ? ($winner === 'home' ? 'away' : 'home') : $winner;
        $game = DB::transaction(function () use ($home, $away, $initial, $receiver, $coinCall, $coin, $winner, $choice, $pending, $data, $controls, $builder, $quickSim) {
            $game = Exhibition::create([
                'home_team_id' => $home->id, 'away_team_id' => $away->id,
                'state' => array_merge($initial, ['possession' => $receiver === 'home' ? 'away' : 'home', 'opening_receiver' => $receiver, 'coin_toss' => ['call' => $coinCall, 'result' => $coin, 'winner' => $winner, 'choice' => $choice, 'pending' => $pending], 'crowd' => ['fullness' => (int) ($data['crowd_fullness'] ?? 80), 'visitors' => (int) ($data['visiting_fans'] ?? 10), 'seed' => random_int(1, 2147483647)], 'rules' => ['overtime' => $data['overtime'] ?? 'none', 'penalties' => (bool) ($data['penalties'] ?? true), 'injuries' => (bool) ($data['injuries'] ?? true)], 'controls' => $controls]),
                'rosters' => ['home' => $builder->build($home), 'away' => $builder->build($away)], 'history' => [],
            ]);
            if ($quickSim) {
                $game->update(app(\App\Services\Simulation\QuickSimulator::class)->run($game->state, $game->rosters));
            }

            return $game;
        });

        return redirect()->route('exhibitions.show', ['exhibition' => $game, 'summary' => $quickSim ? 1 : 0]);
    }

    public function show(Exhibition $exhibition)
    {
        $exhibition->load(['homeTeam', 'awayTeam', 'seasonFixture.season']);
        $appearance = ['home' => app(PracticeController::class)->appearance($exhibition->homeTeam, 'home'),
            'away' => app(PracticeController::class)->appearance($exhibition->awayTeam, 'away')];
        $exhibition->state = app(GameClock::class)->normalize($exhibition->state);
        $history = $exhibition->history;
        $last = $history ? $history[array_key_last($history)] : null;
        $replayOnly = request()->has('replay');
        if ($replayOnly) {
            $data = request()->validate(['replay' => ['required', 'integer', 'min:1']]);
            $last = collect($history)->firstWhere('number', (int) $data['replay']);
            abort_unless($last && isset($last['animation']), 404, 'This saved replay is unavailable.');
        }
        $highlights = array_values(array_filter($history, \App\Support\PlayHighlights::saved(...)));
        $preview = $last ?? ['before' => $exhibition->state, 'call' => 'inside_run', 'outcome' => 'tackle', 'carrier' => 'RB', 'gain' => 0, 'target' => 0, 'summary' => 'Ready for the snap'];
        $animation = $last['animation'] ?? \App\Services\Simulation\FieldOrientation::animation(app(\App\Services\Simulation\PlayTimeline::class)->build($preview, $exhibition->rosters), $preview['before']);

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

        if ($replayOnly) {
            $exhibition->state = $last['after'] ?? $last['before'];
        }

        return view('exhibitions.show', compact('replayOnly', 'highlights', 'exhibition', 'appearance', 'animation', 'last', 'calls', 'defenseOptions', 'controls', 'offenseSide', 'defenseSide', 'cpuOffense', 'cpuDefense', 'cpuPlan', 'humanDefenseOptions', 'boxScore', 'personnel', 'coachSuggestion', 'coachDefense'));
    }

    public function finish(Request $request, Exhibition $exhibition)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($exhibition, $data) {
            $game = Exhibition::whereKey($exhibition->id)->lockForUpdate()->firstOrFail();
            abort_if($game->state['version'] !== (int) $data['version'], 409, 'The game changed. Reload before finishing with Quick Sim.');
            if ($game->state['status'] === 'final' && ! ($game->state['penalty_pending'] ?? false)) {
                return;
            }
            $controls = app(CpuCoach::class)->controls($game->state);
            $result = app(\App\Services\Simulation\QuickSimulator::class)->run($game->state, $game->rosters, $game->history);
            $result['state']['controls'] = $controls;
            $game->update($result);
            app(\App\Services\Seasons\SeasonGames::class)->record($game);
        }, 3);

        return redirect()->route('exhibitions.show', ['exhibition' => $exhibition, 'summary' => 1]);
    }

    public function saveHighlight(Request $request, Exhibition $exhibition)
    {
        $data = $request->validate(['number' => ['required', 'integer', 'min:1'], 'return_replay' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($exhibition, $data) {
            $game = Exhibition::whereKey($exhibition->id)->lockForUpdate()->firstOrFail();
            $history = $game->history;
            $index = array_search((int) $data['number'], array_column($history, 'number'), true);
            abort_unless($index !== false && isset($history[$index]['animation']), 404, 'This saved replay is unavailable.');
            abort_if($history[$index]['after']['penalty_pending'] ?? false, 409, 'Decide the penalty before saving this highlight.');
            $history[$index]['saved_highlight'] = true;
            $game->update(['history' => $history]);
        }, 3);

        return redirect()->route('exhibitions.show', array_filter(['exhibition' => $exhibition, 'replay' => ($data['return_replay'] ?? false) ? $data['number'] : null, 'watch' => ($data['return_replay'] ?? false) ? 1 : 0]));
    }

    public function play(Request $request, Exhibition $exhibition, ExhibitionEngine $engine)
    {
        $controls = app(CpuCoach::class)->controls($exhibition->state);
        $side = $exhibition->state['possession'];
        $rules = ['version' => ['required', 'integer', 'min:0']];
        $action = $request->input('action', 'play');
        $rules['action'] = ['sometimes', Rule::in(['play', 'timeout', 'penalty', 'coin', 'lineup', 'ot_call', 'ot_choice'])];
        if ($action === 'ot_call') {
            $rules['toss_call'] = ['required', Rule::in(['heads', 'tails'])];
        }
        if ($action === 'ot_choice') {
            $rules['choice'] = ['required', Rule::in(['kick', 'receive'])];
        }
        if ($action === 'lineup') {
            $rules['team'] = ['required', Rule::in(['home', 'away'])];
            $rules['role'] = ['required', Rule::in(array_keys(RosterBuilder::GROUPS))];
            $rules['player'] = ['nullable', 'integer', 'min:1'];
        }
        if ($action === 'coin') {
            $rules['choice'] = ['required', Rule::in(['kick', 'receive'])];
        }
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
        // Force injuries are available only to local developers, never on deployed servers.
        $forceInjuryAllowed = app()->environment('local') && (bool) config('app.debug');
        if ($forceInjuryAllowed && $action === 'play') {
            $rules['dev_force_result'] = ['sometimes', Rule::in(['none', 'touchdown', 'turnover', 'flag'])];
            $rules['dev_force_injury'] = ['sometimes', Rule::in(['none', 'minor', 'moderate', 'serious'])];
            $rules['dev_injury_role'] = ['sometimes', Rule::in(['carrier', 'QB', 'RB', 'WR1', 'WR2', 'WR3', 'TE', 'C', 'LB1', 'CB1'])];
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($exhibition, $engine, $data, $request, $forceInjuryAllowed) {
            $game = Exhibition::whereKey($exhibition->id)->lockForUpdate()->firstOrFail();
            abort_if($game->state['version'] !== (int) $data['version'], 409, 'This play was already processed. Reload the game.');
            if (in_array($request->input('action'), ['ot_call', 'ot_choice'], true)) {
                $state = $game->state;
                abort_if($state['penalty_pending'] ?? false, 409, 'Decide the penalty before the overtime toss.');
                $ot = app(\App\Services\Simulation\Overtime::class);
                abort_unless($state['status'] === 'playing' && $state['quarter'] >= 5 && $ot->pending($state), 409, 'This overtime toss was already decided.');
                $controls = app(CpuCoach::class)->controls($state);
                if ($request->input('action') === 'ot_call') {
                    abort_unless(($state['overtime']['toss']['call_pending'] ?? false) && $controls['away'] === 'human', 422, 'The visitor calls the overtime toss.');
                    $state = $ot->call($state, $data['toss_call']);
                } else {
                    abort_unless(($state['overtime']['toss']['pending'] ?? false) && $controls[$state['overtime']['toss']['winner']] === 'human', 422, 'The toss winner chooses kick or receive.');
                    $state = $ot->choose($state, $data['choice']);
                }
                $game->update(['state' => $state]);

                return;
            }
            if ($request->input('action') !== 'penalty') {
                abort_if(app(\App\Services\Simulation\Overtime::class)->pending($game->state), 409, 'Finish the overtime coin toss before continuing.');
            }
            if ($request->input('action') === 'coin') {
                $state = $game->state;
                abort_unless($state['version'] === 0 && ($state['coin_toss']['pending'] ?? false), 409, 'The coin toss was already decided.');
                $winner = $state['coin_toss']['winner'];
                abort_unless(app(CpuCoach::class)->controls($state)[$winner] === 'human', 422, 'The CPU decides its coin toss.');
                $receiver = $data['choice'] === 'receive' ? $winner : ($winner === 'home' ? 'away' : 'home');
                $state['coin_toss']['choice'] = $data['choice'];
                $state['coin_toss']['pending'] = false;
                $state['opening_receiver'] = $receiver;
                $state['possession'] = $receiver === 'home' ? 'away' : 'home';
                $game->update(['state' => $state]);

                return;
            }
            abort_if($game->state['coin_toss']['pending'] ?? false, 409, 'Choose kick or receive before continuing.');
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
                app(\App\Services\Seasons\SeasonGames::class)->record($game);

                return;
            }
            abort_if($game->state['penalty_pending'] ?? false, 409, 'Accept or decline the penalty before continuing.');
            abort_if($game->state['status'] !== 'playing', 409, 'This game is final.');
            if ($request->input('action') === 'lineup') {
                $state = $game->state;
                $side = $data['team'];
                $role = $data['role'];
                abort_unless(app(CpuCoach::class)->controls($state)[$side] === 'human', 422, 'Only human teams can change their lineup.');
                $playerId = isset($data['player']) ? (int) $data['player'] : null;
                if ($playerId) {
                    $player = collect($game->rosters[$side]['pool'] ?? [])->firstWhere('id', $playerId);
                    abort_unless($player && in_array($player['position'], RosterBuilder::GROUPS[$role], true), 422, 'Choose a player at the correct position from this game roster.');
                    $injury = $state['injuries'][$side][$playerId] ?? null;
                    abort_if($injury && ($injury['return_snap'] === null || ($state['personnel_snaps'] ?? 0) < $injury['return_snap']), 422, 'This player is injured.');
                    $preferences = $state['game_lineup'][$side] ?? [];
                    abort_if(in_array($playerId, array_diff_key($preferences, [$role => true]), true), 422, 'This player is already selected for another role. Reset that role to automatic first.');
                    $state['game_lineup'][$side][$role] = $playerId;
                } else {
                    unset($state['game_lineup'][$side][$role]);
                }
                $game->update(['state' => $state]);

                return;
            }
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
                $result = $engine->resolve($game->state, $game->rosters, $offense['call'], $defense['call'], $offense['formation'], $defense['formation'], $management['tempo'], $management['clock_strategy'], $controls[$other] === 'human' ? ($data['expect'] ?? 'balanced') : ($game->state['distance'] >= 8 ? 'pass' : ($game->state['distance'] <= 2 ? 'run' : 'balanced')), $controls[$other] === 'human' && (bool) ($data['blitz'] ?? false), $controls[$side] === 'human' ? ($data['motion'] ?? 'none') : ($offense['motion'] ?? 'none'), $forceInjuryAllowed ? ($data['dev_force_result'] ?? 'none') : 'none');
            }
            // Presentation and persistence use the same injury state as natural injuries.
            // This happens after the engine resolves the play, without altering its outcome.
            if ($forceInjuryAllowed && $request->input('action', 'play') === 'play'
                && in_array($data['dev_force_injury'] ?? 'none', ['minor', 'moderate', 'serious'], true)
                && ! ($result['play']['no_snap'] ?? false)) {
                $severity = $data['dev_force_injury'];
                $role = $data['dev_injury_role'] ?? 'carrier';
                $tracks = $result['play']['animation']['players'] ?? [];
                $candidate = collect($tracks)->first(function ($track) use ($role, $result) {
                    return $track['team'] === 'offense'
                        && $track['role'] === ($role === 'carrier' ? ($result['play']['carrier'] ?? '') : $role);
                });
                if ($candidate && ($candidate['id'] ?? 0) > 0) {
                    $injurySide = $candidate['side'];
                    $injuryId = $candidate['id'];
                    if (! isset($result['play']['before']['injuries'][$injurySide][$injuryId])) {
                        $snap = $result['state']['personnel_snaps'] ?? 1;
                        $returnSnap = $severity === 'minor' ? $snap + 5 : null;
                        $injury = [
                            'name' => $candidate['name'],
                            'type' => match ($severity) {
                                'minor' => 'Shaken up',
                                'moderate' => 'Leg injury',
                                default => 'Serious leg injury',
                            },
                            'return_snap' => $returnSnap,
                            'occurred_snap' => $snap,
                        ];
                        $result['state']['injuries'][$injurySide][$injuryId] = $injury;
                        $result['play']['after']['injuries'][$injurySide][$injuryId] = $injury;
                        $replacement = app(\App\Services\Simulation\GamePersonnel::class)
                            ->active($game->rosters, $result['state'])[$injurySide]['players'][$candidate['role']] ?? null;
                        $notice = ucfirst($injurySide).' · '.$candidate['name'].' · '.$injury['type'].' · '
                            .($returnSnap === null ? 'out for the game' : 'out for 5 snaps')
                            .($replacement ? ' · '.$replacement['name'].' comes in' : '');
                        $result['play']['personnel_notices'][] = $notice;
                    }
                }
            }
            $history = $game->history;
            $history[] = $result['play'];
            $game->update(['state' => $result['state'], 'history' => $history]);
            app(\App\Services\Seasons\SeasonGames::class)->record($game);
        }, 3);

        return redirect()->route('exhibitions.show', ['exhibition' => $exhibition, 'watch' => in_array($action, ['penalty', 'coin', 'lineup', 'ot_call', 'ot_choice'], true) ? 0 : 1]);
    }
}
