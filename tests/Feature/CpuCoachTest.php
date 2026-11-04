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
    $kick = $coach->offense(array_merge($state, ['phase' => 'kickoff']), cpuRosters());
    expect($kick['call'])->toBeIn(['kickoff', 'kickoff_deep', 'kickoff_high', 'kickoff_short', 'kickoff_left', 'kickoff_right'])
        ->and($kick)->toBe($coach->offense(array_merge($state, ['phase' => 'kickoff']), cpuRosters()));
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
    foreach (['punt' => 'punt_return', 'kickoff' => 'kickoff_return'] as $call => $family) {
        $choice = $coach->defense($state, $call)['call'];
        expect($choice)->toBeIn($family === 'punt_return'
            ? ['punt_return', 'punt_return_left', 'punt_return_middle', 'punt_return_right', 'punt_safe', 'punt_block']
            : ['kickoff_return', 'kickoff_return_left', 'kickoff_return_middle', 'kickoff_return_right', 'kickoff_return_safe', 'kickoff_return_aggressive']);
        expect($coach->defense($state, $call)['call'])->toBe($choice);
    }
    foreach (['field_goal', 'extra_point'] as $call) {
        expect($coach->defense($state, $call)['call'])->toBe('field_goal_block');
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
    expect($game->history[0]['call'])->toBe('kickoff')
        ->and($game->history[0]['defense'])->toBeIn($defenseControl === 'cpu'
            ? ['kickoff_return', 'kickoff_return_left', 'kickoff_return_middle', 'kickoff_return_right', 'kickoff_return_safe', 'kickoff_return_aggressive']
            : ['kickoff_return'])
        ->and($game->history[0]['design'] ?? 'kickoff')->toBeIn($offenseControl === 'cpu'
            ? ['kickoff', 'kickoff_deep', 'kickoff_high', 'kickoff_short', 'kickoff_left', 'kickoff_right']
            : ['kickoff']);
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
    expect(collect($game->history)->contains(fn ($play) => ($play['motion'] ?? 'none') !== 'none'))->toBeTrue();
    $this->get(route('exhibitions.show', $game))->assertOk()->assertDontSee('data-call-form', false);
    $this->post(route('exhibitions.play', $game), ['version' => $game->state['version']])->assertStatus(409);
});

test('CPU motion and coin choices follow their target rates and remain deterministic', function () {
    $coach = app(CpuCoach::class);
    $motion = $kicks = 0;
    foreach (range(1, 2000) as $seed) {
        $state = app(ExhibitionEngine::class)->initial(180, $seed, false);
        $plan = $coach->offense($state, cpuRosters());
        expect($plan)->toBe($coach->offense($state, cpuRosters()));
        $motion += $plan['motion'] !== 'none' ? 1 : 0;
        $kicks += $coach->coinChoice($state) === 'kick' ? 1 : 0;
        expect($coach->offense(array_merge($state, ['phase' => 'kickoff']), cpuRosters())['motion'] ?? 'none')->toBe('none');
    }
    expect($motion / 2000)->toBeBetween(.60, .68);
    expect($kicks / 2000)->toBeBetween(.76, .84);
});

test('CPU mixes short routes on early downs and favors longer routes on conversion downs', function () {
    $engine = app(ExhibitionEngine::class);
    $coach = app(CpuCoach::class);
    $counts = [];
    foreach ([1, 2, 3] as $down) {
        $counts[$down] = array_fill_keys(['short_pass', 'slant', 'medium_pass', 'deep_pass'], 0);
        foreach (range(1, 600) as $seed) {
            $state = array_merge($engine->initial(180, $seed, false), ['down' => $down, 'distance' => 10, 'spot' => 25]);
            $decision = $coach->offense($state, cpuRosters());
            expect($decision)->toBe($coach->offense($state, cpuRosters()));
            if (isset($counts[$down][$decision['call']])) {
                $counts[$down][$decision['call']]++;
            }
        }
        foreach ($counts[$down] as $count) {
            expect($count)->toBeGreaterThan(0);
        }
    }
    foreach ([1, 2] as $earlyDown) {
        $earlyShort = ($counts[$earlyDown]['short_pass'] + $counts[$earlyDown]['slant']) / array_sum($counts[$earlyDown]);
        $conversionShort = ($counts[3]['short_pass'] + $counts[3]['slant']) / array_sum($counts[3]);
        expect($earlyShort)->toBeGreaterThan($conversionShort);
    }
});

test('CPU hurry up favors passes and keeps the formation already on the field', function () {
    $coach = app(CpuCoach::class);
    $runs = 0;
    foreach (range(1, 300) as $seed) {
        $state = array_replace(app(ExhibitionEngine::class)->initial(900, $seed, false), ['quarter' => 4, 'clock' => 90, 'home_score' => 0, 'away_score' => 7]);
        $state['on_field'] = ['offense_formation' => 'singleback', 'defense_formation' => 'nickel', 'players' => []];
        $offense = $coach->offense($state, cpuRosters());
        expect($offense['formation'])->toBe('singleback')->and(in_array($offense['call'], \App\Services\Simulation\OffensiveOptions::FORMATIONS['singleback'], true))->toBeTrue();
        $runs += (int) in_array(\App\Services\Simulation\OffensiveOptions::canonical($offense['call']), ['inside_run', 'outside_run'], true);
    }
    expect($runs)->toBeLessThan(40);
});

test('CPU victory decisions account for running clocks downs timeouts warnings and safety risk', function () {
    $coach = app(CpuCoach::class);
    $base = array_replace(app(ExhibitionEngine::class)->initial(900, 42, false), [
        'quarter' => 4, 'home_score' => 21, 'away_score' => 17, 'spot' => 50,
        'timeouts' => ['home' => 3, 'away' => 0], 'warnings' => ['4' => true],
    ]);
    foreach ([
        [1, false, 0, 120, true], [1, false, 0, 121, false],
        [1, true, 0, 158, true], [1, true, 0, 159, false],
        [2, false, 0, 80, true], [2, false, 0, 81, false],
        [3, false, 0, 40, true], [3, false, 0, 41, false],
        [4, true, 0, 38, true], [4, true, 0, 39, false], [4, false, 0, 1, false],
        [1, false, 1, 82, true], [1, false, 1, 83, false],
        [1, false, 2, 44, true], [1, false, 2, 45, false],
        [1, false, 3, 6, true], [1, false, 3, 7, false],
    ] as [$down, $running, $timeouts, $clock, $kneel]) {
        $state = array_replace($base, ['down' => $down, 'clock_running' => $running, 'clock' => $clock, 'timeouts' => ['home' => 3, 'away' => $timeouts]]);
        expect($coach->offense($state, cpuRosters())['call'] === 'kneel')->toBe($kneel);
    }
    $state = array_replace($base, ['clock' => 150, 'clock_running' => true, 'warnings' => ['4' => false]]);
    expect($coach->offense($state, cpuRosters())['call'])->toBe('kneel');
    $state['clock'] = 160;
    expect($coach->offense($state, cpuRosters())['call'])->not->toBe('kneel');
    foreach ([['spot' => 3, 'clock' => 100], ['spot' => 1, 'clock' => 1], ['untimed_down' => true, 'clock' => 1], ['quarter' => 3, 'clock' => 1], ['home_score' => 17, 'clock' => 1], ['home_score' => 10, 'clock' => 1], ['quarter' => 5, 'clock' => 1, 'rules' => ['overtime' => 'modern_playoff']]] as $changes) {
        expect($coach->offense(array_replace($base, $changes), cpuRosters())['call'])->not->toBe('kneel');
    }
    expect($coach->offense(array_replace($base, ['clock' => 150, 'clock_restart_on_ready' => true]), cpuRosters())['call'])->toBe('kneel');
});

test('CPU protects late leads with inside runs regardless of coach pass bias or random seed', function () {
    $coach = app(CpuCoach::class);
    foreach (['home', 'away'] as $side) {
        foreach (range(1, 100) as $seed) {
            $state = array_replace(app(ExhibitionEngine::class)->initial(900, $seed, false), [
                'quarter' => 4, 'clock' => 175, 'possession' => $side, $side.'_score' => 7,
                'coaching' => [$side => ['pass' => 100, 'qb_run' => 100, 'tempo' => 100, 'fourth_down' => 100]],
            ]);
            foreach ([1, 2, 3] as $down) {
                $state['down'] = $down;
                $plan = $coach->offense($state, cpuRosters());
                expect($plan['call'])->toBeIn(['inside_run', 'inside_zone', 'power', 'lead'])
                    ->and($plan)->toBe($coach->offense($state, cpuRosters()))
                    ->and($coach->management($state))->toBe(['tempo' => 'drain', 'clock_strategy' => 'normal']);
                expect(\App\Services\Simulation\OffensiveOptions::FORMATIONS[$plan['formation']])->toContain($plan['call']);
            }
            $state['down'] = 4;
            $state['spot'] = 65;
            $state['distance'] = 1;
            expect($coach->offense($state, cpuRosters())['call'])->toBeIn(['punt', 'punt_coffin']);
            $state['spot'] = 90;
            expect($coach->offense($state, cpuRosters())['call'])->toBe('field_goal');
        }
    }
});
