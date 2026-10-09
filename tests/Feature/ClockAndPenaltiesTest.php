<?php

use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PenaltyRules;
use App\Services\Simulation\PlayerRatings;
use App\Support\CurrentWorld;

function clockRosters(): array
{
    $rosters = [];
    $roles = ['QB', 'RB', 'WR1', 'WR2', 'WR3', 'TE', 'C', 'LG', 'RG', 'LT', 'RT', 'DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3', 'CB1', 'CB2', 'S1', 'S2', 'K', 'P'];
    foreach (['home', 'away'] as $side) {
        foreach ($roles as $i => $role) {
            $rosters[$side]['players'][$role] = ['id' => $i + 1, 'name' => $side.' '.$role, 'number' => $i + 1, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70)];
        }
    }

    return $rosters;
}

function clockState(array $changes = []): array
{
    return array_merge(app(ExhibitionEngine::class)->initial(900, 42, false), ['rules' => ['penalties' => false]], $changes);
}

test('tempo changes pre-snap runoff without changing the underlying play and cannot snap after expiry', function () {
    $engine = app(ExhibitionEngine::class);
    $state = clockState(['quarter' => 4, 'clock' => 90, 'clock_running' => true]);
    $hurry = $engine->resolve($state, clockRosters(), 'inside_run', 'man_to_man', 'shotgun', 'base_4_3', 'hurry');
    $drain = $engine->resolve($state, clockRosters(), 'inside_run', 'man_to_man', 'shotgun', 'base_4_3', 'drain');
    expect($hurry['play']['gain'])->toBe($drain['play']['gain']);
    expect($hurry['state']['clock'] - $drain['state']['clock'])->toBe(35);
    expect($hurry['play']['runoff_seconds'])->toBe(3)->and($drain['play']['runoff_seconds'])->toBe(38);
    expect($drain['play']['before']['clock'])->toBe(90)->and($drain['play']['snap_clock'])->toBe(52);
    $expired = $engine->resolve(clockState(['quarter' => 4, 'clock' => 20, 'clock_running' => true]), clockRosters(), 'deep_pass', 'man_to_man', 'shotgun', 'base_4_3', 'drain');
    expect($expired['state']['status'])->toBe('final')->and($expired['play']['no_snap'])->toBeTrue();
    expect($expired['state']['stats']['home']['plays'])->toBe(0);
    expect($expired['play']['animation']['no_snap'])->toBeTrue();
});

test('two minute warning interrupts runoff once per half and also stops a play crossing the threshold', function () {
    $engine = app(ExhibitionEngine::class);
    foreach ([2, 4] as $quarter) {
        $state = clockState(['quarter' => $quarter, 'clock' => 130, 'clock_running' => true]);
        $warning = $engine->resolve($state, clockRosters(), 'inside_run', 'man_to_man');
        expect($warning['state']['clock'])->toBe(120)->and($warning['state']['warnings'][$quarter])->toBeTrue();
        expect($warning['play']['two_minute_warning'])->toBeTrue()->and($warning['state']['clock_running'])->toBeFalse();
        $next = $engine->resolve($warning['state'], clockRosters(), 'inside_run', 'man_to_man');
        expect($next['play']['two_minute_warning'] ?? false)->toBeFalse();
        $crossing = $engine->resolve(clockState(['quarter' => $quarter, 'clock' => 121]), clockRosters(), 'inside_run', 'man_to_man');
        expect($crossing['state']['clock'])->toBeLessThan(120)->and($crossing['play']['two_minute_warning'])->toBeTrue();
        expect($crossing['state']['clock_running'])->toBeFalse();
    }
});

test('timeouts stop the clock without a snap and reset at halftime only', function () {
    $engine = app(ExhibitionEngine::class);
    $state = clockState(['clock_running' => true]);
    for ($i = 2; $i >= 0; $i--) {
        $result = $engine->timeout($state, clockRosters(), 'away');
        expect($result['state']['timeouts']['away'])->toBe($i)->and($result['state']['clock'])->toBe(900);
        expect($result['state']['clock_running'])->toBeFalse()->and($result['state']['down'])->toBe(1);
        expect($result['state']['stats']['home']['plays'])->toBe(0);
        $state = $result['state'];
        $state['clock_running'] = true;
    }
    expect(fn () => $engine->timeout($state, clockRosters(), 'away'))->toThrow(LogicException::class);
    expect(fn () => $engine->timeout(clockState(), clockRosters(), 'home'))->toThrow(LogicException::class);
    $state['quarter'] = 1;
    $state['clock'] = 1;
    $quarter = $engine->resolve($state, clockRosters(), 'inside_run', 'man_to_man');
    expect($quarter['state']['quarter'])->toBe(2)->and($quarter['state']['timeouts']['away'])->toBe(0);
    $state['quarter'] = 2;
    $half = $engine->resolve($state, clockRosters(), 'inside_run', 'man_to_man');
    expect($half['state']['quarter'])->toBe(3)->and($half['state']['timeouts'])->toBe(['home' => 3, 'away' => 3]);
});

test('spikes consume a down and stop the clock while kneels keep it running', function () {
    $engine = app(ExhibitionEngine::class);
    $state = clockState(['quarter' => 4, 'clock' => 90]);
    $spike = $engine->resolve($state, clockRosters(), 'spike', 'man_to_man', 'shotgun', 'base_4_3', 'hurry');
    expect($spike['state']['down'])->toBe(2)->and($spike['state']['clock'])->toBe(89)->and($spike['state']['clock_running'])->toBeFalse();
    $kneel = $engine->resolve($state, clockRosters(), 'kneel', 'man_to_man');
    expect($kneel['state']['spot'])->toBe(24)->and($kneel['state']['distance'])->toBe(11)->and($kneel['state']['clock_running'])->toBeTrue();
    $fourth = $engine->resolve(array_merge($state, ['down' => 4]), clockRosters(), 'spike', 'man_to_man');
    expect($fourth['state']['possession'])->toBe('away')->and($fourth['state']['clock_running'])->toBeFalse();
    $safety = $engine->resolve(array_merge($state, ['spot' => 1]), clockRosters(), 'kneel', 'man_to_man');
    expect($safety['state']['away_score'])->toBe(2)->and($safety['state']['phase'])->toBe('kickoff');
});

test('sideline strategy can sacrifice yardage to stop the clock in the last two minutes', function () {
    $engine = app(ExhibitionEngine::class);
    $found = false;
    foreach (range(1, 200) as $seed) {
        $state = clockState(['quarter' => 2, 'clock' => 90, 'seed' => $seed]);
        $result = $engine->resolve($state, clockRosters(), 'outside_run', 'man_to_man', 'singleback', 'base_4_3', 'hurry', 'sideline');
        if ($result['play']['out_of_bounds']) {
            expect($result['state']['clock_running'])->toBeFalse();
            expect($result['play']['summary'])->toContain('out of bounds');
            $ball = $result['play']['animation']['ball'];
            expect($ball[array_key_last($ball)][3])->toBe(53.33);
            $found = true;
            break;
        }
    }
    expect($found)->toBeTrue();
    $early = $engine->resolve(clockState(), clockRosters(), 'outside_run', 'man_to_man', 'singleback', 'base_4_3', 'normal', 'sideline');
    expect($early['play']['out_of_bounds'])->toBeFalse();
});

test('penalties enforce repeat downs half distance automatic first downs and decline benefits', function () {
    $rules = app(PenaltyRules::class);
    $before = clockState(['spot' => 8, 'down' => 2, 'distance' => 6]);
    $after = array_merge($before, ['spot' => 20, 'down' => 1, 'distance' => 10]);
    $play = ['call' => 'short_pass', 'defense' => 'man_to_man', 'carrier' => 'WR1', 'outcome' => 'tackle', 'gain' => 12, 'target' => 8, 'summary' => 'Completed'];
    $holding = $rules->enforce($before, $after, $play, 'holding');
    expect($holding['state']['spot'])->toBe(4)->and($holding['state']['down'])->toBe(2)->and($holding['state']['distance'])->toBe(10);
    expect($holding['state']['stats']['home']['penalty_yards'])->toBe(4);
    $turnover = array_merge($after, ['possession' => 'away']);
    $declined = $rules->enforce($before, $turnover, array_merge($play, ['outcome' => 'interception']), 'holding');
    expect($declined['play']['penalty']['accepted'])->toBeFalse()->and($declined['state']['possession'])->toBe('away');
    $pi = $rules->enforce($before, $turnover, array_merge($play, ['outcome' => 'interception']), 'defensive_pass_interference');
    expect($pi['state']['possession'])->toBe('home')->and($pi['state']['down'])->toBe(1)->and($pi['state']['spot'])->toBe(16);
    $face = $rules->enforce($before, $after, $play, 'face_mask');
    expect($face['state']['spot'])->toBe(35)->and($face['state']['down'])->toBe(1);
    $pre = $rules->enforce($before, $before, $play, 'encroachment');
    expect($pre['state']['spot'])->toBe(13)->and($pre['state']['distance'])->toBe(1)->and($pre['play']['no_snap'])->toBeTrue();
    $goal = $rules->enforce(array_merge($before, ['spot' => 98]), array_merge($after, ['spot' => 98]), $play, 'face_mask');
    expect($goal['state']['spot'])->toBe(99);
});

test('accepted defensive fouls extend an expired period with an untimed down', function () {
    $engine = app(ExhibitionEngine::class);
    $before = clockState(['quarter' => 4, 'clock' => 1, 'spot' => 60]);
    $play = ['call' => 'short_pass', 'defense' => 'man_to_man', 'offense_formation' => 'shotgun', 'defense_formation' => 'base_4_3', 'carrier' => 'WR1', 'outcome' => 'incomplete', 'gain' => 0, 'target' => 10, 'summary' => 'Incomplete'];
    $enforced = app(PenaltyRules::class)->enforce($before, $before, $play, 'defensive_pass_interference');
    $extended = $engine->finish($enforced['state'], $before, $enforced['play'], clockRosters(), 5);
    expect($extended['state']['clock'])->toBe(0)->and($extended['state']['untimed_down'])->toBeTrue()->and($extended['state']['status'])->toBe('playing');
    $final = $engine->resolve($extended['state'], clockRosters(), 'spike', 'man_to_man');
    expect($final['state']['status'])->toBe('final');
});

test('CPU clock choices target late first half scores protect a lead and use defensive timeouts', function () {
    $coach = app(CpuCoach::class);
    $state = clockState(['quarter' => 2, 'clock' => 90, 'controls' => ['home' => 'cpu', 'away' => 'cpu']]);
    expect($coach->management($state))->toBe(['tempo' => 'hurry', 'clock_strategy' => 'sideline']);
    $state['quarter'] = 4;
    $state['home_score'] = 7;
    $state['clock_running'] = true;
    expect($coach->management($state)['tempo'])->toBe('drain')->and($coach->timeoutTeam($state))->toBe('away');
    $state['timeouts']['away'] = 0;
    expect($coach->timeoutTeam($state))->toBeNull();
    expect($coach->offense($state, clockRosters())['call'])->toBe('kneel');
    $state['home_score'] = 0;
    $state['away_score'] = 7;
    expect($coach->management($state)['tempo'])->toBe('hurry');
    $state['clock'] = 8;
    $state['spot'] = 90;
    $state['quarter'] = 2;
    expect($coach->offense($state, clockRosters())['call'])->toBe('field_goal');
});

test('CPU games with clock management and penalties finish with valid field position and timeout counts', function () {
    $engine = app(ExhibitionEngine::class);
    $coach = app(CpuCoach::class);
    $penalties = 0;
    for ($seed = 1; $seed <= 12; $seed++) {
        $state = $engine->initial(900, $seed);
        $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
        for ($i = 0; $i < 1000 && $state['status'] !== 'final'; $i++) {
            $timeout = $coach->timeoutTeam($state);
            if ($timeout) {
                $result = $engine->timeout($state, clockRosters(), $timeout);
            } else {
                $offense = $coach->offense($state, clockRosters());
                $defense = $coach->defense($state, $offense['call']);
                $management = $coach->management($state);
                $result = $engine->resolve($state, clockRosters(), $offense['call'], $defense['call'], $offense['formation'], $defense['formation'], $management['tempo'], $management['clock_strategy']);
            }
            expect($result['state']['version'])->toBe($state['version'] + 1);
            $state = $result['state'];
            expect($state['clock'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(900);
            expect($state['spot'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(99);
            expect($state['down'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(4);
            foreach ($state['timeouts'] as $count) {
                expect($count)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(3);
            }
            $penalties += (int) ($result['play']['penalty']['accepted'] ?? false);
        }
        expect($state['status'])->toBe('final')->and($state['quarter'])->toBe(4);
    }
    expect($penalties)->toBeGreaterThan(20);
});

test('human timeout requests are version checked scoped and cannot control the CPU team', function () {
    $this->withoutVite();
    $world = World::create(['name' => 'Timeout UI']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    app(CurrentWorld::class)->id = $world->id;
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 900, 'away_control' => 'cpu', 'penalties' => 0])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $state = array_merge($game->state, ['possession' => 'home', 'phase' => 'scrimmage', 'clock_running' => true, 'quarter' => 2, 'clock' => 90]);
    $game->state = $state;
    app(CurrentWorld::class)->id = $world->id;
    $game->save();
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('Hurry-up')->assertSee('Try to get out of bounds')->assertSee('timeout (3)');
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'action' => 'timeout', 'timeout_team' => 'away'])->assertStatus(422);
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'action' => 'timeout', 'timeout_team' => 'home'])->assertRedirect();
    $game->refresh();
    expect($game->state['timeouts']['home'])->toBe(2)->and($game->state['clock'])->toBe(90)->and($game->history[0]['animation']['no_snap'])->toBeTrue();
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'action' => 'timeout', 'timeout_team' => 'home'])->assertStatus(409);
    $this->post(route('exhibitions.play', $game), ['version' => 1, 'action' => 'timeout', 'timeout_team' => 'home'])->assertStatus(422);
    $this->get(route('exhibitions.show', [$game, 'watch' => 1]))->assertOk();
});

test('human penalty choices commit the selected outcome once and block intervening plays', function (string $decision) {
    $this->withoutVite();
    $world = World::create(['name' => 'Flags']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    app(CurrentWorld::class)->id = $world->id;
    $teams = Team::all();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 900])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $engine = app(ExhibitionEngine::class);
    for ($seed = 1; $seed < 500; $seed++) {
        $result = $engine->resolve($engine->initial(900, $seed, false), $game->rosters, 'inside_run', 'man_to_man');
        if (($result['play']['penalty']['type'] ?? '') === 'holding') {
            break;
        }
    }
    expect($result['state']['penalty_pending'])->toBeTrue();
    $expected = $result['play']['penalty_options'][$decision];
    app(CurrentWorld::class)->id = $world->id;
    $game->update(['state' => $result['state'], 'history' => [$result['play']]]);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertSee('data-penalty-dialog', false)->assertSee('Accept')->assertSee('Decline')->assertSee('illegally held');
    $this->post(route('exhibitions.play', $game), ['version' => 1, 'call' => 'inside_run', 'defense' => 'man_to_man'])->assertStatus(409);
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'action' => 'penalty', 'decision' => $decision])->assertStatus(409);
    $this->post(route('exhibitions.play', $game), ['version' => 1, 'action' => 'penalty', 'decision' => $decision])->assertRedirect(route('exhibitions.show', [$game, 'watch' => 0]));
    $game->refresh();
    expect($game->state)->toBe($expected['state'])->and($game->history)->toHaveCount(1);
    expect($game->history[0]['penalty']['accepted'])->toBe($decision === 'accept');
    expect($game->history[0]['penalty']['decided'])->toBeTrue();
    $this->get(route('exhibitions.show', $game))->assertOk()->assertDontSee('data-penalty-dialog', false);
    $this->post(route('exhibitions.play', $game), ['version' => 1, 'action' => 'penalty', 'decision' => $decision])->assertStatus(409);
})->with(['accept', 'decline']);

test('dead ball fouls offer unchanged down on decline and CPU penalties need only acknowledgment', function () {
    $engine = app(ExhibitionEngine::class);
    for ($seed = 1; $seed < 500; $seed++) {
        $result = $engine->resolve($engine->initial(900, $seed, false), clockRosters(), 'inside_run', 'man_to_man');
        if (($result['play']['penalty']['type'] ?? '') === 'false_start') {
            break;
        }
    }
    expect($result['play']['animation']['no_snap'])->toBeTrue();
    expect($result['play']['penalty_options']['accept']['state']['spot'])->toBe(20);
    expect($result['play']['penalty_options']['decline']['state']['spot'])->toBe(25);
    expect($result['play']['penalty_options']['decline']['state']['down'])->toBe(1);
    $state = $engine->initial(900, $seed, false);
    $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
    $cpu = $engine->resolve($state, clockRosters(), 'inside_run', 'man_to_man');
    expect($cpu['state']['penalty_pending'] ?? false)->toBeFalse();
    expect($cpu['play'])->not->toHaveKey('penalty_options');
    expect($cpu['play']['penalty']['beneficiary'])->toBe('away');
    expect($cpu['play']['penalty']['explanation'])->toContain('before the snap');
});

test('human defensive penalty choices preserve an untimed down or allow the final whistle', function () {
    $engine = app(ExhibitionEngine::class);
    for ($seed = 1; $seed < 500; $seed++) {
        $state = $engine->initial(900, $seed, false);
        $state['quarter'] = 4;
        $state['clock'] = 1;
        $result = $engine->resolve($state, clockRosters(), 'short_pass', 'man_to_man');
        if (($result['play']['penalty']['type'] ?? '') === 'defensive_pass_interference') {
            break;
        }
    }
    $accepted = $result['play']['penalty_options']['accept']['state'];
    $declined = $result['play']['penalty_options']['decline']['state'];
    expect($accepted['untimed_down'])->toBeTrue()->and($accepted['status'])->toBe('playing')->and($accepted['clock'])->toBe(0);
    expect($declined['untimed_down'])->toBeFalse()->and($declined['status'])->toBe('final');
    expect($accepted['stats']['away']['penalties'])->toBe(1);
    expect($declined['stats']['away']['penalties'])->toBe(0);
});

test('normal pace uses realistic running-clock preparation and stopped clocks have no runoff', function () {
    $clock = app(\App\Services\Simulation\GameClock::class);
    expect($clock->runoff(clockState(['clock_running' => true]), 'normal'))->toBe(34);
    expect($clock->runoff(clockState(['clock_running' => false]), 'normal'))->toBe(0);
});

test('encroachment preserves the line to gain through engine finish and both human decisions', function () {
    $engine = app(ExhibitionEngine::class);
    foreach ([10 => 5, 5 => 10] as $distance => $expected) {
        $before = clockState(['spot' => 30, 'down' => 1, 'distance' => $distance]);
        $play = ['call' => 'clock_event', 'defense' => 'man_to_man', 'carrier' => 'RB', 'outcome' => 'penalty', 'gain' => 0, 'target' => 0, 'summary' => 'No snap', 'no_snap' => true];
        foreach ([true, false] as $accept) {
            $penalty = app(PenaltyRules::class)->enforce($before, $before, $play, 'encroachment', $accept);
            $result = $engine->finish($penalty['state'], $before, $penalty['play'], clockRosters(), 0);
            expect($result['state']['down'])->toBe(1)
                ->and($result['state']['spot'])->toBe($accept ? 35 : 30)
                ->and($result['state']['distance'])->toBe($accept ? $expected : $distance);
        }
    }
});

test('a generated human encroachment offers first and five rather than a new first and ten', function () {
    $engine = app(ExhibitionEngine::class);
    for ($seed = 1; $seed < 1000; $seed++) {
        $before = clockState(['seed' => $seed, 'spot' => 30, 'down' => 1, 'distance' => 10, 'rules' => ['penalties' => true], 'controls' => ['home' => 'human', 'away' => 'cpu']]);
        if (app(PenaltyRules::class)->preSnap($before) !== 'encroachment') {
            continue;
        }
        $result = $engine->resolve($before, clockRosters(), 'inside_run', 'man_to_man');
        expect($result['state']['distance'])->toBe(5)
            ->and($result['play']['penalty_options']['accept']['state']['distance'])->toBe(5)
            ->and($result['play']['penalty_options']['decline']['state']['distance'])->toBe(10);

        return;
    }
    throw new RuntimeException('No encroachment seed found.');
});
