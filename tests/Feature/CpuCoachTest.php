<?php

use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PlayerRatings;
use App\Support\CurrentWorld;

function cpuRosters(): array
{
    $ratings = array_fill_keys(PlayerRatings::FIELDS, 70);
    $players = array_fill_keys(['QB', 'RB', 'WR1', 'C', 'K'], ['ratings' => $ratings]);

    return ['home' => ['players' => $players], 'away' => ['players' => $players]];
}

test('CPU coach handles kicking phases fourth downs red zone and late deficits deterministically', function () {
    $engine = app(ExhibitionEngine::class);
    $coach = app(CpuCoach::class);
    $state = $engine->initial(180, 42, false);
    expect($coach->controls($state))->toBe(['home' => 'human', 'away' => 'human']);
    expect($coach->offense(array_merge($state, ['phase' => 'kickoff']), cpuRosters())['call'])->toBe('kickoff');
    expect($coach->offense(array_merge($state, ['phase' => 'extra_point']), cpuRosters())['call'])->toBe('extra_point');
    expect($coach->offense(array_merge($state, ['down' => 4, 'spot' => 25]), cpuRosters())['call'])->toBe('punt');
    expect($coach->offense(array_merge($state, ['down' => 4, 'spot' => 90]), cpuRosters())['call'])->toBe('field_goal');
    $late = array_merge($state, ['down' => 4, 'quarter' => 4, 'clock' => 20, 'away_score' => 14]);
    expect($coach->offense($late, cpuRosters())['call'])->not->toBeIn(['punt', 'field_goal']);
    foreach (range(1, 100) as $seed) {
        $red = array_merge($state, ['spot' => 85, 'distance' => 20, 'seed' => $seed]);
        $decision = $coach->offense($red, cpuRosters());
        expect($decision)->toBe($coach->offense($red, cpuRosters()));
        expect($decision['call'])->not->toBe('deep_pass');
        expect(array_keys(ExhibitionEngine::OFFENSE_FORMATIONS))->toContain($decision['formation']);
        expect($coach->defense($red, 'inside_run'))->toBe($coach->defense($red, 'medium_pass'));
    }
    foreach (['punt' => 'punt_return', 'field_goal' => 'field_goal_block', 'extra_point' => 'field_goal_block', 'kickoff' => 'kickoff_return'] as $call => $defense) {
        expect($coach->defense($state, $call)['call'])->toBe($defense);
    }
});

test('all four control combinations persist and only show selectors for human teams', function (string $homeControl, string $awayControl) {
    $this->withoutVite();
    $world = World::create(['name' => 'CPU test']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    app(CurrentWorld::class)->id = $world->id;
    $teams = Team::all();
    $this->get(route('exhibitions.index'))->assertOk()->assertSee('name="home_control"', false)->assertSee('name="away_control"', false);
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'home_control' => $homeControl, 'away_control' => $awayControl])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($game->state['controls'])->toBe(['home' => $homeControl, 'away' => $awayControl]);
    $page = $this->get(route('exhibitions.show', $game))->assertOk();
    $offenseControl = $game->state['controls'][$game->state['possession']];
    $defenseControl = $game->state['controls'][$game->state['possession'] === 'home' ? 'away' : 'home'];
    if ($offenseControl === 'human') {
        $page->assertSee('name="call"', false);
    } else {
        $page->assertDontSee('name="call"', false);
    }
    if ($defenseControl === 'human') {
        $page->assertSee('name="defense"', false);
    } else {
        $page->assertDontSee('name="defense"', false);
    }
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'call' => $offenseControl === 'cpu' ? 'forged' : 'kickoff', 'defense' => $defenseControl === 'cpu' ? 'forged' : 'kickoff_return'])->assertRedirect();
    $game->refresh();
    expect($game->history[0]['call'])->toBe('kickoff')->and($game->history[0]['defense'])->toBe('kickoff_return');
    expect($game->state['controls'])->toBe(['home' => $homeControl, 'away' => $awayControl]);
    $page = $this->get(route('exhibitions.show', $game))->assertOk();
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'call' => 'kickoff', 'defense' => 'kickoff_return'])->assertStatus(409);
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'home_control' => 'robot'])->assertSessionHasErrors('home_control');
})->with([['human', 'human'], ['human', 'cpu'], ['cpu', 'human'], ['cpu', 'cpu']]);

test('CPU versus CPU can finish a saved game without human calls and retains ratings', function () {
    $this->withoutVite();
    $world = World::create(['name' => 'CPU season']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    app(CurrentWorld::class)->id = $world->id;
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180, 'home_control' => 'cpu', 'away_control' => 'cpu'])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $rosters = $game->rosters;
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Start CPU game');
    for ($i = 0; $i < 300 && $game->state['status'] === 'playing'; $i++) {
        $this->post(route('exhibitions.play', $game), ['version' => $game->state['version']])->assertRedirect();
        $game->refresh();
    }
    expect($game->state['status'])->toBe('final')->and($game->rosters)->toBe($rosters);
    expect(count($game->history))->toBe($game->state['version']);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertDontSee('data-call-form', false);
    $this->post(route('exhibitions.play', $game), ['version' => $game->state['version']])->assertStatus(409);
});
