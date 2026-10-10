<?php

use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Player;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\PlayerRatings;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
    $save = World::create(['name' => 'Engine']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $save->id]);
    openFootballSave($save);
    app(CurrentWorld::class)->id = $save->id;
    $this->artisan('world:seed-demo', ['world' => $save->id, '--teams' => 2])->assertSuccessful();
});

test('exhibition starts from real rosters and duplicate snaps do not advance twice', function () {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($game->rosters['home']['players'])->toHaveCount(28);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Call play')->assertSee('data-animation', false);
    $this->post(route('exhibitions.play', $game), ['call' => 'kickoff', 'defense' => 'kickoff_return', 'version' => 0])->assertRedirect();
    $this->post(route('exhibitions.play', $game), ['call' => 'kickoff', 'defense' => 'kickoff_return', 'version' => 0])->assertStatus(409);
    $game->refresh();
    expect($game->state['version'])->toBe(1)->and($game->history)->toHaveCount(1);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Last play:')->assertSee('QB kneel')->assertSee('Stadium sound')->assertSee('data-sound-volume="crowd"', false);
    $this->post(route('exhibitions.play', $game), ['call' => 'made_up', 'defense' => 'zone', 'version' => 1])->assertSessionHasErrors('call');
});

test('ratings edits are validated persisted and do not change games already started', function () {
    $teams = Team::all();
    $player = $teams[0]->players()->first();
    $foreign = $teams[1]->players()->first();
    $this->get(route('simulation-ratings.edit', $teams[0]))->assertOk()->assertSee('Engine ratings');
    $ratings = array_fill_keys(PlayerRatings::FIELDS, 90);
    $this->put(route('simulation-ratings.update', $teams[0]), ['ratings' => [$player->id => $ratings]])->assertRedirect();
    expect(Player::withoutGlobalScopes()->findOrFail($player->id)->simulation_ratings)->toBe($ratings);
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $snapshot = $game->rosters;
    $this->put(route('simulation-ratings.update', $teams[0]), ['ratings' => [$player->id => array_fill_keys(PlayerRatings::FIELDS, 99)]])->assertRedirect();
    expect($game->fresh()->rosters)->toBe($snapshot);
    $this->put(route('simulation-ratings.update', $teams[0]), ['ratings' => [$player->id => array_merge($ratings, ['speed' => 100])]])->assertSessionHasErrors('ratings.'.$player->id.'.speed');
    $this->put(route('simulation-ratings.update', $teams[0]), ['ratings' => [$foreign->id => $ratings]])->assertNotFound();
});

test('exhibitions reject same team incomplete rosters and records from other saves', function () {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[0]->id, 'quarter_length' => 180])->assertSessionHasErrors('away');
    app(CurrentWorld::class)->id = LocalSetting::find(1)->current_world_id;
    $empty = Team::create(['city' => 'Empty', 'name' => 'Team']);
    $this->post(route('exhibitions.store'), ['home' => $empty->id, 'away' => $teams[0]->id, 'quarter_length' => 180])->assertSessionHasErrors('teams');
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $other = World::create(['name' => 'Other']);
    LocalSetting::find(1)->update(['current_world_id' => $other->id]);
    openFootballSave($other);
    $this->get(route('exhibitions.show', $game))->assertNotFound();
    $this->post(route('exhibitions.play', $game), ['call' => 'slant', 'defense' => 'man_to_man', 'version' => 0])->assertNotFound();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180])->assertNotFound();
});

test('formation choices persist and quarter halftime and final notices are rendered', function () {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    foreach ([1 => 'End of quarter 1', 2 => 'Halftime', 4 => 'Final whistle'] as $quarter => $heading) {
        app(CurrentWorld::class)->id = $game->world_id;
        $state = $game->state;
        $state['quarter'] = $quarter;
        $state['clock'] = 1;
        // This test targets formation persistence and quarter notices, not
        // the pre-snap clock runoff. Ensure the requested play actually snaps.
        $state['clock_running'] = false;
        $state['clock_restart_on_ready'] = false;
        $state['status'] = 'playing';
        $state['phase'] = 'scrimmage';
        $state['rules']['penalties'] = false;
        $game->update(['state' => $state]);
        $this->post(route('exhibitions.play', $game), ['call' => 'medium_pass', 'defense' => 'zone', 'version' => $state['version'],
            'offense_formation' => 'spread', 'defense_formation' => 'two_high'])->assertRedirect();
        $game->refresh();
        $this->get(route('exhibitions.show', $game))->assertOk()->assertSee($heading)->assertSee('data-quarter-dialog', false);
        $play = collect($game->history)->last();
        expect($play['offense_formation'])->toBe('spread')->and($play['defense_formation'])->toBe('two_high');
    }
    $this->post(route('exhibitions.play', $game), ['call' => 'short_pass', 'defense' => 'zone', 'version' => $game->state['version'], 'offense_formation' => 'not-real'])->assertSessionHasErrors('offense_formation');
});

test('watch page renders the pre-play display and hides the new result until revealed', function () {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $this->post(route('exhibitions.play', $game), ['call' => 'slant', 'defense' => 'man_to_man', 'version' => 0])->assertSessionHasErrors('call');
    $this->post(route('exhibitions.play', $game), ['call' => 'kickoff', 'defense' => 'zone', 'version' => 0])->assertSessionHasErrors('defense');
    $this->post(route('exhibitions.play', $game), ['call' => 'kickoff', 'defense' => 'kickoff_return', 'version' => 0])->assertRedirect();
    $response = $this->get(route('exhibitions.show', ['exhibition' => $game, 'watch' => 1]))->assertOk();
    $html = $response->getContent();
    expect($html)->toMatch('/data-situation[^>]*>[^<]*Kickoff<\/p>/')->toContain('data-hidden-result  hidden');
});

test('exhibition list paginates newest summaries without loading replay history or rosters', function () {
    Exhibition::query()->delete();
    $teams = Team::all();
    $ids = [];
    for ($i = 0; $i < 21; $i++) {
        $ids[] = Exhibition::create([
            'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
            'state' => ['home_score' => $i, 'away_score' => 0, 'status' => 'playing', 'quarter' => 1],
            'rosters' => ['large' => str_repeat('x', 10000)], 'history' => [['large' => str_repeat('x', 10000)]],
        ])->id;
    }
    $response = $this->get(route('exhibitions.index'))->assertOk();
    $games = $response->viewData('games');
    expect($games->total())->toBe(21)
        ->and($games->count())->toBe(20)
        ->and($games->first()->id)->toBe($ids[20])
        ->and(array_key_exists('history', $games->first()->getAttributes()))->toBeFalse()
        ->and(array_key_exists('rosters', $games->first()->getAttributes()))->toBeFalse();
    $second = $this->get(route('exhibitions.index', ['page' => 2]))->assertOk()->viewData('games');
    expect($second->count())->toBe(1)->and($second->first()->id)->toBe($ids[0]);
});

test('crowd settings persist through plays and invalid percentages are rejected', function () {
    $teams = Team::all();
    $data = ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'crowd_fullness' => 63, 'visiting_fans' => 17];
    $this->post(route('exhibitions.store'), array_merge($data, ['crowd_fullness' => 101, 'visiting_fans' => -1]))->assertSessionHasErrors(['crowd_fullness', 'visiting_fans']);
    $this->post(route('exhibitions.store'), $data)->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $crowd = $game->state['crowd'];
    expect($crowd['fullness'])->toBe(63)->and($crowd['visitors'])->toBe(17)->and($crowd['seed'])->toBeGreaterThan(0);
    $this->post(route('exhibitions.play', $game), ['call' => 'kickoff', 'defense' => 'kickoff_return', 'version' => 0])->assertRedirect();
    expect($game->fresh()->state['crowd'])->toBe($crowd);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('data-crowd=', false);
});

test('visitor coin call lets the winner choose and halftime reverses receiving teams', function (string $choice) {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'coin_call' => 'tails'])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $state = $game->state;
    $winner = $state['coin_toss']['result'] === 'tails' ? 'away' : 'home';
    expect($state['coin_toss']['call'])->toBe('tails')->and($state['opening_receiver'])->toBe($winner)
        ->and($state['possession'])->toBe($winner === 'home' ? 'away' : 'home');
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('COIN TOSS');
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'call' => 'kickoff', 'defense' => 'kickoff_return'])->assertStatus(409);
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'action' => 'coin', 'choice' => $choice])->assertRedirect();
    $state = $game->fresh()->state;
    $receiver = $choice === 'receive' ? $winner : ($winner === 'home' ? 'away' : 'home');
    expect($state['coin_toss']['pending'])->toBeFalse()->and($state['opening_receiver'])->toBe($receiver)
        ->and($state['possession'])->toBe($receiver === 'home' ? 'away' : 'home');
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'action' => 'coin', 'choice' => 'receive'])->assertStatus(409);
    $state['quarter'] = 2;
    $state['clock'] = 1;
    $state['phase'] = 'scrimmage';
    $play = ['outcome' => 'tackle', 'summary' => 'Tackle'];
    $next = app(\App\Services\Simulation\GameClock::class)->advance($state, $state, $play, 1);
    expect($next['quarter'])->toBe(3)->and($next['phase'])->toBe('kickoff')->and($next['possession'])->toBe($receiver);
})->with(['kick', 'receive']);

test('human game lineup overrides use captured backups without changing permanent rosters', function () {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'away_control' => 'cpu'])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $original = $game->rosters;
    $starter = $original['home']['players']['QB']['id'];
    $backup = collect($original['home']['pool'])->first(fn ($p) => $p['position'] === 'QB' && $p['id'] !== $starter);
    expect($backup)->not->toBeNull();
    $request = ['action' => 'lineup', 'version' => 0, 'team' => 'home', 'role' => 'QB', 'player' => $backup['id']];
    $this->post(route('exhibitions.play', $game), $request)->assertRedirect();
    $game->refresh();
    expect($game->rosters)->toBe($original)->and($game->history)->toBe([])->and($game->state['version'])->toBe(0);
    $active = app(\App\Services\Simulation\GamePersonnel::class)->active($game->rosters, $game->state);
    expect($active['home']['players']['QB']['id'])->toBe($backup['id']);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Set QB');
    $this->post(route('exhibitions.play', $game), array_merge($request, ['team' => 'away']))->assertStatus(422);
    $wrong = collect($original['home']['pool'])->first(fn ($p) => $p['position'] !== 'QB');
    $this->post(route('exhibitions.play', $game), array_merge($request, ['player' => $wrong['id']]))->assertStatus(422);
    $state = $game->state;
    $state['injuries']['home'][$backup['id']] = ['return_snap' => null];
    app(CurrentWorld::class)->id = (int) $game->world_id;
    $game->update(['state' => $state]);
    $this->post(route('exhibitions.play', $game), $request)->assertStatus(422);
    expect(app(\App\Services\Simulation\GamePersonnel::class)->active($original, $state)['home']['players']['QB']['id'])->toBe($starter);
    $this->post(route('exhibitions.play', $game), array_merge($request, ['player' => '']))->assertRedirect();
    expect($game->fresh()->state['game_lineup']['home'])->toBe([]);
});

test('saved animations can be replayed and bookmarked without advancing or changing the game', function () {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $this->post(route('exhibitions.play', $game), ['call' => 'kickoff', 'defense' => 'kickoff_return', 'version' => 0])->assertRedirect();
    $game->refresh();
    $state = $game->state;
    $animation = $game->history[0]['animation'];
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'replay' => 1, 'watch' => 1]))
        ->assertOk()->assertSee('Return to game')->assertDontSee('data-call-form', false)->assertDontSee('data-quarter-dialog', false);
    $this->post(route('exhibitions.highlights.save', $game), ['number' => 1])->assertRedirect();
    $game->refresh();
    expect($game->state)->toBe($state)->and($game->history[0]['animation'])->toBe($animation)
        ->and($game->history[0]['saved_highlight'])->toBeTrue();
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Saved by you')->assertSee('Watch highlight');
    $this->post(route('exhibitions.highlights.save', $game), ['number' => 1])->assertRedirect();
    expect($game->fresh()->history)->toHaveCount(1);
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'replay' => 999]))->assertNotFound();
    $this->post(route('exhibitions.highlights.save', $game), ['number' => 999])->assertNotFound();
});

test('quick sim finishes games with CPU coaches and retains all box score and replay data', function (int $quarterLength) {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), [
        'home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => $quarterLength,
        'home_control' => 'human', 'away_control' => 'human', 'coin_call' => 'heads',
        'quick_sim' => 1, 'penalties' => 1, 'injuries' => 1,
    ])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($game->state['status'])->toBe('final')->and($game->state['quarter'])->toBe(4)
        ->and($game->state['clock'])->toBe(0)->and($game->state['quick_sim'])->toBeTrue()
        ->and($game->state['controls'])->toBe(['home' => 'cpu', 'away' => 'cpu'])
        ->and($game->state['coin_toss']['pending'])->toBeFalse()
        ->and(count($game->history))->toBe($game->state['version']);
    foreach ($game->history as $play) {
        expect($play)->toHaveKeys(['animation', 'before', 'after', 'summary']);
    }
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'summary' => 1]))->assertOk()
        ->assertSee('data-summary="true"', false)->assertSee('Box score')->assertSee('Highlights')
        ->assertDontSee('data-call-form', false)->assertDontSee('data-quarter-dialog', false);
    $this->get(route('exhibitions.show', ['exhibition' => $game, 'replay' => 1, 'watch' => 1]))->assertOk();
})->with([180, 300, 600, 900]);

test('human overtime visitor calls a new toss and its winner chooses without advancing plays', function () {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'overtime' => 'modern'])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $state = array_replace($game->state, ['quarter' => 4, 'clock' => 1, 'phase' => 'scrimmage', 'possession' => 'home', 'spot' => 50]);
    $state['rules']['penalties'] = false;
    app(CurrentWorld::class)->id = (int) $game->world_id;
    $game->update(['state' => $state]);
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'call' => 'kneel', 'defense' => 'man_to_man'])->assertRedirect();
    $game->refresh();
    expect($game->state['quarter'])->toBe(5)->and($game->state['overtime']['toss']['call_pending'])->toBeTrue();
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('OVERTIME')->assertSee('data-ot-dialog', false);
    $this->post(route('exhibitions.play', $game), ['version' => 1, 'call' => 'kickoff', 'defense' => 'kickoff_return'])->assertStatus(409);
    $this->post(route('exhibitions.play', $game), ['version' => 1, 'action' => 'ot_call', 'toss_call' => $game->state['overtime']['toss']['result']])->assertRedirect();
    $game->refresh();
    expect($game->state['overtime']['toss']['winner'])->toBe('away');
    $this->post(route('exhibitions.play', $game), ['version' => 1, 'action' => 'ot_choice', 'choice' => 'kick'])->assertRedirect();
    $game->refresh();
    expect($game->state['overtime']['receiver'])->toBe('home')->and($game->state['possession'])->toBe('away')
        ->and($game->state['version'])->toBe(1)->and($game->state['timeouts'])->toBe(['home' => 2, 'away' => 2]);
    $this->post(route('exhibitions.play', $game), ['version' => 1, 'action' => 'ot_choice', 'choice' => 'receive'])->assertStatus(409);
});

test('quick simulator completes all overtime modes using real captured game rosters', function (string $mode) {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'overtime' => $mode])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $state = array_replace($game->state, ['quarter' => 4, 'clock' => 1, 'phase' => 'scrimmage', 'possession' => 'home', 'spot' => 20]);
    $state['rules']['penalties'] = false;
    $state['rules']['injuries'] = false;
    $result = app(\App\Services\Simulation\QuickSimulator::class)->run($state, $game->rosters);
    expect($result['state']['status'])->toBe('final')->and($result['state']['quarter'])->toBeGreaterThanOrEqual(5);
    expect(collect($result['history'])->contains(fn ($play) => $play['before']['quarter'] >= 5))->toBeTrue();
    if (str_ends_with($mode, '_playoff')) {
        expect($result['state']['home_score'])->not->toBe($result['state']['away_score']);
    }
})->with(['traditional', 'modern', 'traditional_playoff', 'modern_playoff']);

test('player appearance is captured in games and saved animation tracks', function () {
    $appearance = ['hair' => 'short', 'hair_color' => '#332211', 'eye_color' => '#2266aa', 'beard' => 'stubble'];
    Player::all()->each(fn ($player) => $player->update(['appearance' => $appearance]));
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($game->rosters['home']['players']['QB']['appearance'])->toBe($appearance);
    Player::all()->each(fn ($player) => $player->update(['appearance' => ['hair' => 'bald']]));
    $this->post(route('exhibitions.play', $game), ['call' => 'kickoff', 'defense' => 'kickoff_return', 'version' => 0])->assertRedirect();
    $game->refresh();
    expect($game->rosters['home']['players']['QB']['appearance'])->toBe($appearance);
    foreach ($game->history[0]['animation']['players'] as $track) {
        expect($track['appearance'])->toBe($appearance);
    }
});
