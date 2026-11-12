<?php

use App\Models\ArcadeFlowReceipt;
use App\Models\ArcadeGameFlow;
use App\Models\ArcadePlaySession;
use App\Models\Exhibition;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GamePersonnel;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\RosterBuilder;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->withoutVite();
    config(['arcade.enabled' => true]);
    openFootballSave(World::create(['name' => 'Persistent stadium acceptance']));
});

function stageAReviewGame(): Exhibition
{
    app(\App\Support\CurrentWorld::class)->id = (int) session('current_world_id');
    $teams = $rosters = [];
    foreach (['home', 'away'] as $side) {
        $teams[$side] = Team::create(['city' => ucfirst($side), 'name' => 'Stage A']);
        foreach (RosterBuilder::GROUPS as $role => $positions) {
            $id = ($side === 'home' ? 0 : 100) + count($rosters[$side]['players'] ?? []) + 1;
            $person = ['id' => $id, 'name' => $side.' '.$role, 'number' => $id % 100,
                'position' => $positions[0], 'depth' => $role, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70),
                'appearance' => [], 'height_inches' => 72, 'weight_pounds' => 220];
            $rosters[$side]['players'][$role] = $person;
            $rosters[$side]['pool'][] = $person;
        }
    }
    $state = app(ExhibitionEngine::class)->initial(180, 42);
    $state['controls'] = ['home' => 'human', 'away' => 'cpu'];
    $state['rules'] = ['penalties' => false, 'injuries' => false, 'overtime' => 'modern'];
    $state['opening_receiver'] = 'home';
    $state['coin_toss'] = ['result' => 'heads', 'call' => 'heads', 'winner' => 'home', 'pending' => true];
    $game = Exhibition::create(['home_team_id' => $teams['home']->id, 'away_team_id' => $teams['away']->id,
        'rosters' => $rosters, 'state' => $state, 'history' => []]);
    ArcadeGameFlow::create(['exhibition_id' => $game->id, 'human_side' => 'home', 'defense_mode' => 'coach',
        'opening_enabled' => true, 'intro_seen' => false]);

    return $game;
}

function stageAPersistedEvidence(Exhibition $game): array
{
    return [$game->fresh()->getRawOriginal(),
        ArcadeGameFlow::where('exhibition_id', $game->id)->first()->getRawOriginal(),
        ArcadePlaySession::where('exhibition_id', $game->id)->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all(),
        ArcadeFlowReceipt::where('exhibition_id', $game->id)->count()];
}

function stageARead(Exhibition $game): array
{
    $before = stageAPersistedEvidence($game);
    $snapshot = test()->getJson(route('exhibitions.arcade.presentation', $game))->assertOk()->json();
    expect(stageAPersistedEvidence($game))->toBe($before)
        ->and($snapshot['state'])->toBe($game->fresh()->state)
        ->and($snapshot['history_cursor'])->toBe(count($game->fresh()->history));
    if ($last = $snapshot['latest_play']) {
        $playback = test()->getJson($last['url'])->assertOk()->json();
        $saved = collect($game->fresh()->history)->firstWhere('number', $last['number']);
        expect($playback['before'])->toBe($saved['before'])->and($playback['after'])->toBe($saved['after']);
        if ($last['source'] === 'arcade') {
            expect($playback['recording'])->toBe($saved['arcade_recording'])->and($playback['animation'])->toBeNull();
        } else {
            expect($playback['animation'])->toBe($saved['animation'])->and($playback['recording'])->toBeNull();
        }
        expect(stageAPersistedEvidence($game))->toBe($before);
    }

    return $snapshot;
}

function stageACommand(Exhibition $game, array $action): array
{
    $snapshot = stageARead($game);
    $command = ['command_id' => (string) Str::uuid(), 'version' => $snapshot['state']['version'],
        'flow_revision' => $snapshot['flow']['revision'], 'state_hash' => $snapshot['flow']['state_hash']] + $action;
    $response = test()->postJson(route('exhibitions.arcade.flow', $game), $command)->assertOk()->json();
    $saved = stageAPersistedEvidence($game);
    test()->postJson(route('exhibitions.arcade.flow', $game), $command)->assertOk()->assertJsonPath('replayed', true);
    expect(stageAPersistedEvidence($game))->toBe($saved);

    return $response;
}

test('persistent presentation reads span opening Arcade defense Fast and replay without changing football', function () {
    $game = stageAReviewGame();
    $opening = stageARead($game);
    expect($opening['flow']['opening_enabled'])->toBeTrue()->and($opening['flow']['intro_seen'])->toBeFalse()
        ->and($opening['flow']['required_decision'])->toBe('coin');
    $football = $game->fresh()->only(['state', 'history', 'rosters']);
    stageACommand($game, ['action' => 'intro_seen']);
    expect($game->fresh()->only(['state', 'history', 'rosters']))->toBe($football);
    stageACommand($game, ['action' => 'coin', 'choice' => 'receive']);
    $live = [];
    $modes = [];
    $returned = false;
    for ($index = 0; $index < 120; $index++) {
        $snapshot = stageARead($game);
        $state = $snapshot['state'];
        $flow = $snapshot['flow'];
        if (count($live) >= 2 && count(array_unique($modes)) === 3 && $flow['phase'] === 'offense') {
            $returned = true;
            break;
        }
        if ($state['status'] === 'final') {
            break;
        }
        if ($snapshot['available'] && $state['down'] < 4) {
            $kind = count($live) % 2 ? 'pass' : 'run';
            $session = $this->postJson(route('exhibitions.arcade.start', $game), ['version' => $state['version'],
                'kind' => $kind, 'tempo' => 'normal'])->assertOk()->json('session');
            if (! $session) {
                continue;
            }
            expect($session['setup']['speedScale'])->toBe(.925)->and($session['setup']['actors'])->toHaveCount($kind === 'run' ? 3 : 5);
            expect(stageARead($game)['pending_session']['envelope'])->toBe($session['envelope']);
            $process = new Process(['node', base_path('tests/Fixtures/arcade-flow-physics.mjs')], base_path());
            $process->setInput(json_encode(['setup' => $session['setup'], 'distance' => $state['distance']], JSON_THROW_ON_ERROR)."\n");
            $process->mustRun();
            $recording = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['recording'];
            $body = ['envelope' => $session['envelope'], 'recording' => $recording];
            $result = $this->postJson($session['submit_url'], $body)->assertOk()->json();
            $saved = stageAPersistedEvidence($game);
            $this->postJson($session['submit_url'], $body)->assertOk()->assertJsonPath('receipt', $result['receipt']);
            expect(stageAPersistedEvidence($game))->toBe($saved);
            $live[$result['play']['number']] = $recording['frames'];
        } else {
            if ($flow['required_decision'] === 'ot_call') {
                stageACommand($game, ['action' => 'ot_call', 'toss_call' => 'heads']);

                continue;
            }
            if (in_array($flow['required_decision'], ['coin', 'ot_choice'], true)) {
                stageACommand($game, ['action' => $flow['required_decision'], 'choice' => 'receive']);

                continue;
            }
            if ($state['phase'] === 'scrimmage' && $state['possession'] === 'away') {
                $mode = ['coach', 'watch', 'fast'][count($modes) % 3];
                stageACommand($game, ['action' => 'preferences', 'defense_mode' => $mode]);
                $modes[] = $mode;
                if ($mode !== 'coach') {
                    stageACommand($game, ['action' => $mode === 'fast' ? 'fast' : 'advance']);

                    continue;
                }
            }
            $personnel = app(GamePersonnel::class)->active($game->fresh()->rosters, $state);
            $call = $state['phase'] === 'scrimmage'
                ? ($state['possession'] === 'home' ? 'punt' : app(CpuCoach::class)->offense($state, $personnel)['call'])
                : ExhibitionEngine::callsForState($state)[0];
            stageACommand($game, ['action' => 'play', 'call' => $call, 'defense' => ExhibitionEngine::defensesForCall($call)[0],
                'offense_formation' => 'shotgun', 'defense_formation' => 'base_4_3', 'tempo' => 'normal']);
        }
    }
    expect($returned)->toBeTrue()->and($modes)->toContain('coach', 'watch', 'fast');
    $before = stageAPersistedEvidence($game);
    foreach ($live as $number => $frames) {
        $this->getJson(route('exhibitions.arcade.playback', ['exhibition' => $game, 'number' => $number]))
            ->assertOk()->assertJsonPath('recording.frames', $frames);
    }
    stageARead($game);
    expect(stageAPersistedEvidence($game))->toBe($before);
    $history = $game->fresh()->history;
    expect(array_unique(array_column($history, 'number')))->toHaveCount(count($history))
        ->and($game->fresh()->state['version'])->toBe(count($history));
});
