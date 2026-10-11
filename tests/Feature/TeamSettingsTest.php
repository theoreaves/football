<?php

use App\Http\Controllers\PracticeController;
use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PlayerRatings;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
    $world = World::create(['name' => 'Team settings']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
    app(CurrentWorld::class)->id = $world->id;
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    app(CurrentWorld::class)->id = $world->id;
});

test('team settings tabs save validated profiles and reject out of range coaching preferences', function () {
    $team = Team::first();
    $this->get(route('teams.editor.edit', $team))->assertOk()->assertSee('data-team-panel="uniforms"', false)->assertSee('data-team-panel="coaching"', false)->assertSee('data-team-panel="mascot"', false);
    $settings = ['white_at_home' => 1, 'coaching' => ['pass' => 85, 'blitz' => 20], 'staff' => ['head' => ['name' => 'Theo', 'skin' => '#593b2c', 'hair' => 'short', 'build' => 'stocky']], 'cheerleaders' => ['primary' => '#123456'], 'mascot' => ['name' => 'Chief', 'character' => 'bear']];
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => $settings])->assertRedirect()->assertSessionHasNoErrors();
    expect($team->fresh()->team_settings['coaching']['pass'])->toBe(85)->and($team->fresh()->team_settings['staff']['head']['name'])->toBe('Theo');
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => ['white_at_home' => 0]])->assertRedirect();
    expect($team->fresh()->team_settings['staff']['head']['name'])->toBe('Theo');
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => ['coaching' => ['pass' => 101]]])->assertSessionHasErrors('team_settings.coaching.pass');
});

test('white at home swaps both kits and new games snapshot coaching and presentation', function () {
    [$home, $away] = Team::all()->all();
    $home->update(['uniform_home_shirt' => '#112233', 'uniform_away_shirt' => '#ffffff', 'team_settings' => ['white_at_home' => true, 'coaching' => ['pass' => 90], 'staff' => ['head' => ['name' => 'Head']]]]);
    $away->update(['uniform_home_shirt' => '#445566', 'uniform_away_shirt' => '#eeeeee', 'team_settings' => ['white_at_home' => true, 'coaching' => ['blitz' => 80]]]);
    $this->get(route('practice', ['home' => $home->id, 'away' => $away->id]))->assertOk()->assertViewHas('appearance', fn ($a) => $a['home']['uniform']['shirt'] === '#ffffff' && $a['away']['uniform']['shirt'] === '#445566');
    $this->post(route('exhibitions.store'), ['home' => $home->id, 'away' => $away->id, 'quarter_length' => 180])->assertRedirect()->assertSessionHasNoErrors();
    // Request middleware clears CurrentWorld after each HTTP response.
    app(CurrentWorld::class)->id = (int) $home->world_id;
    $game = Exhibition::latest('id')->firstOrFail();
    expect($game->state['coaching']['home']['pass'])->toBe(90)->and($game->state['coaching']['away']['blitz'])->toBe(80);
    $home->update(['uniform_away_shirt' => '#ff0000', 'team_settings' => ['white_at_home' => false, 'coaching' => ['pass' => 10]]]);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertViewHas('appearance', fn ($a) => $a['home']['uniform']['shirt'] === '#ffffff');
    app(CurrentWorld::class)->id = (int) $home->world_id;
    expect($game->fresh()->state['coaching']['home']['pass'])->toBe(90);
    $normal = app(PracticeController::class)->matchup($home->fresh(), $away);
    expect($normal['home']['uniform']['shirt'])->toBe('#112233')->and($normal['away']['uniform']['shirt'])->toBe('#eeeeee');
});

test('coaching tendencies change deterministic distributions while neutral and late clock decisions remain intact', function () {
    $coach = app(CpuCoach::class);
    $ratings = array_fill_keys(PlayerRatings::FIELDS, 70);
    $players = array_fill_keys(['QB', 'RB', 'WR1', 'C', 'K'], ['ratings' => $ratings]);
    $rosters = ['home' => ['players' => $players], 'away' => ['players' => $players]];
    $counts = ['run' => 0, 'pass' => 0, 'blitz_low' => 0, 'blitz_high' => 0, 'zone' => 0, 'man' => 0, 'short' => 0, 'deep' => 0, 'go_low' => 0, 'go_high' => 0, 'run_stop_low' => 0, 'run_stop_high' => 0, 'tempo_low' => 0, 'tempo_high' => 0];
    for ($seed = 1; $seed <= 300; $seed++) {
        $state = array_merge(app(ExhibitionEngine::class)->initial(900, $seed, false), ['phase' => 'scrimmage', 'possession' => 'home', 'down' => 1, 'distance' => 10, 'spot' => 40, 'quarter' => 1, 'clock' => 600]);
        $neutral = $state;
        $neutral['coaching'] = ['home' => array_fill_keys(array_keys(config('team-settings.coaching')), 50), 'away' => array_fill_keys(array_keys(config('team-settings.coaching')), 50)];
        expect($coach->offense($state, $rosters))->toBe($coach->offense($neutral, $rosters));
        expect($coach->defense($state, 'inside_run'))->toBe($coach->defense($neutral, 'inside_run'));
        foreach (['run' => 0, 'pass' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['home' => ['pass' => $value]]]);
            $decision = $coach->offense($biased, $rosters);
            expect($decision)->toBe($coach->offense($biased, $rosters));
            if (! in_array($decision['call'], ['inside_run', 'outside_run'])) {
                $counts[$label]++;
            }
        }
        foreach (['blitz_low' => 0, 'blitz_high' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['away' => ['blitz' => $value]]]);
            $counts[$label] += $coach->defense($biased, 'inside_run')['call'] === 'blitz' ? 1 : 0;
        }
        foreach (['zone' => 0, 'man' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['away' => ['man' => $value]]]);
            $counts[$label] += $coach->defense($biased, 'inside_run')['call'] === 'man_to_man' ? 1 : 0;
        }
        foreach (['short' => 0, 'deep' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['home' => ['depth' => $value, 'pass' => 100]]]);
            $counts[$label] += $coach->offense($biased, $rosters)['call'] === 'deep_pass' ? 1 : 0;
        }
        foreach (['go_low' => 0, 'go_high' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['down' => 4, 'distance' => 2, 'spot' => 50, 'coaching' => ['home' => ['fourth_down' => $value]]]);
            $counts[$label] += $coach->offense($biased, $rosters)['call'] !== 'punt' ? 1 : 0;
        }
        foreach (['run_stop_low' => 0, 'run_stop_high' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['away' => ['run_stop' => $value]]]);
            $counts[$label] += $coach->defense($biased, 'inside_run')['call'] === 'run_stop' ? 1 : 0;
        }
        foreach (['tempo_low' => 0, 'tempo_high' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['home' => ['tempo' => $value]]]);
            $counts[$label] += $coach->management($biased)['tempo'] === 'hurry' ? 1 : 0;
        }
        $late = array_replace_recursive($state, ['quarter' => 4, 'clock' => 10, 'home_score' => 7, 'timeouts' => ['away' => 0], 'coaching' => ['home' => ['pass' => 100, 'tempo' => 100]]]);
        expect($coach->offense($late, $rosters)['call'])->toBe('kneel');
    }
    expect($counts['pass'])->toBeGreaterThan($counts['run']);
    expect($counts['blitz_high'])->toBeGreaterThan($counts['blitz_low']);
    expect($counts['man'])->toBeGreaterThan($counts['zone']);
    expect($counts['deep'])->toBeGreaterThan($counts['short']);
    expect($counts['go_high'])->toBeGreaterThan($counts['go_low']);
    expect($counts['run_stop_high'])->toBeGreaterThan($counts['run_stop_low']);
    expect($counts['tempo_high'])->toBeGreaterThan($counts['tempo_low']);
});
