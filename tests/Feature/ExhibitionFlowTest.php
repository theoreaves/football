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

test('visitor coin call determines the opening receiver and halftime reverses receiving teams', function () {
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'coin_call' => 'tails'])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $state = $game->state;
    $winner = $state['coin_toss']['result'] === 'tails' ? 'away' : 'home';
    expect($state['coin_toss']['call'])->toBe('tails')->and($state['opening_receiver'])->toBe($winner)
        ->and($state['possession'])->toBe($winner === 'home' ? 'away' : 'home');
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('COIN TOSS');
    $state['quarter'] = 2;
    $state['clock'] = 1;
    $state['phase'] = 'scrimmage';
    $play = ['outcome' => 'tackle', 'summary' => 'Tackle'];
    $next = app(\App\Services\Simulation\GameClock::class)->advance($state, $state, $play, 1);
    expect($next['quarter'])->toBe(3)->and($next['phase'])->toBe('kickoff')->and($next['possession'])->toBe($winner);
});
