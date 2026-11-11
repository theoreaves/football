<?php

use App\Models\ArcadeFlowReceipt;
use App\Models\ArcadePlaySession;
use App\Models\Exhibition;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\RosterBuilder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    config(['arcade.enabled' => true]);
    openFootballSave(World::create(['name' => 'Complete Arcade exhibition']));
});

function completeArcadeFixture(int $seed, string $human): Exhibition
{
    app(\App\Support\CurrentWorld::class)->id = (int) session('current_world_id');
    $teams = [];
    $rosters = [];
    foreach (['home', 'away'] as $side) {
        $teams[$side] = Team::create(['city' => ucfirst($side), 'name' => 'Flow']);
        foreach (RosterBuilder::GROUPS as $role => $positions) {
            $id = ($side === 'home' ? 0 : 100) + count($rosters[$side]['players'] ?? []) + 1;
            $player = ['id' => $id, 'name' => $side.' '.$role, 'number' => $id % 100, 'position' => $positions[0], 'depth' => $role,
                'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70), 'appearance' => [], 'height_inches' => 72, 'weight_pounds' => 220];
            $rosters[$side]['players'][$role] = $player;
            $rosters[$side]['pool'][] = $player;
        }
    }
    $state = app(ExhibitionEngine::class)->initial(180, $seed);
    $state['controls'] = ['home' => $human === 'home' ? 'human' : 'cpu', 'away' => $human === 'away' ? 'human' : 'cpu'];
    $state['rules'] = ['penalties' => false, 'injuries' => false, 'overtime' => 'modern'];
    $state['opening_receiver'] = $human;
    $state['coin_toss'] = ['result' => 'heads', 'call' => 'heads', 'winner' => $human, 'pending' => true];

    return Exhibition::create(['home_team_id' => $teams['home']->id, 'away_team_id' => $teams['away']->id, 'rosters' => $rosters, 'state' => $state, 'history' => []]);
}

function completeArcadeStatus(Exhibition $game): array
{
    return test()->getJson('/exhibitions/'.$game->id.'/arcade/flow')->assertOk()->json();
}

function completeArcadeCommand(Exhibition $game, array $payload): array
{
    $status = completeArcadeStatus($game);
    $command = ['command_id' => (string) Str::uuid(), 'version' => $status['state']['version'],
        'flow_revision' => $status['flow']['revision'], 'state_hash' => $status['flow']['state_hash']] + $payload;
    $url = '/exhibitions/'.$game->id.'/arcade/flow';
    $first = test()->postJson($url, $command)->assertOk()->json();
    $saved = $game->fresh()->only(['state', 'history']);
    $receipts = ArcadeFlowReceipt::where('exhibition_id', $game->id)->count();
    test()->postJson($url, $command)->assertOk()->assertExactJson(array_replace($first, ['replayed' => true]));
    expect($game->fresh()->only(['state', 'history']))->toBe($saved)
        ->and(ArcadeFlowReceipt::where('exhibition_id', $game->id)->count())->toBe($receipts);

    return $first;
}

function completeArcadeRecord(array $session, int $distance): array
{
    $process = new \Symfony\Component\Process\Process(['node', base_path('tests/Fixtures/arcade-flow-physics.mjs')], base_path());
    $process->setInput(json_encode(['setup' => $session['setup'], 'distance' => $distance], JSON_THROW_ON_ERROR)."\n");
    $process->mustRun();

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['recording'];
}

/** Full persistent game through real commands/recordings, not a quick-sim-drive surrogate. */
test('complete Arcade exhibitions reach the final whistle with exact receipts and preserved live replays', function (int $seed, string $human) {
    $game = completeArcadeFixture($seed, $human);
    $before = $game->only(['state', 'history']);
    completeArcadeStatus($game);
    expect($game->fresh()->only(['state', 'history']))->toBe($before);
    completeArcadeCommand($game, ['action' => 'preferences', 'human_side' => $human, 'defense_mode' => 'coach']);
    $arcadeSnaps = 0;
    $modes = [];
    $entered = [];
    $snapshotReplay = [];
    for ($index = 0; $index < 300; $index++) {
        $status = completeArcadeStatus($game);
        $state = $status['state'];
        if (getenv('ARCADE_COMPLETE_FLOW_TRACE')) {
            fwrite(STDERR, json_encode(['index' => $index, 'state' => array_intersect_key($state, array_flip(['version', 'quarter', 'clock', 'phase', 'status', 'possession', 'down']))])."\n");
        }
        if ($state['status'] === 'final') {
            break;
        }
        $entered[$state['quarter']] = true;
        $flow = $status['flow'];
        if ($state['coin_toss']['pending'] ?? false) {
            completeArcadeCommand($game, ['action' => 'coin', 'choice' => 'receive']);
        } elseif ($state['penalty_pending'] ?? false) {
            completeArcadeCommand($game, ['action' => 'penalty', 'decision' => 'accept']);
        } elseif ($state['overtime']['toss']['call_pending'] ?? false) {
            completeArcadeCommand($game, ['action' => 'ot_call', 'toss_call' => 'heads']);
        } elseif ($state['overtime']['toss']['pending'] ?? false) {
            completeArcadeCommand($game, ['action' => 'ot_choice', 'choice' => 'receive']);
        } elseif ($status['available'] && $state['down'] < 4) {
            $kind = $arcadeSnaps % 2 === 0 ? 'run' : 'pass';
            $reservation = $this->postJson(route('exhibitions.arcade.start', $game), ['version' => $state['version'], 'kind' => $kind, 'tempo' => 'normal'])->assertOk()->json();
            if ($session = $reservation['session']) {
                expect($session['setup']['speedScale'])->toBe(.925);
                $recording = completeArcadeRecord($session, (int) $state['distance']);
                $body = ['envelope' => $session['envelope'], 'recording' => $recording];
                $response = $this->postJson($session['submit_url'], $body)->assertOk();
                $saved = $game->fresh()->only(['state', 'history']);
                $this->postJson($session['submit_url'], $body)->assertOk()->assertJsonPath('receipt', $response->json('receipt'));
                expect($game->fresh()->only(['state', 'history']))->toBe($saved);
                $play = $response->json('play');
                expect($play['arcade_recording']['frames'])->toBe($recording['frames']);
                $snapshotReplay[$play['number']] = $recording['frames'];
                $arcadeSnaps++;
            }
        } else {
            if ($state['phase'] === 'scrimmage' && $state['possession'] !== $human) {
                $mode = ['coach', 'watch', 'fast'][count($modes) % 3];
                if ($flow['defense_mode'] !== $mode) {
                    completeArcadeCommand($game, ['action' => 'preferences', 'defense_mode' => $mode]);
                    $status = completeArcadeStatus($game);
                    $flow = $status['flow'];
                }
                $modes[] = $mode;
                if ($mode !== 'coach') {
                    completeArcadeCommand($game, ['action' => $mode === 'fast' ? 'fast' : 'advance']);

                    continue;
                }
            }
            $call = $state['phase'] === 'scrimmage'
                ? ($state['possession'] === $human ? ($state['spot'] >= 65 ? 'field_goal' : 'punt') : app(CpuCoach::class)->offense($state, $game->rosters)['call'])
                : ExhibitionEngine::callsForState($state)[0];
            completeArcadeCommand($game, ['action' => 'play', 'call' => $call, 'defense' => ExhibitionEngine::defensesForCall($call)[0],
                'offense_formation' => 'shotgun', 'defense_formation' => 'base_4_3', 'tempo' => 'normal']);
        }
    }
    $saved = $game->fresh();
    expect($saved->state['status'])->toBe('final')->and($entered)->toHaveKeys([1, 2, 3, 4])
        ->and($arcadeSnaps)->toBeGreaterThan(0)->and(array_unique($modes))->toContain('coach', 'watch', 'fast')
        ->and(ArcadePlaySession::where('exhibition_id', $game->id)->where('status', 'accepted')->count())->toBe($arcadeSnaps)
        ->and(ArcadePlaySession::where('active_slot', $game->id)->count())->toBe(0);
    $numbers = array_column($saved->history, 'number');
    expect(count($numbers))->toBe(count(array_unique($numbers)))->and(count($numbers))->toBe($saved->state['version']);
    foreach ($saved->history as $play) {
        if (isset($snapshotReplay[$play['number']])) {
            expect($play['arcade_recording']['frames'])->toBe($snapshotReplay[$play['number']]);
        }
    }
    $snapshot = $saved->only(['state', 'history']);
    $this->get(route('exhibitions.arcade.show', $game))->assertOk();
    completeArcadeStatus($game);
    expect($game->fresh()->only(['state', 'history']))->toBe($snapshot);
    if (getenv('ARCADE_COMPLETE_FLOW_REPORT')) {
        $quarters = [];
        $drive = null;
        foreach ($saved->history as $play) {
            if (($play['before']['phase'] ?? '') !== 'scrimmage' || ($play['no_snap'] ?? false)) {
                continue;
            }
            $period = $play['before']['quarter'];
            $side = $play['before']['possession'];
            $quarters[$period][$side]['snaps'] = ($quarters[$period][$side]['snaps'] ?? 0) + 1;
            if ($drive !== $side) {
                $quarters[$period][$side]['possessions'] = ($quarters[$period][$side]['possessions'] ?? 0) + 1;
            }
            $drive = ($play['after']['possession'] ?? null) === $side && ($play['after']['phase'] ?? '') === 'scrimmage' ? $side : null;
        }
        fwrite(STDOUT, json_encode(['seed' => $seed, 'human' => $human, 'quarter_length' => 180, 'score' => [$saved->state['home_score'], $saved->state['away_score']], 'arcade_snaps' => $arcadeSnaps, 'per_quarter' => $quarters], JSON_THROW_ON_ERROR)."\n");
    }
})->with([[42, 'home'], [137, 'away']]);
