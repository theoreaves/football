<?php

use App\Models\Exhibition;
use App\Models\World;
use App\Services\Simulation\CpuCoach;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->world = World::create(['name' => 'CPU clock tests']);
    openFootballSave($this->world);
    $this->artisan('world:seed-demo', ['world' => $this->world->id, '--teams' => 2])->assertSuccessful();
    app(CurrentWorld::class)->id = $this->world->id;
});

test('clock test command lists worlds without creating a game', function () {
    $count = Exhibition::count();
    $this->artisan('game:test-clock')->expectsOutputToContain('CPU clock tests')->assertSuccessful();
    expect(Exhibition::count())->toBe($count);
});

test('clock test presets create isolated playable snapshots with the expected CPU call', function ($scenario, $calls) {
    $before = Exhibition::all()->mapWithKeys(fn ($game) => [$game->id => $game->getAttributes()])->all();
    $this->artisan('game:test-clock', ['world' => $this->world->id, '--scenario' => $scenario])->assertSuccessful();
    $game = Exhibition::latest('id')->firstOrFail();
    expect($game->state['quarter'])->toBe(4)->and($game->state['phase'])->toBe('scrimmage')
        ->and($game->state['controls'])->toBe(['home' => 'cpu', 'away' => 'cpu'])
        ->and($game->state['team_appearance']['home']['name'])->not->toBeEmpty()
        ->and($game->history)->toBe([])->and($game->seasonFixture)->toBeNull();
    expect(app(CpuCoach::class)->offense($game->state, $game->rosters)['call'])->toBeIn($calls);
    foreach ($before as $id => $attributes) {
        expect(Exhibition::findOrFail($id)->getAttributes())->toBe($attributes);
    }
    expect(app(CurrentWorld::class)->id)->toBe($this->world->id);
    $this->withoutVite()->get(route('exhibitions.show', $game))->assertOk();
})->with([
    ['kneel', ['kneel']], ['run', ['inside_run', 'inside_zone', 'power', 'lead']],
    ['warning', ['kneel']], ['fourth', ['punt_hang']],
    ['trailing', ['short_pass', 'slant', 'quick_pass', 'mesh', 'screen', 'hitch', 'curl', 'drag', 'te_stick', 'rb_flat', 'medium_pass', 'deep_pass', 'flood', 'play_action', 'comeback', 'dig', 'out', 'te_seam', 'post', 'corner', 'fade', 'wheel']],
    ['safety', ['inside_run', 'inside_zone', 'power', 'lead']],
]);

test('clock test overrides support visitor possession and stopped clocks', function () {
    $this->artisan('game:test-clock', ['world' => $this->world->id, '--possession' => 'away', '--clock' => 44, '--timeouts' => 2, '--stopped' => true, '--down' => 1, '--spot' => 60, '--margin' => 3])->assertSuccessful();
    $state = Exhibition::latest('id')->firstOrFail()->state;
    expect($state['possession'])->toBe('away')->and($state['away_score'] - $state['home_score'])->toBe(3)
        ->and($state['timeouts']['home'])->toBe(2)->and($state['clock_running'])->toBeFalse()
        ->and(app(CpuCoach::class)->offense($state, Exhibition::latest('id')->first()->rosters)['call'])->toBe('kneel');
});

test('invalid test scenarios and foreign teams cannot create games or change world context', function () {
    $count = Exhibition::withoutGlobalScopes()->count();
    foreach ([['--scenario' => 'bad'], ['--clock' => 'abc'], ['--clock' => 0], ['--spot' => 0], ['--down' => 5], ['--timeouts' => 4], ['--possession' => 'bad']] as $options) {
        $this->artisan('game:test-clock', ['world' => $this->world->id] + $options)->assertFailed();
    }
    $other = World::create(['name' => 'Other']);
    $this->artisan('game:test-clock', ['world' => $other->id])->assertFailed();
    expect(Exhibition::withoutGlobalScopes()->count())->toBe($count)->and(app(CurrentWorld::class)->id)->toBe($this->world->id);
});
