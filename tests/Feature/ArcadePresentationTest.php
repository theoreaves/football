<?php

use App\Models\Exhibition;
use App\Models\Team;
use App\Models\World;
use App\Services\Arcade\ExhibitionPresentation;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\RosterBuilder;

beforeEach(function () {
    $this->withoutVite();
    config(['arcade.enabled' => true]);
    openFootballSave(World::create(['name' => 'Presentation']));
});

function presentationGame(array $changes = []): Exhibition
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

test('persistent presentation reads neither mutate the model nor reserve or advance a game', function () {
    $game = presentationGame()->refresh();
    $before = $game->getAttributes();
    $view = app(ExhibitionPresentation::class)->viewData($game);
    expect($game->getAttributes())->toBe($before);
    $one = $this->getJson(route('exhibitions.arcade.presentation', $game))->assertOk()->assertJsonPath('presentation_version', 1)->assertJsonPath('history_cursor', 0)->assertJsonPath('pending_session', null);
    $two = $this->getJson(route('exhibitions.arcade.presentation', $game))->assertOk();
    expect($two->json())->toBe($one->json())->and($game->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('arcade_play_sessions', 0);
    $this->assertDatabaseCount('arcade_flow_receipts', 0);
    expect($one->json('controls_html'))->toContain('data-practice')->and($one->json())->not->toHaveKey('history');
});

test('presentation and playback enforce feature world owner and standalone gates', function () {
    $game = presentationGame();
    config(['arcade.enabled' => false]);
    $this->getJson(route('exhibitions.arcade.presentation', $game))->assertNotFound();
    $this->getJson(route('exhibitions.arcade.playback', [$game, 1]))->assertNotFound();
    config(['arcade.enabled' => true]);
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    $state = $game->state;
    $state['season_id'] = 123;
    $game->update(['state' => $state]);
    $this->getJson(route('exhibitions.arcade.presentation', $game))->assertConflict();
    $this->getJson(route('exhibitions.arcade.playback', [$game, 1]))->assertConflict();
    expect($game->fresh()->history)->toBe([]);
});

test('read playback returns exact saved source data without duplicating recordings', function () {
    $game = presentationGame();
    $result = app(ExhibitionEngine::class)->resolve($game->state, $game->rosters, 'inside_run', 'man_to_man');
    $play = $result['play'];
    $game->update(['state' => $result['state'], 'history' => [$play]]);
    $play = $game->fresh()->history[0];
    $url = route('exhibitions.arcade.playback', [$game, $play['number']]);
    $this->getJson($url)->assertOk()->assertJsonPath('source', 'coach')->assertJsonPath('animation', $play['animation'])->assertJsonPath('recording', null);
    app(\App\Support\CurrentWorld::class)->id = $game->world_id;
    $recording = ['version' => 1, 'frames' => [[0, 0, 0, [], []]], 'marker' => 'saved-exact'];
    $play['source'] = 'arcade';
    $play['arcade_recording'] = $recording;
    $play['animation']['recording'] = $recording;
    $game->update(['history' => [$play]]);
    $game->refresh();
    $before = $game->getAttributes();
    $this->getJson($url)->assertOk()->assertJsonPath('source', 'arcade')->assertJsonPath('recording', $recording)->assertJsonPath('animation', null);
    $snapshot = $this->getJson(route('exhibitions.arcade.presentation', $game))->assertOk();
    expect($snapshot->json('latest_play.number'))->toBe($play['number'])->and($snapshot->json('scene.animation'))->not->toHaveKey('recording')->and($snapshot->json('controls_html'))->not->toContain('saved-exact')->and($game->fresh()->getAttributes())->toBe($before);
    $this->getJson(route('exhibitions.arcade.playback', [$game, 999]))->assertNotFound();
});

test('presentation resumes a pending binding without renewing it and historical reads never touch it', function () {
    $game = presentationGame();
    $session = $this->postJson(route('exhibitions.arcade.start', $game), ['version' => 0, 'kind' => 'run', 'tempo' => 'normal'])->assertOk()->json('session');
    $saved = \App\Models\ArcadePlaySession::first()->getAttributes();
    $this->getJson(route('exhibitions.arcade.presentation', $game))->assertOk()->assertJsonPath('pending_session.envelope', $session['envelope']);
    $this->getJson(route('exhibitions.arcade.playback', [$game, 1]))->assertNotFound();
    expect(\App\Models\ArcadePlaySession::first()->getAttributes())->toBe($saved)->and($game->fresh()->history)->toBe([]);
    \App\Models\ArcadePlaySession::query()->update(['expires_at' => now()->subSecond()]);
    $expired = \App\Models\ArcadePlaySession::first()->getAttributes();
    $this->getJson(route('exhibitions.arcade.presentation', $game))->assertOk()->assertJsonPath('pending_session', null);
    expect(\App\Models\ArcadePlaySession::first()->getAttributes())->toBe($expired);
});

test('Play deep URL uses persistent shell while Coach and historical views retain original path', function () {
    $game = presentationGame();
    $this->get(route('exhibitions.show', $game))->assertOk()->assertViewIs('exhibitions.show');
    \App\Models\ArcadeGameFlow::create(['exhibition_id' => $game->id, 'human_side' => 'home', 'defense_mode' => 'coach']);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertViewIs('arcade.exhibition')->assertViewHas('presentationUrl', route('exhibitions.arcade.presentation', $game));
    $this->get(route('exhibitions.arcade.show', $game))->assertOk()->assertViewIs('arcade.exhibition')->assertViewHas('presentationUrl', route('exhibitions.arcade.presentation', $game));
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'replay' => 1]))->assertNotFound();
});

test('presentation refuses another owners world without exposing the read model', function () {
    $game = presentationGame();
    $before = $game->fresh()->getAttributes();
    $this->actingAs(\App\Models\User::factory()->create());
    $this->getJson(route('exhibitions.arcade.presentation', $game))->assertRedirect(route('worlds.index'));
    $this->getJson(route('exhibitions.arcade.playback', [$game, 1]))->assertRedirect(route('worlds.index'));
    expect($game->fresh()->getAttributes())->toBe($before);
});

test('snapshot HTML carries history labels and links but no historical recordings or animation paths', function () {
    $game = presentationGame();
    $first = app(ExhibitionEngine::class)->resolve($game->state, $game->rosters, 'inside_run', 'man_to_man');
    $second = app(ExhibitionEngine::class)->resolve($first['state'], $game->rosters, 'inside_run', 'man_to_man');
    $old = $first['play'];
    $latest = $second['play'];
    $old['source'] = 'arcade';
    $old['saved_highlight'] = true;
    $old['arcade_recording'] = ['version' => 1, 'frames' => [[0, 0, 0, [], []]], 'marker' => 'OLD_RECORDING_SHOULD_NOT_BE_EMBEDDED'];
    $old['animation']['recording'] = $old['arcade_recording'];
    $old['animation']['audit_marker'] = 'OLD_ANIMATION_SHOULD_NOT_BE_EMBEDDED';
    $latest['source'] = 'arcade';
    $latest['arcade_recording'] = ['version' => 1, 'frames' => [[0, 0, 0, [], []]], 'marker' => 'LATEST_RECORDING_SHOULD_BE_ON_DEMAND'];
    $latest['animation']['recording'] = $latest['arcade_recording'];
    $game->update(['state' => $second['state'], 'history' => [$old, $latest]]);
    $game->refresh();
    $before = $game->getAttributes();
    $response = $this->getJson(route('exhibitions.arcade.presentation', $game))->assertOk();
    expect($response->getContent())->not->toContain('OLD_RECORDING_SHOULD_NOT_BE_EMBEDDED', 'OLD_ANIMATION_SHOULD_NOT_BE_EMBEDDED', 'LATEST_RECORDING_SHOULD_BE_ON_DEMAND');
    $html = $response->json('controls_html');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $root = $xpath->query('//*[@data-practice]')->item(0);
    $animation = json_decode($root->getAttribute('data-animation'), true, 512, JSON_THROW_ON_ERROR);
    expect($animation)->not->toHaveKey('recording')->and($animation)->toBe($response->json('scene.animation'));
    expect($html)->toContain('Watch highlight', 'Replay Arcade recording')->and($response->json('urls.playback_base').'/'.$old['number'])->toBe(route('exhibitions.arcade.playback', [$game, $old['number']]));
    $this->getJson(route('exhibitions.arcade.playback', [$game, $old['number']]))->assertOk()->assertJsonPath('recording', $old['arcade_recording']);
    expect($game->fresh()->getAttributes())->toBe($before);
});

test('historical Coach fragment uses selected play and cannot expose current decisions', function () {
    $game = presentationGame();
    $result = app(ExhibitionEngine::class)->resolve($game->state, $game->rosters, 'inside_run', 'man_to_man');
    $old = $result['play'];
    $current = $result['state'];
    $current['quarter'] = 5;
    $current['clock'] = 600;
    $current['phase'] = 'kickoff';
    $current['version'] = 12;
    $current['rules']['overtime'] = 'modern';
    $current['overtime']['toss'] = ['call_pending' => true, 'pending' => false];
    $current['penalty_pending'] = true;
    $game->update(['state' => $current, 'history' => [$old]]);
    $game->refresh();
    $before = $game->getAttributes();
    $old = $game->history[0];
    $response = $this->getJson(route('exhibitions.arcade.playback', [$game, $old['number']]))->assertOk()->assertJsonPath('replay_only', true)->assertJsonPath('before', $old['before'])->assertJsonPath('after', $old['after']);
    $html = $response->json('controls_html');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $root = $xpath->query('//*[@data-practice]')->item(0);
    expect(json_decode($root->getAttribute('data-before-state'), true))->toBe($old['before'])->and(json_decode($root->getAttribute('data-after-state'), true))->toBe($old['after']);
    expect($xpath->query('//form[translate(@method,"post","POST")="POST"]')->length)->toBe(0)->and($html)->not->toContain('data-ot-dialog', 'data-coin-dialog', 'data-penalty-form', 'data-call-form')->and($html)->toContain('game-replay-return');
    expect($game->fresh()->getAttributes())->toBe($before);
});

test('presentation and box score stream history while preserving display metadata and selected replay', function () {
    $game = presentationGame();
    $first = app(ExhibitionEngine::class)->resolve($game->state, $game->rosters, 'inside_run', 'man_to_man');
    $second = app(ExhibitionEngine::class)->resolve($first['state'], $game->rosters, 'inside_run', 'man_to_man');
    $old = $first['play'];
    $old['source'] = 'arcade';
    $old['saved_highlight'] = true;
    $old['arcade_recording'] = ['version' => 1, 'marker' => 'exact embedded historical recording', 'frames' => [[0, 0, 0, [], []]]];
    $old['animation']['recording'] = $old['arcade_recording'];
    $history = [$old, $second['play']];
    $game->update(['state' => $second['state'], 'history' => $history]);
    $game->refresh();
    $history = $game->history;
    $old = $history[0];
    $expectedBox = app(\App\Services\Simulation\ExhibitionBoxScore::class)->build($game);
    $streamed = new class extends Exhibition
    {
        protected $table = 'exhibitions';

        public function getForeignKey()
        {
            return 'exhibition_id';
        }

        public function getAttribute($key)
        {
            if ($key === 'history') {
                throw new LogicException('Presentation must not decode the whole history cast.');
            }

            return parent::getAttribute($key);
        }
    };
    $streamed->setRawAttributes($game->getAttributes(), true);
    $streamed->exists = true;
    $data = app(ExhibitionPresentation::class)->viewData($streamed);
    expect($data['historyCount'])->toBe(2)->and($data['last'])->toBe($history[1])
        ->and($data['boxScore'])->toBe($expectedBox)
        ->and($data['historyLog'][0])->toEqual([
            'number' => $old['number'], 'summary' => $old['summary'], 'source' => 'arcade',
            'before' => \Illuminate\Support\Arr::only($old['before'], ['quarter', 'clock', 'possession']),
            'has_recording' => true, 'has_animation' => true, 'highlight' => true,
        ])->and($data['highlights'][0]['reasons'])->toBe(\App\Support\PlayHighlights::reasons($old));
    $expectedBreaks = collect($history)->map(function ($play) {
        $item = \Illuminate\Support\Arr::only($play, ['number', 'call', 'outcome', 'clock_seconds', 'no_snap', 'gain', 'penalty']);
        foreach (['before', 'after'] as $when) {
            $item[$when] = \Illuminate\Support\Arr::only($play[$when] ?? [], ['stats', 'quarter', 'clock', 'phase', 'possession', 'status', 'home_score', 'away_score', 'penalty_pending', 'down', 'distance']);
        }

        return $item;
    })->all();
    expect($data['breakHistory'])->toBe($expectedBreaks)
        ->and(json_encode([$data['historyLog'], $data['highlights'], $data['breakHistory']]))->not->toContain('exact embedded historical recording');
    $replay = app(ExhibitionPresentation::class)->viewData($streamed, $old['number'], false, true);
    expect($replay['last'])->toBe($old)->and($replay['animation']['recording'])->toBe($old['arcade_recording']);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Replay Arcade recording');
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'replay' => $old['number']]))->assertOk();
    $this->get(route('exhibitions.arcade.show', ['exhibition' => $game, 'play' => 1]))->assertOk()->assertViewHas('lastRecording', $old['arcade_recording']);
});
