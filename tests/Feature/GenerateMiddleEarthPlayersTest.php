<?php

use App\Models\LocalSetting;
use App\Models\Player;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\MiddleEarthRosterGenerator;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;

test('middle earth command creates the original league without changing the current saved game', function () {
    $this->withoutVite();
    $old = World::create(['name' => 'Existing save']);
    app(CurrentWorld::class)->id = $old->id;
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $old->id]);
    $oldTeam = Team::create(['city' => 'Existing', 'name' => 'Team', 'uniform_home_shirt' => '#112233']);
    $this->artisan('football:seed-middle-earth', ['--year' => 2030, '--seed' => 42])->assertSuccessful();
    expect(app(CurrentWorld::class)->id)->toBe($old->id);
    expect(LocalSetting::find(1)->current_world_id)->toBe($old->id);
    expect($oldTeam->fresh()->uniform_home_shirt)->toBe('#112233');
    $world = World::where('name', 'Middle Earth Football')->firstOrFail();
    app(CurrentWorld::class)->id = $world->id;
    expect(Team::count())->toBe(16);
    expect(Player::count())->toBe(848);
    $this->assertDatabaseHas('seasons', ['world_id' => $world->id, 'year' => 2030]);
    $teams = Team::orderBy('id')->get();
    expect($teams->map(fn ($team) => $team->city.' '.$team->name)->all())->toBe(array_map(fn ($team) => $team[0].' '.$team[1], config('middle-earth.teams')));
    foreach (config('middle-earth.teams') as $i => $expected) {
        expect($teams[$i]->conference)->toBe($expected[3])->and($teams[$i]->division)->toBe($expected[4]);
    }
    foreach ($teams as $team) {
        $players = $team->players()->wherePivot('team_year', '2030')->get();
        expect($players)->toHaveCount(53);
        expect($players->pluck('pivot.jersey_number')->unique())->toHaveCount(53);
        expect($players->map(fn ($player) => $player->firstname.' '.$player->lastname)->unique())->toHaveCount(53);
        $roster = app(RosterBuilder::class)->build($team);
        expect($roster['players'])->toHaveCount(27);
        foreach ($players as $player) {
            expect(array_keys($player->simulation_ratings))->toBe(PlayerRatings::FIELDS);
            foreach ($player->simulation_ratings as $value) {
                expect($value)->toBeBetween(10, 99);
            }
        }
    }
    expect($teams[0]->team_color1)->toBe('#111111');
    expect($teams[0]->uniform_home_number)->toBe('#dc143c');
    expect($teams[3]->team_color1)->toBe('#b7410e');
    expect($teams[4]->team_color1)->toBe('#cd7f32');
    $rosters = ['home' => app(RosterBuilder::class)->build($teams[0]), 'away' => app(RosterBuilder::class)->build($teams[1])];
    $engine = app(ExhibitionEngine::class);
    $result = $engine->resolve($engine->initial(180, 42), $rosters, 'kickoff', 'kickoff_return');
    expect($result['play']['animation']['players'])->toHaveCount(22);
    app(CurrentWorld::class)->id = null;
    $this->withSession(['current_world_id' => $world->id])->get(route('teams.editor.index'))->assertOk()->assertSee('Imperial')->assertSee('Watch');
});

test('middle earth rosters reproduce names ratings and starters independently of database IDs', function () {
    $generator = app(MiddleEarthRosterGenerator::class);
    $roster = $generator->generate(42);
    expect($generator->generate(42))->toBe($roster);
    expect($generator->generate(43))->not->toBe($roster);
    expect($roster)->toHaveCount(53);
    expect(array_column(array_column($roster, 'roster'), 'jersey_number'))->toBe(range(1, 53));
    $quarterbacks = array_values(array_filter($roster, fn ($entry) => $entry['player']['position'] === 'QB'));
    foreach ($quarterbacks as $entry) {
        expect($entry['player']['simulation_ratings']['throwing'])->toBeGreaterThan($entry['player']['simulation_ratings']['blocking']);
    }
    $skill = fn ($entry) => array_sum(array_intersect_key($entry['player']['simulation_ratings'], array_flip(['throwing', 'awareness', 'ball_security'])));
    expect($skill($quarterbacks[0]))->toBeGreaterThanOrEqual($skill($quarterbacks[1]));
    foreach (['catch', 'catch_plus', 'rush', 'sack', 'interception', 'tackle', 'kick', 'punt'] as $prefix) {
        $covered = [];
        foreach ($roster as $entry) {
            if ($entry['roster'][$prefix.'_from'] > 0) {
                $covered = array_merge($covered, range($entry['roster'][$prefix.'_from'], $entry['roster'][$prefix.'_to']));
            }
        }
        sort($covered);
        expect($covered)->toBe(range(1, 20));
    }
});

test('middle earth seeding refuses an occupied saved game and invalid arguments', function () {
    $world = World::create(['name' => 'Occupied']);
    app(CurrentWorld::class)->id = $world->id;
    Team::create(['city' => 'Original', 'name' => 'Team']);
    $this->artisan('football:seed-middle-earth', ['world' => $world->id])->assertFailed();
    expect(Team::count())->toBe(1)->and(Player::count())->toBe(0);
    foreach ([['world' => 'wrong'], ['world' => 99999], ['--year' => 'wrong'], ['--seed' => -1], ['--seed' => '999999999999999999999'], ['--name' => ' ']] as $options) {
        $this->artisan('football:seed-middle-earth', $options)->assertFailed();
    }
    expect(World::count())->toBe(1)->and(app(CurrentWorld::class)->id)->toBe($world->id);
});

test('middle earth seed failures roll back the entire league and restore world context', function () {
    $world = World::create(['name' => 'Original']);
    app(CurrentWorld::class)->id = $world->id;
    $this->mock(MiddleEarthRosterGenerator::class, function ($mock) {
        $mock->shouldReceive('generate')->once()->andThrow(new LogicException('Roster generation failed.'));
    });
    $this->artisan('football:seed-middle-earth')->assertFailed();
    expect(World::count())->toBe(1)->and(app(CurrentWorld::class)->id)->toBe($world->id);
    $this->assertDatabaseCount('teams', 0);
    $this->assertDatabaseCount('players', 0);
    $this->assertDatabaseCount('leagues', 0);
});

test('console dispatcher allows the scoped middle earth seeder and still blocks legacy imports', function () {
    \Illuminate\Support\Facades\Artisan::all();
    \Illuminate\Support\Facades\Event::dispatch(new \Illuminate\Console\Events\CommandStarting('football:seed-middle-earth', new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput));
    expect(\Illuminate\Support\Facades\Artisan::call('football:seed-middle-earth', ['--seed' => 42]))->toBe(0);
    $this->assertDatabaseCount('teams', 16);
    $this->assertDatabaseCount('players', 848);
    expect(fn () => \Illuminate\Support\Facades\Event::dispatch(new \Illuminate\Console\Events\CommandStarting('football:seed-nfl', new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput)))
        ->toThrow(LogicException::class, 'Legacy football commands are disabled');
});
