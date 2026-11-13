<?php

use App\Models\ArcadePlaySession;
use App\Models\Exhibition;
use App\Models\Team;
use App\Models\User;
use App\Models\World;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\RosterBuilder;

beforeEach(function () {
    $this->withoutVite();
    config(['arcade.enabled' => true]);
    openFootballSave(World::create(['name' => 'Persistent Arcade']));
});

function persistentArcadeGame(array $changes = []): Exhibition
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

function reserveArcade(Exhibition $game, array $changes = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson(route('exhibitions.arcade.start', $game), array_replace(['version' => $game->state['version'], 'kind' => 'run', 'tempo' => 'normal'], $changes));
}

test('persistent arcade gates all endpoints and validates owner on every request', function () {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    config(['arcade.enabled' => false]);
    $this->get(route('exhibitions.arcade.show', $game))->assertNotFound();
    reserveArcade($game)->assertNotFound();
    $this->postJson($session['submit_url'], ['envelope' => [], 'recording' => []])->assertNotFound();
    $this->postJson($session['cancel_url'])->assertNotFound();
    config(['arcade.enabled' => true]);
    World::whereKey($game->world_id)->update(['owner_user_id' => User::factory()->create()->id]);
    $this->postJson($session['cancel_url'])->assertRedirect(route('worlds.index'));
});

test('reservation is persistent and retryable without consuming clock or history', function () {
    $game = persistentArcadeGame(['clock_running' => true]);
    $before = $game->state;
    $one = reserveArcade($game)->assertOk()->json('session');
    $two = reserveArcade($game)->assertOk()->json('session');
    expect($one)->toBe($two)->and(ArcadePlaySession::count())->toBe(1)
        ->and($game->fresh()->state)->toBe($before)->and($game->fresh()->history)->toBe([]);
    reserveArcade($game, ['kind' => 'pass'])->assertConflict();
    $this->postJson($one['cancel_url'])->assertOk();
    $this->postJson($one['cancel_url'])->assertOk();
    $next = reserveArcade($game)->assertOk()->json('session');
    expect($next['envelope']['session_id'])->not->toBe($one['envelope']['session_id'])
        ->and($game->fresh()->state)->toBe($before);
});

test('expired reservation releases the database slot without charging runoff twice', function () {
    $game = persistentArcadeGame(['clock_running' => true]);
    $one = reserveArcade($game)->assertOk()->json('session');
    $snapClock = ArcadePlaySession::first()->snapshot['state']['clock'];
    $this->travel(181)->seconds();
    $two = reserveArcade($game)->assertOk()->json('session');
    expect($two['envelope']['session_id'])->not->toBe($one['envelope']['session_id'])
        ->and(ArcadePlaySession::where('status', 'active')->count())->toBe(1)
        ->and(ArcadePlaySession::where('status', 'active')->first()->snapshot['state']['clock'])->toBe($snapClock)
        ->and($game->fresh()->state['clock'])->toBe(180);
});

test('same version coach mutations invalidate the entire reservation snapshot', function () {
    $game = persistentArcadeGame();
    $one = reserveArcade($game)->assertOk()->json('session');
    $state = $game->state;
    $state['game_lineup'] = ['home' => ['RB' => 999]];
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    $game->update(['state' => $state]);
    $this->postJson($one['submit_url'], ['envelope' => $one['envelope'], 'recording' => ['schemaVersion' => 1]])->assertConflict();
    expect(ArcadePlaySession::first()->status)->toBe('stale')->and(ArcadePlaySession::first()->active_slot)->toBeNull();
});

test('arcade rejects invalid phases decisions control versions and oversized recordings', function () {
    $game = persistentArcadeGame();
    reserveArcade($game, ['version' => 9])->assertConflict();
    foreach ([['phase' => 'kickoff'], ['penalty_pending' => true], ['coin_toss' => ['pending' => true]], ['controls' => ['home' => 'cpu', 'away' => 'cpu']], ['playoff' => ['season_id' => 1]]] as $change) {
        $changed = persistentArcadeGame($change);
        reserveArcade($changed)->assertConflict();
    }
    $session = reserveArcade($game)->assertOk()->json('session');
    config(['arcade.max_request_bytes' => 100]);
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => ['padding' => str_repeat('x', 101)]])->assertStatus(413);
});

test('presnap no snap results are persisted once and cannot be reserved again with the old version', function () {
    $game = persistentArcadeGame(['quarter' => 2, 'clock' => 125, 'clock_running' => true]);
    $response = reserveArcade($game)->assertOk()->assertJsonPath('session', null);
    expect($response->json('play.no_snap'))->toBeTrue()->and($game->fresh()->history)->toHaveCount(1)
        ->and($game->fresh()->state['version'])->toBe(1)->and(ArcadePlaySession::count())->toBe(0);
    reserveArcade($game)->assertConflict();
});

function liveArcadeRecording(array $session): array
{
    $script = <<<'JS'
import {createDrill,startDrill,stepDrill} from './resources/js/arcade/simulation.js';
import {createDrillRecording} from './resources/js/arcade/recording.js';
import {createPassingDrill,stepPassingDrill} from './resources/js/arcade/passing.js';
let input=''; for await (const chunk of process.stdin) input+=chunk;
const setup=JSON.parse(input), state=setup.kind==='pass'?createPassingDrill(setup):createDrill(setup), recorder=createDrillRecording(state);
startDrill(state); recorder.capture(state);
for(let i=0;i<1200 && !state.result;i++){
if(setup.kind==='pass')stepPassingDrill(state,i===20?{pass:{x:state.startX+setup.direction*6,z:22}}:{x:state.controlledKey==='qb'?0:setup.direction});
else stepDrill(state,{x:setup.direction,z:setup.exhibition.startX>=100 ? -0.6 : 0});
recorder.capture(state);}
process.stdout.write(JSON.stringify(recorder.export()));
JS;
    $process = new \Symfony\Component\Process\Process(['node', '--input-type=module', '-e', $script], base_path());
    $process->setInput(json_encode($session['setup'], JSON_THROW_ON_ERROR));
    $process->mustRun();

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

test('live generated movement commits once and identical receipt retries survive progression', function () {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    $recording = liveArcadeRecording($session);
    $data = ['envelope' => $session['envelope'], 'recording' => $recording];
    $first = $this->postJson($session['submit_url'], $data)->assertOk();
    $saved = $game->fresh();
    expect($saved->state['version'])->toBe(1)->and($saved->history)->toHaveCount(1)
        ->and($saved->state['stats']['home']['plays'])->toBe(1)
        ->and($saved->history[0]['arcade_recording']['frames'])->toBe($recording['frames'])
        ->and(ArcadePlaySession::first()->status)->toBe('accepted');
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    $state = $saved->state;
    $state['version']++;
    $state['phase'] = 'extra_point';
    $saved->update(['state' => $state]);
    $this->travel(181)->seconds();
    $receiptJson = ArcadePlaySession::first()->getRawOriginal('receipt');
    $receiptCasts = 0;
    \Illuminate\Database\Eloquent\Casts\Json::decodeUsing(function ($value, $associative = true) use ($receiptJson, &$receiptCasts) {
        if ($value === $receiptJson) {
            $receiptCasts++;
        }

        return json_decode($value, $associative);
    });
    try {
        $this->postJson($session['submit_url'], $data)->assertOk()->assertJsonPath('receipt', $first->json('receipt'));
    } finally {
        \Illuminate\Database\Eloquent\Casts\Json::decodeUsing(null);
    }
    expect($receiptCasts)->toBe(1);
    expect($game->fresh()->history)->toHaveCount(1)->and($game->fresh()->state['version'])->toBe(2);
    $changed = $data;
    $changed['recording']['result']['yards'] = 999;
    $this->postJson($session['submit_url'], $changed)->assertConflict();
    $this->postJson($session['cancel_url'])->assertOk()->assertJsonPath('session_status', 'accepted');
});

test('rejected evidence is atomic and may be corrected while active', function () {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    $recording = liveArcadeRecording($session);
    $bad = $recording;
    $bad['frames'][0][3][0][0] += 1000;
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $bad])->assertUnprocessable();
    expect($game->fresh()->state['version'])->toBe(0)->and($game->fresh()->history)->toBe([])->and(ArcadePlaySession::first()->status)->toBe('active');
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $recording])->assertOk();
});

test('arcade page recovers pending reservations and renders actual accepted replay', function () {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    $this->get(route('exhibitions.arcade.show', $game))->assertOk()->assertViewHas('pendingSession', $session);
    $recording = liveArcadeRecording($session);
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $recording])->assertOk();
    $this->get(route('exhibitions.arcade.show', $game))->assertOk()->assertViewHas('pendingSession', null)
        ->assertViewHas('lastRecording', fn ($value) => $value['frames'] === $recording['frames']);
});

test('session identifiers cannot be used for another game or another owner', function () {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    $other = persistentArcadeGame();
    $this->postJson(route('exhibitions.arcade.cancel', ['exhibition' => $other, 'session' => $session['envelope']['session_id']]))->assertNotFound();
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    World::whereKey($game->world_id)->update(['owner_user_id' => User::factory()->create()->id]);
    try {
        app(\App\Services\Arcade\ExhibitionSessions::class)->cancel($game, auth()->id(), $session['envelope']['session_id']);
        test()->fail('Ownership loss must reject the request.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    expect(ArcadePlaySession::first()->status)->toBe('active');
});

test('expired and cancelled submissions never advance official state', function () {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    $recording = liveArcadeRecording($session);
    $this->travel(180)->seconds();
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $recording])->assertGone();
    expect(ArcadePlaySession::first()->active_slot)->toBeNull();
    $next = reserveArcade($game)->assertOk()->json('session');
    $this->postJson($next['cancel_url'])->assertOk();
    $this->postJson($next['submit_url'], ['envelope' => $next['envelope'], 'recording' => $recording])->assertConflict();
    expect($game->fresh()->state['version'])->toBe(0)->and($game->fresh()->history)->toBe([]);
});

test('arcade touchdown and turnover on downs stop continuation without resolving a second play', function (array $state, string $phase) {
    $game = persistentArcadeGame($state);
    $session = reserveArcade($game)->assertOk()->json('session');
    $recording = liveArcadeRecording($session);
    $response = $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $recording])->assertOk()->assertJsonPath('available', false);
    expect($game->fresh()->history)->toHaveCount(1)->and($game->fresh()->state['phase'])->toBe($phase);
    if ($phase === 'extra_point') {
        expect($game->fresh()->state['home_score'])->toBe(6)->and($response->json('play.outcome'))->toBe('touchdown');
    } else {
        expect($game->fresh()->state['possession'])->toBe('away');
    }
})->with([
    'touchdown' => [['spot' => 90, 'distance' => 10, 'seed' => 1], 'extra_point'],
    'downs' => [['down' => 4, 'distance' => 75], 'scrimmage'],
]);

test('real passing movement commits with actual participant identities in both directions', function (int $quarter) {
    $game = persistentArcadeGame(['quarter' => $quarter]);
    $session = reserveArcade($game, ['kind' => 'pass'])->assertOk()->json('session');
    $recording = liveArcadeRecording($session);
    $response = $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $recording])->assertOk();
    expect($response->json('play.source'))->toBe('arcade')->and($response->json('play.call'))->toBe('short_pass')
        ->and($response->json('play.arcade_participants.passer.player_id'))->toBe($session['setup']['quarterback']['id'])
        ->and($game->fresh()->history)->toHaveCount(1)->and($game->fresh()->history[0]['arcade_recording']['frames'])->toBe($recording['frames']);
})->with([1, 2]);

test('season fixtures and queued games are excluded from all persistent arcade operations', function (?string $queue) {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    $league = \App\Models\League::create(['name' => 'League']);
    $season = \App\Models\Season::create(['league_id' => $league->id, 'year' => 2030]);
    \App\Models\SeasonFixture::create(['season_id' => $season->id, 'week' => 1, 'home_team_id' => $game->home_team_id,
        'away_team_id' => $game->away_team_id, 'exhibition_id' => $game->id, 'simulation_status' => $queue]);
    $this->getJson(route('exhibitions.arcade.show', $game))->assertConflict();
    reserveArcade($game)->assertConflict();
    $this->postJson($session['cancel_url'])->assertConflict();
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => ['version' => 1]])->assertConflict();
    expect($game->fresh()->history)->toBe([]);
})->with([null, 'queued']);

test('the existing Coach team-control endpoint invalidates reservations without changing Coach behavior', function () {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    $this->post(route('exhibitions.team-control', $game), ['version' => 0, 'home_control' => 'cpu', 'away_control' => 'human'])->assertRedirect();
    expect($game->fresh()->state['version'])->toBe(0);
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => ['version' => 1]])->assertConflict();
    expect(ArcadePlaySession::first()->status)->toBe('stale')->and($game->fresh()->history)->toBe([]);
});

test('historical arcade replay selects exact saved play after a later accepted play', function () {
    $game = persistentArcadeGame();
    $one = reserveArcade($game)->assertOk()->json('session');
    $firstRecording = liveArcadeRecording($one);
    $this->postJson($one['submit_url'], ['envelope' => $one['envelope'], 'recording' => $firstRecording])->assertOk();
    $game = $game->fresh();
    $two = reserveArcade($game, ['kind' => 'pass'])->assertOk()->json('session');
    $secondRecording = liveArcadeRecording($two);
    $this->postJson($two['submit_url'], ['envelope' => $two['envelope'], 'recording' => $secondRecording])->assertOk();
    $this->get(route('exhibitions.arcade.show', ['exhibition' => $game, 'play' => 1]))->assertOk()
        ->assertViewHas('selectedPlay', 1)->assertViewHas('replayOnly', true)->assertViewHas('available', false)->assertViewHas('pendingSession', null)->assertViewHas('lastRecording', fn ($r) => $r['frames'] === $firstRecording['frames']);
    $this->get(route('exhibitions.arcade.show', ['exhibition' => $game, 'play' => 2]))->assertOk()
        ->assertViewHas('lastRecording', fn ($r) => $r['frames'] === $secondRecording['frames']);
    $this->get(route('exhibitions.arcade.show', $game))->assertOk()
        ->assertViewHas('lastRecording', fn ($r) => $r['frames'] === $secondRecording['frames']);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee(route('exhibitions.arcade.show', ['exhibition' => $game, 'play' => 1]), false);
});

test('historical arcade replay rejects missing nonarcade malformed and foreign-world play selections', function () {
    $game = persistentArcadeGame();
    foreach (['0', '-1', '01', '1.0', '1e0', 'abc', '', '999999999999', '1'] as $play) {
        $this->get(route('exhibitions.arcade.show', ['exhibition' => $game]).'?play='.urlencode($play))->assertNotFound();
    }
    $this->get(route('exhibitions.arcade.show', $game).'?play[]=1')->assertNotFound();
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    $game->update(['history' => [['source' => 'coach', 'arcade_recording' => ['frames' => []]]]]);
    $this->get(route('exhibitions.arcade.show', ['exhibition' => $game, 'play' => 1]))->assertNotFound();
    openFootballSave(World::create(['name' => 'Another replay world']));
    $this->get(route('exhibitions.arcade.show', ['exhibition' => $game, 'play' => 1]))->assertNotFound();
});

test('production recording wire contract has its own bound version distinct from the proposal prototype', function () {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    expect($session['envelope']['contract_version'])->toBe('live-recording-1');
    $recording = liveArcadeRecording($session);
    $binding = $session['envelope'];
    $binding['contract_version'] = 'prototype-evidence-1';
    $this->postJson($session['submit_url'], ['envelope' => $binding, 'recording' => $recording])->assertUnprocessable();
    unset($binding['contract_version']);
    $this->postJson($session['submit_url'], ['envelope' => $binding, 'recording' => $recording])->assertUnprocessable();
    expect($game->fresh()->state['version'])->toBe(0);
    $response = $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $recording]);
    expect($response->json('message'))->toBeNull();
    $response->assertOk();
});

test('official arcade pacing remains approved when local drill speed configuration changes', function () {
    config(['arcade.speed_scale' => 1.1]);
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    expect($session['setup']['speedScale'])->toBe(.925)->and(config('arcade.speed_scale'))->toBe(1.1);
    $recording = liveArcadeRecording($session);
    expect($recording['speedScale'])->toBe(.925);
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $recording])->assertOk();
});

test('live session randomness advances between snaps but cancellation expiry and retries cannot reroll it', function () {
    $game = persistentArcadeGame();
    $one = reserveArcade($game)->assertOk()->json('session');
    $seed = $one['setup']['seed'];
    expect(reserveArcade($game)->assertOk()->json('session.setup.seed'))->toBe($seed);
    $this->postJson($one['cancel_url'])->assertOk();
    $two = reserveArcade($game)->assertOk()->json('session');
    expect($two['setup']['seed'])->toBe($seed)
        ->and($two['envelope']['session_id'])->not->toBe($one['envelope']['session_id']);
    $this->travel(181)->seconds();
    $three = reserveArcade($game)->assertOk()->json('session');
    expect($three['setup']['seed'])->toBe($seed);
    $recording = liveArcadeRecording($three);
    $body = ['envelope' => $three['envelope'], 'recording' => $recording];
    $receipt = $this->postJson($three['submit_url'], $body)->assertOk()->json('receipt');
    $this->postJson($three['submit_url'], $body)->assertOk()->assertJsonPath('receipt', $receipt);
    $next = reserveArcade($game->fresh())->assertOk()->json('session');
    expect($next['setup']['seed'])->not->toBe($seed)
        ->and($next['setup']['seed'])->toBe(\App\Services\Arcade\LivePlaySeed::forState($game->fresh()->state));
});

test('reservations created with the previous seed scheme keep their saved seed and accept exact recordings', function () {
    $game = persistentArcadeGame();
    $session = reserveArcade($game)->assertOk()->json('session');
    $stored = ArcadePlaySession::findOrFail($session['envelope']['session_id']);
    $setup = $stored->setup;
    $setup['seed'] = $game->state['seed'];
    $stored->update(['setup' => $setup]);
    $existing = reserveArcade($game)->assertOk()->json('session');
    expect($existing['setup']['seed'])->toBe($game->state['seed']);
    $recording = liveArcadeRecording($existing);
    $body = ['envelope' => $existing['envelope'], 'recording' => $recording];
    $receipt = $this->postJson($existing['submit_url'], $body)->assertOk()->json('receipt');
    $this->postJson($existing['submit_url'], $body)->assertOk()->assertJsonPath('receipt', $receipt);
    expect($game->fresh()->history)->toHaveCount(1)
        ->and($game->fresh()->history[0]['arcade_recording']['seed'])->toBe($game->state['seed']);
});
