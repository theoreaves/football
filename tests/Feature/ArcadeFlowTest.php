<?php

use App\Models\ArcadeFlowReceipt;
use App\Models\Exhibition;
use App\Models\Team;
use App\Models\World;
use App\Services\Arcade\ExhibitionFlow;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\RosterBuilder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    config(['arcade.enabled' => true]);
    openFootballSave(World::create(['name' => 'Flow']));
});
function flowTestGame(array $changes = []): Exhibition
{
    app(\App\Support\CurrentWorld::class)->id = (int) session('current_world_id');
    $home = Team::create(['city' => 'Home', 'name' => 'Arcade']);
    $away = Team::create(['city' => 'Away', 'name' => 'Arcade']);
    $rosters = [];
    $id = 1;
    foreach (['home', 'away'] as $side) {
        $players = [];
        foreach (RosterBuilder::GROUPS as $role => $positions) {
            $players[$role] = ['id' => $id++, 'name' => $side.' '.$role, 'number' => $id, 'position' => $positions[0], 'depth' => $role,
                'ratings' => array_fill_keys(\App\Services\Simulation\PlayerRatings::FIELDS, 70), 'appearance' => [], 'height_inches' => 72, 'weight_pounds' => 220];
        }
        $rosters[$side] = ['players' => $players];
    }
    $state = app(ExhibitionEngine::class)->initial(180, 1234, false);
    $state['controls'] = ['home' => 'human', 'away' => 'cpu'];
    $state['rules'] = ['penalties' => false, 'injuries' => false];

    return Exhibition::create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'rosters' => $rosters, 'state' => array_replace($state, $changes), 'history' => []]);
}

function flowCommand(Exhibition $game, array $data): array
{
    $game->refresh();
    $flow = app(ExhibitionFlow::class)->status($game)['flow'];

    return $data + ['command_id' => (string) Str::uuid(), 'version' => $game->state['version'], 'flow_revision' => $flow['revision'], 'state_hash' => $flow['state_hash']];
}
function enableFlow(Exhibition $game, string $mode = 'coach'): void
{
    test()->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'preferences', 'human_side' => 'home', 'defense_mode' => $mode]))->assertOk();
}
test('flow preferences are durable exactly once and cannot switch player team', function () {
    $game = flowTestGame();
    $payload = flowCommand($game, ['action' => 'preferences', 'human_side' => 'home', 'defense_mode' => 'watch']);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk()->assertJsonPath('flow.revision', 1);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk()->assertJsonPath('replayed', true);
    $this->postJson(route('exhibitions.arcade.flow', $game), array_replace($payload, ['defense_mode' => 'fast']))->assertConflict();
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'preferences', 'human_side' => 'away', 'defense_mode' => 'fast']))->assertConflict();
    expect(ArcadeFlowReceipt::count())->toBe(1);
});
test('watch advances one authoritative play and fast stops at human offense', function () {
    $game = flowTestGame(['possession' => 'away']);
    enableFlow($game, 'watch');
    $payload = flowCommand($game, ['action' => 'advance']);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk();
    expect($game->fresh()->history)->toHaveCount(1);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk();
    expect($game->fresh()->history)->toHaveCount(1);
    if (app(ExhibitionFlow::class)->status($game->fresh())['flow']['can_fast']) {
        $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'fast']))->assertOk()->assertJsonPath('flow.can_advance', false);
    }
    expect($game->fresh()->state['controls'])->toBe(['home' => 'human', 'away' => 'cpu']);
});
test('flow cannot bypass an active interactive reservation or simulate human pass', function () {
    $game = flowTestGame();
    enableFlow($game);
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'play', 'call' => 'short_pass']))->assertUnprocessable();
    $session = $this->postJson(route('exhibitions.arcade.start', $game), ['version' => $game->state['version'], 'kind' => 'run', 'tempo' => 'normal'])->assertOk()->json('session');
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'timeout']))->assertConflict();
    $this->postJson($session['cancel_url'])->assertOk();
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'timeout']))->assertOk();
    expect($game->fresh()->history)->toHaveCount(1);
});
test('same version canonical changes invalidate flow commands', function () {
    $game = flowTestGame();
    enableFlow($game);
    $payload = flowCommand($game, ['action' => 'timeout']);
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    $state = $game->state;
    $state['test_legacy_lineup_change'] = true;
    $game->update(['state' => $state]);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertConflict();
});
test('flow spike uses existing rules and retries do not consume another down', function () {
    $game = flowTestGame();
    enableFlow($game);
    $payload = flowCommand($game, ['action' => 'spike']);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk();
    $state = $game->fresh()->state;
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk();
    expect($game->fresh()->state)->toBe($state)->and($state['down'])->toBe(2);
});

test('fast includes opponent try and kickoff then returns human offense', function () {
    $game = flowTestGame(['possession' => 'away', 'phase' => 'extra_point', 'away_score' => 6, 'spot' => 98]);
    enableFlow($game, 'fast');
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'fast']))->assertOk()->assertJsonPath('flow.phase', 'offense');
    $history = $game->fresh()->history;
    expect(count($history))->toBeGreaterThanOrEqual(2)->and($history[0]['call'])->toBe('extra_point')->and($game->fresh()->state['possession'])->toBe('home');
});
test('human coin choice is never consumed by fast and is retry safe at version zero', function () {
    $game = flowTestGame(['phase' => 'kickoff', 'coin_toss' => ['pending' => true, 'winner' => 'home', 'call' => 'heads', 'result' => 'tails', 'choice' => null]]);
    enableFlow($game, 'fast');
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'fast']))->assertConflict();
    $payload = flowCommand($game, ['action' => 'coin', 'choice' => 'receive']);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk()->assertJsonPath('state.version', 0);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk()->assertJsonPath('replayed', true);
    expect($game->fresh()->history)->toBe([]);
});
test('legacy mutation endpoints cannot bypass enabled Play ownership and receipts', function () {
    $game = flowTestGame();
    enableFlow($game);
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'action' => 'play', 'call' => 'short_pass'])->assertConflict();
    $this->post(route('exhibitions.finish', $game), ['version' => 0])->assertConflict();
    expect($game->fresh()->history)->toBe([]);
});
test('expired reservation can be replaced by a flow timeout without double accounting', function () {
    $game = flowTestGame();
    enableFlow($game);
    $this->postJson(route('exhibitions.arcade.start', $game), ['version' => 0, 'kind' => 'run', 'tempo' => 'normal'])->assertOk();
    \App\Models\ArcadePlaySession::query()->update(['expires_at' => now()->subSecond()]);
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'timeout']))->assertOk();
    expect(\App\Models\ArcadePlaySession::first()->status)->toBe('expired')->and($game->fresh()->history)->toHaveCount(1);
});

test('overtime human toss survives fast and final state rejects another play', function () {
    $game = flowTestGame(['controls' => ['home' => 'cpu', 'away' => 'human'], 'rules' => ['penalties' => false, 'injuries' => false, 'overtime' => 'modern']]);
    $state = app(\App\Services\Simulation\Overtime::class)->begin($game->state);
    $game->update(['state' => $state]);
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'preferences', 'human_side' => 'away', 'defense_mode' => 'fast']))->assertOk()->assertJsonPath('flow.required_decision', 'ot_call');
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'fast']))->assertConflict();
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'ot_call', 'toss_call' => $state['overtime']['toss']['result']]))->assertOk()->assertJsonPath('flow.required_decision', 'ot_choice');
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'ot_choice', 'choice' => 'receive']))->assertOk();
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    $state = $game->fresh()->state;
    $state['status'] = 'final';
    $game->update(['state' => $state]);
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'advance']))->assertConflict();
    $this->getJson(route('exhibitions.arcade.flow.status', $game))->assertOk()->assertJsonPath('flow.phase', 'final')->assertJsonPath('flow.can_timeout', false);
});
test('last second running clock uses authoritative no snap and quarter transition', function () {
    $game = flowTestGame(['quarter' => 2, 'clock' => 1, 'clock_running' => true, 'opening_receiver' => 'home']);
    enableFlow($game);
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'spike']))->assertOk();
    $saved = $game->fresh();
    expect($saved->state['quarter'])->toBe(3)->and($saved->history)->toHaveCount(1)->and($saved->history[0]['no_snap'])->toBeTrue();
});
test('goal to go and unavailable timeouts preserve canonical state', function () {
    $game = flowTestGame(['spot' => 97, 'distance' => 3, 'timeouts' => ['home' => 0, 'away' => 3]]);
    enableFlow($game);
    $before = $game->fresh()->state;
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'timeout']))->assertConflict();
    expect($game->fresh()->state)->toBe($before);
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'spike']))->assertOk()->assertJsonPath('state.distance', 3)->assertJsonPath('state.down', 2);
});
test('feature gate blocks status and every command without writing', function () {
    $game = flowTestGame();
    enableFlow($game);
    $payload = flowCommand($game, ['action' => 'timeout']);
    $before = $game->fresh()->state;
    config(['arcade.enabled' => false]);
    $this->getJson(route('exhibitions.arcade.flow.status', $game))->assertNotFound();
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertNotFound();
    expect($game->fresh()->state)->toBe($before)->and(ArcadeFlowReceipt::count())->toBe(1);
});

test('human penalty choices retain the recorded play and are exactly once', function (string $decision) {
    $game = flowTestGame(['rules' => ['penalties' => true, 'injuries' => false]]);
    // Build a deterministic adjudicated fixture through the same finalizer used by live submissions.
    $before = $game->state;
    $after = $before;
    $after['spot'] += 2;
    $after['down'] = 2;
    $after['distance'] = 8;
    $recording = ['format' => 'test-recording', 'frames' => [[0, 1, 2]]];
    $play = ['source' => 'arcade', 'arcade_recording' => $recording, 'call' => 'inside_run', 'outcome' => 'tackle', 'gain' => 2, 'flip' => false, 'summary' => 'Two yard run', 'carrier' => 'RB', 'target' => 0, 'defense' => 'man_to_man', 'offense_formation' => 'shotgun', 'defense_formation' => 'base_4_3', 'dev_force_result' => 'defensive_offside'];
    $result = app(\App\Services\Simulation\PlayFinalizer::class)->finish($after, $before, $play, $game->rosters, 5, ['events' => [[0, 'Snap'], [5, 'Tackle']], 'players' => [], 'ball' => []]);
    $game->update(['state' => $result['state'], 'history' => [$result['play']]]);
    enableFlow($game);
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'fast']))->assertConflict();
    $payload = flowCommand($game, ['action' => 'penalty', 'decision' => $decision]);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk();
    $saved = $game->fresh();
    expect($saved->history)->toHaveCount(1)->and($saved->history[0]['arcade_recording'])->toBe($recording)->and($saved->history[0]['penalty']['accepted'])->toBe($decision === 'accept');
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk()->assertJsonPath('replayed', true);
    expect($game->fresh()->state)->toBe($saved->state);
})->with(['accept', 'decline']);

test('new Play exhibition owns one team and enables opening without changing Coach creation', function () {
    $worldId = (int) session('current_world_id');
    $this->artisan('world:seed-demo', ['world' => $worldId, '--teams' => 2])->assertSuccessful();
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'play_mode' => 1, 'play_side' => 'away', 'defense_mode' => 'watch'])->assertRedirect();
    $created = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($created->state['controls'])->toBe(['away' => 'human', 'home' => 'cpu'])->and($created->arcadeFlow->human_side)->toBe('away')->and($created->arcadeFlow->opening_enabled)->toBeTrue()->and($created->arcadeFlow->intro_seen)->toBeFalse();
});

test('plain saved game URL resumes persisted Play controls', function () {
    $game = flowTestGame();
    enableFlow($game);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertViewHas('arcadeFlow', fn ($flow) => $flow['enabled'] && $flow['human_side'] === 'home')->assertSee('data-arcade-flow', false);
});
test('adoption does not strand a pending choice owned by the other team', function () {
    $game = flowTestGame(['controls' => ['home' => 'human', 'away' => 'human'], 'coin_toss' => ['pending' => true, 'winner' => 'away', 'choice' => null, 'call' => 'heads', 'result' => 'heads']]);
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'preferences', 'human_side' => 'home', 'defense_mode' => 'fast']))->assertConflict();
    expect($game->fresh()->arcadeFlow)->toBeNull()->and($game->fresh()->state['controls']['away'])->toBe('human');
});

test('shared Coach form automatic defaults do not block human defense', function () {
    $game = flowTestGame(['possession' => 'away']);
    $rosters = $game->rosters;
    foreach (['home', 'away'] as $side) {
        $rosters[$side]['pool'] = array_values($rosters[$side]['players']);
    }
    $game->update(['rosters' => $rosters]);
    enableFlow($game);
    $id = $game->rosters['home']['players']['CB1']['id'];
    $payload = flowCommand($game, ['action' => 'play', 'defense' => 'man_to_man', 'defense_formation' => 'base_4_3',
        'team_play' => ['home' => '', 'away' => ''], 'one_play' => ['home' => ['CB1' => $id], 'away' => ['QB' => null, 'RB' => '']]]);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk();
    $saved = $game->fresh();
    expect($saved->history)->toHaveCount(1)->and($saved->history[0]['before']['_snap_lineup']['home']['CB1'])->toBe($id);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk()->assertJsonPath('replayed', true);
    expect($game->fresh()->state)->toBe($saved->state);
});

test('shared form defaults never authorize actual opponent personnel choices', function (array $selection) {
    $game = flowTestGame(['possession' => 'away']);
    enableFlow($game);
    $before = $game->fresh()->state;
    $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'play', 'defense' => 'man_to_man'] + $selection))->assertUnprocessable();
    expect($game->fresh()->state)->toBe($before)->and($game->fresh()->history)->toBe([]);
})->with([
    [['team_play' => ['home' => null, 'away' => '11111111-1111-4111-8111-111111111111']]],
    [['one_play' => ['away' => ['QB' => 32]]]],
    [['one_play' => ['away' => ['not_a_role' => null]]]],
    [['team_play' => ['unrecognized' => null]]],
]);

test('successful opening coin choice durably marks intro seen without an extra football action', function () {
    $game = flowTestGame(['phase' => 'kickoff', 'coin_toss' => ['pending' => true, 'winner' => 'home', 'call' => 'heads', 'result' => 'tails', 'choice' => null]]);
    enableFlow($game);
    $game->arcadeFlow()->update(['opening_enabled' => true, 'intro_seen' => false]);
    $before = $game->fresh()->state;
    $payload = flowCommand($game, ['action' => 'coin', 'choice' => 'receive']);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk()->assertJsonPath('flow.intro_seen', true);
    $after = $game->fresh()->state;
    expect($after['version'])->toBe($before['version'])->and($after['seed'])->toBe($before['seed'])->and($after['clock'])->toBe($before['clock'])->and($game->fresh()->history)->toBe([]);
    $this->postJson(route('exhibitions.arcade.flow', $game), $payload)->assertOk()->assertJsonPath('flow.intro_seen', true);
});

test('fast navigation retains intermediate quarter transitions once without resimulating', function () {
    $game = flowTestGame(['possession' => 'away', 'quarter' => 3, 'clock' => 1, 'clock_running' => true]);
    enableFlow($game, 'fast');
    $response = $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'fast']))->assertOk();
    expect($response->json('state.quarter'))->toBe(4);
    $saved = $game->fresh();
    $history = $saved->history;
    $state = $saved->state;
    $page = $this->get($response->json('navigation_url'))->assertOk();
    $flow = $page->viewData('flow') ?? $page->viewData('arcadeFlow');
    expect(collect($flow['notices'])->contains(fn ($notice) => $notice['message'] === 'Quarter 3 → 4.'))->toBeTrue();
    $again = $this->get($response->json('navigation_url'))->assertOk();
    $flow = $again->viewData('flow') ?? $again->viewData('arcadeFlow');
    expect(collect($flow['notices'])->contains(fn ($notice) => $notice['message'] === 'Quarter 3 → 4.'))->toBeFalse()->and($game->fresh()->history)->toBe($history)->and($game->fresh()->state)->toBe($state);
});

test('fast transition handoff never leaks into another exhibition', function () {
    $game = flowTestGame(['possession' => 'away', 'quarter' => 3, 'clock' => 1, 'clock_running' => true]);
    enableFlow($game, 'fast');
    $response = $this->postJson(route('exhibitions.arcade.flow', $game), flowCommand($game, ['action' => 'fast']))->assertOk();
    $other = flowTestGame();
    enableFlow($other);
    $this->get(route('exhibitions.arcade.show', $other))->assertOk()->assertViewHas('flow', fn ($flow) => ! collect($flow['notices'])->contains(fn ($notice) => $notice['message'] === 'Quarter 3 → 4.'));
    $this->get($response->json('navigation_url'))->assertOk()->assertSee('Quarter 3');
});

test('notice handoff retains exact stoppage and scoring summaries but ignores obsolete retries', function () {
    $game = flowTestGame();
    enableFlow($game);
    $request = \Illuminate\Http\Request::create('/', 'POST', ['action' => 'fast']);
    $request->setLaravelSession(app('session')->driver());
    $before = $game->state;
    $scored = array_replace($before, ['home_score' => 6]);
    $plays = [['before' => $before, 'after' => $before, 'no_snap' => true, 'summary' => 'CPU timeout canonical summary'], ['before' => $before, 'after' => $scored, 'summary' => 'Recorded touchdown canonical summary']];
    $service = app(\App\Services\Arcade\FlowNotices::class);
    $result = ['state' => $before, 'receipt' => ['version' => $before['version']], 'plays' => $plays];
    $service->remember($request, $game, $result);
    $flow = $service->consume($request, $game, ['notices' => []]);
    expect(array_column($flow['notices'], 'message'))->toBe(['CPU timeout canonical summary', 'Recorded touchdown canonical summary']);
    $result['receipt']['version'] = -1;
    $service->remember($request, $game, $result);
    expect($service->consume($request, $game, ['notices' => []])['notices'])->toBe([]);
});
