<?php

use App\Models\ArcadePlaySession;
use App\Models\Exhibition;
use App\Models\Team;
use App\Models\World;
use App\Services\Arcade\FullTeamSetup;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\RosterBuilder;

beforeEach(function () {
    $this->withoutVite();
    config(['arcade.enabled' => true]);
    openFootballSave(World::create(['name' => 'Full team']));
});
function fullTeamTestGame(array $changes = []): Exhibition
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

test('full team reservation pins exactly twenty two active identities and selected call', function (string $call) {
    $game = fullTeamTestGame();
    $kind = in_array($call, ['inside_run', 'outside_run'], true) ? 'run' : 'pass';
    $data = ['version' => 0, 'kind' => $kind, 'tempo' => 'normal', 'contract_version' => 'live-recording-2', 'call' => $call];
    $response = $this->postJson(route('exhibitions.arcade.start', $game), $data)->assertOk();
    $session = $response->json('session');
    expect($session['setup']['players'])->toHaveCount(22)->and($session['setup']['actors'])->toHaveCount(22)
        ->and(array_unique(array_column($session['setup']['players'], 'id')))->toHaveCount(22)
        ->and($session['setup']['call'])->toBe($call)->and($session['envelope']['call'])->toBe($call)
        ->and($session['envelope']['contract_version'])->toBe('live-recording-2')->and($session['setup']['speedScale'])->toBe(.925);
    foreach (['home', 'away'] as $side) {
        expect(array_filter($session['setup']['players'], fn ($p) => $p['side'] === $side))->toHaveCount(11);
    }
    $this->postJson(route('exhibitions.arcade.start', $game), $data)->assertOk()->assertJsonPath('session.envelope.session_id', $session['envelope']['session_id']);
    $other = $kind === 'run' ? ($call === 'inside_run' ? 'outside_run' : 'inside_run') : ($call === 'short_pass' ? 'deep_pass' : 'short_pass');
    $this->postJson(route('exhibitions.arcade.start', $game), array_replace($data, ['call' => $other]))->assertConflict();
    expect(ArcadePlaySession::count())->toBe(1)->and($game->fresh()->history)->toBe([])->and($game->fresh()->state['version'])->toBe(0);
})->with(FullTeamSetup::CALLS);
test('full team rejects duplicate and unavailable assignments without reserving', function () {
    $game = fullTeamTestGame();
    $rosters = $game->rosters;
    $rosters['home']['players']['WR3'] = $rosters['home']['players']['WR2'];
    $game->update(['rosters' => $rosters]);
    $this->postJson(route('exhibitions.arcade.start', $game), ['version' => 0, 'kind' => 'run', 'tempo' => 'normal', 'contract_version' => 'live-recording-2', 'call' => 'inside_run'])->assertConflict();
    expect(ArcadePlaySession::count())->toBe(0)->and($game->fresh()->history)->toBe([]);
});
test('old start remains v1 and cannot be silently upgraded by retry', function () {
    $game = fullTeamTestGame();
    $data = ['version' => 0, 'kind' => 'run', 'tempo' => 'normal'];
    $this->postJson(route('exhibitions.arcade.start', $game), $data)->assertOk()->assertJsonPath('session.envelope.contract_version', 'live-recording-1');
    $this->postJson(route('exhibitions.arcade.start', $game), $data + ['contract_version' => 'live-recording-2', 'call' => 'inside_run'])->assertConflict();
});

test('full team snapshot uses authoritative fatigue lineup and injury selection', function () {
    $game = fullTeamTestGame();
    $rosters = $game->rosters;
    foreach (['home', 'away'] as $side) {
        $rosters[$side]['pool'] = array_values($rosters[$side]['players']);
    }
    $replacement = $rosters['home']['players']['RB'];
    $replacement['id'] = 9999;
    $replacement['depth'] = 'RB2';
    $replacement['name'] = 'Healthy reserve';
    $rosters['home']['pool'][] = $replacement;
    $state = $game->state;
    $state['injuries']['home'][$rosters['home']['players']['RB']['id']] = ['return_snap' => null];
    $state['fatigue']['home'][$rosters['home']['players']['LG']['id']] = 90;
    $state['game_lineup']['home']['RB'] = 9999;
    $game->update(['rosters' => $rosters, 'state' => $state]);
    $before = $game->fresh()->getAttributes();
    $expected = app(\App\Services\Simulation\GamePersonnel::class)->active($rosters, $state, 'inside_run', 'shotgun');
    $session = $this->postJson(route('exhibitions.arcade.start', $game), ['version' => 0, 'kind' => 'run', 'tempo' => 'normal', 'contract_version' => 'live-recording-2', 'call' => 'inside_run'])->assertOk()->json('session');
    $setup = $session['setup'];
    foreach ($setup['players'] as $player) {
        expect($player['id'])->toBe($expected[$player['side']]['players'][$player['role']]['id'])
            ->and($player['ratings'])->toBe($expected[$player['side']]['players'][$player['role']]['ratings']);
    }
    expect($setup['actors']['offense_RB']['player_id'])->toBe(9999)->and($game->fresh()->getAttributes())->toBe($before);
    require_once base_path('tests/Fixtures/fullteam-benchmark-support.php');
    $recording = fullteamBenchmarkRun(['setup' => $setup])['recording'];
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $recording])->assertOk()->assertJsonPath('play.arcade_participants.carrier.player_id', 9999);
    $box = app(\App\Services\Simulation\ExhibitionBoxScore::class)->build($game->fresh());
    $reserve = collect($box['players']['home'])->firstWhere('id', 9999);
    $starter = collect($box['players']['home'])->firstWhere('id', $rosters['home']['players']['RB']['id']);
    expect($reserve['rushes'])->toBe(1)->and($starter['rushes'])->toBe(0);

});

test('full team recorded result commits once and rejects changed bound call or missing actor', function (string $call) {
    require_once base_path('tests/Fixtures/fullteam-benchmark-support.php');
    $game = fullTeamTestGame(['spot' => 35]);
    $kind = in_array($call, ['inside_run', 'outside_run'], true) ? 'run' : 'pass';
    $session = $this->postJson(route('exhibitions.arcade.start', $game), ['version' => 0, 'kind' => $kind, 'tempo' => 'normal', 'contract_version' => 'live-recording-2', 'call' => $call])->assertOk()->json('session');
    $recording = fullteamBenchmarkRun(['setup' => $session['setup']])['recording'];
    $body = ['envelope' => $session['envelope'], 'recording' => $recording];
    $bad = $body;
    $bad['recording']['call'] = $call === 'inside_run' ? 'outside_run' : 'inside_run';
    $this->postJson($session['submit_url'], $bad)->assertUnprocessable();
    $bad = $body;
    array_pop($bad['recording']['actors']);
    $this->postJson($session['submit_url'], $bad)->assertUnprocessable();
    $badKey = $body;
    $badKey['recording']['actors'][0]['key'] = [];
    $this->postJson($session['submit_url'], $badKey)->assertUnprocessable();
    $badIdentity = $body;
    $badIdentity['recording']['actors'][0]['id'] = 999999;
    $this->postJson($session['submit_url'], $badIdentity)->assertUnprocessable();
    $badPosition = $body;
    $badPosition['recording']['frames'][1][3][0][0] += 1000;
    $this->postJson($session['submit_url'], $badPosition)->assertUnprocessable();
    $badVersion = $body;
    $badVersion['recording']['version'] = 1;
    $this->postJson($session['submit_url'], $badVersion)->assertUnprocessable();
    expect($game->fresh()->state['version'])->toBe(0);
    $accepted = $this->postJson($session['submit_url'], $body);
    $this->assertSame(200, $accepted->status(), $accepted->json('message') ?? 'Expected accepted result');
    $accepted->assertJsonPath('play.call', $call)->assertJsonPath('state.version', 1);
    $saved = $game->fresh();
    expect($saved->history)->toHaveCount(1)->and($saved->history[0]['arcade_recording']['actors'])->toHaveCount(22)
        ->and($saved->history[0]['animation']['players'])->toHaveCount(22)->and($saved->state['personnel_snaps'])->toBe(1);
    $this->postJson($session['submit_url'], $body)->assertOk();
    expect($game->fresh()->getAttributes())->toBe($saved->getAttributes());
    $this->postJson($session['submit_url'], $bad)->assertConflict();
})->with(FullTeamSetup::CALLS);

test('called run stopped before handoff credits QB rush not sack or RB carry', function () {
    require_once base_path('tests/Fixtures/fullteam-benchmark-support.php');
    $game = fullTeamTestGame(['spot' => 35]);
    $session = $this->postJson(route('exhibitions.arcade.start', $game), ['version' => 0, 'kind' => 'run', 'tempo' => 'normal', 'contract_version' => 'live-recording-2', 'call' => 'inside_run'])->assertOk()->json('session');
    $recording = fullteamBenchmarkRun(['setup' => $session['setup']])['recording'];
    // Bounded synthetic evidence: all 22 starts are exact; the QB is caught before handing off.
    $first = $recording['frames'][0];
    $qb = 0;
    $defender = 11;
    $recording['frames'] = [];
    for ($tick = 0; $tick <= 60; $tick += 2) {
        $frame = $first;
        $frame[0] = $tick;
        $frame[1] = (int) round($tick * 1000000 / 60);
        foreach ([0, 1] as $axis) {
            $target = $first[3][$qb][$axis] + ($axis === 0 ? 80 : 0);
            $frame[3][$defender][$axis] = (int) round($first[3][$defender][$axis] + ($target - $first[3][$defender][$axis]) * $tick / 60);
        }
        if ($tick === 60) {
            $frame[4][3] = 2;
        }
        $recording['frames'][] = $frame;
    }
    $position = [$first[3][$qb][0] / 100, $first[3][$qb][1] / 100];
    $recording['events'] = [[0, 0, 'start', $qb, -1, []], [60, 1000000, 'tackle', $qb, $defender, []], [60, 1000000, 'finished', $qb, -1, ['reason' => 'tackle', 'position' => $position]]];
    $recording['result'] = ['reason' => 'tackle'];
    $response = $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $recording]);
    $this->assertSame(200, $response->status(), $response->json('message') ?? 'Expected accepted prehandoff tackle');
    $response->assertJsonPath('play.carrier', 'QB')->assertJsonPath('play.qb_designed_run', true);
    $box = app(\App\Services\Simulation\ExhibitionBoxScore::class)->build($game->fresh());
    expect($box['players']['home']['QB']['rushes'])->toBe(1)->and($box['players']['home']['RB']['rushes'])->toBe(0)
        ->and($box['players']['home']['QB']['sacks'])->toBe(0)->and($box['players']['away']['DE1']['defensive_sacks'])->toBe(0)
        ->and($box['players']['away']['DE1']['tackles'])->toBe(1);
});

test('full team cancellation expiry and stale versions never commit an outcome', function () {
    $game = fullTeamTestGame();
    $start = ['version' => 0, 'kind' => 'pass', 'tempo' => 'normal', 'contract_version' => 'live-recording-2', 'call' => 'medium_pass'];
    $session = $this->postJson(route('exhibitions.arcade.start', $game), $start)->assertOk()->json('session');
    $this->postJson($session['cancel_url'])->assertOk()->assertJsonPath('cancelled', true);
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => ['version' => 2]])->assertConflict();
    $session = $this->postJson(route('exhibitions.arcade.start', $game), $start)->assertOk()->json('session');
    $this->travel(181)->seconds();
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => ['version' => 2]])->assertGone();
    $session = $this->postJson(route('exhibitions.arcade.start', $game), $start)->assertOk()->json('session');
    $state = $game->fresh()->state;
    $state['version']++;
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    $game->update(['state' => $state]);
    $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => ['version' => 2]])->assertConflict();
    expect($game->fresh()->history)->toBe([])->and($game->fresh()->state)->toBe($state);
});

test('interception terminal carrier is the actual defender even while control remains QB', function () {
    require_once base_path('tests/Fixtures/fullteam-benchmark-support.php');
    $game = fullTeamTestGame(['spot' => 35]);
    $session = $this->postJson(route('exhibitions.arcade.start', $game), ['version' => 0, 'kind' => 'pass', 'tempo' => 'normal', 'contract_version' => 'live-recording-2', 'call' => 'short_pass'])->assertOk()->json('session');
    $r = fullteamBenchmarkRun(['setup' => $session['setup']])['recording'];
    $first = $r['frames'][0];
    $defender = 12;
    $qb = 0;
    $contact = [$first[3][$defender][0], 165, $first[3][$defender][1]];
    $r['frames'] = [$first];
    foreach (array_merge([1], range(2, 30, 2)) as $tick) {
        $frame = $first;
        $frame[0] = $tick;
        $frame[1] = $tick === 1 ? 0 : (int) round($tick * 1000000 / 60);
        $fraction = $frame[1] / 500000;
        $frame[4] = [(int) round($first[4][0] + ($contact[0] - $first[4][0]) * $fraction), 165, (int) round($first[4][2] + ($contact[2] - $first[4][2]) * $fraction), $tick === 30 ? 2 : 1, $tick === 30 ? $defender : -1];
        $r['frames'][] = $frame;
    }
    $contactInfo = ['contact' => $contact, 'contactExact' => array_map(fn ($n) => $n / 100, $contact)];
    $r['events'] = [[0, 0, 'start', $qb, -1, []], [1, 0, 'throw', $qb, -1, ['actualTarget' => [$contact[0], $contact[2]]]],
        [30, 500000, 'catch_attempt', $defender, -1, $contactInfo + ['success' => true]], [30, 500000, 'interception', $defender, -1, $contactInfo],
        [30, 500000, 'finished', $qb, -1, ['reason' => 'interception', 'position' => [$first[3][$qb][0] / 100, $first[3][$qb][1] / 100]]]];
    $r['result'] = ['reason' => 'interception'];
    $response = $this->postJson($session['submit_url'], ['envelope' => $session['envelope'], 'recording' => $r]);
    $this->assertSame(200, $response->status(), $response->json('message') ?? 'Expected valid interception');
    $response->assertJsonPath('play.arcade_participants.carrier.player_id', $r['actors'][$defender]['id'])->assertJsonPath('play.arcade_participants.interceptor.player_id', $r['actors'][$defender]['id']);
    $box = app(\App\Services\Simulation\ExhibitionBoxScore::class)->build($game->fresh());
    expect($box['players']['away']['DT1']['defensive_interceptions'])->toBe(1)->and($box['players']['home']['QB']['interceptions'])->toBe(1);
});
