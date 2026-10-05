<?php

use App\Models\LocalSetting;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\DB;

function emptyDemoWorld(): World
{
    $world = World::create(['name' => 'Test World']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);

    return $world;
}

test('demo seed creates complete rosters and exhibitions only in the selected world', function () {
    $world = emptyDemoWorld();
    $other = emptyDemoWorld();
    app(CurrentWorld::class)->id = $other->id;
    $this->artisan('world:seed-demo', ['world' => $world->id, '--year' => 2030])->assertSuccessful();
    expect(app(CurrentWorld::class)->id)->toBe($other->id);
    $this->assertDatabaseCount('teams', 4);
    $this->assertDatabaseCount('players', 212);
    $this->assertDatabaseCount('team_players', 212);
    $this->assertDatabaseCount('exhibitions', 6);
    $this->assertDatabaseHas('seasons', ['world_id' => $world->id, 'year' => 2030]);
    expect(DB::table('teams')->where('world_id', $other->id)->count())->toBe(0);
    foreach (DB::table('teams')->get() as $team) {
        $roster = DB::table('team_players')->where('team_id', $team->id)->get();
        expect($roster)->toHaveCount(53);
        expect($roster->pluck('jersey_number')->unique())->toHaveCount(53);
        expect($roster->pluck('depth_chart_position'))->toContain('QB1', 'K1', 'P1', 'OL1', 'S2');
    }
    foreach (DB::table('players')->get() as $player) {
        $ratings = json_decode($player->simulation_ratings, true);
        expect(array_keys($ratings))->toBe(\App\Services\Simulation\PlayerRatings::FIELDS);
        foreach ($ratings as $rating) {
            expect($rating)->toBeBetween(1, 99);
        }
    }
    $this->artisan('world:seed-demo', ['world' => $world->id])->assertFailed();
    $this->assertDatabaseCount('players', 212);
});

test('demo seed rejects missing saved games and invalid options', function () {
    $world = emptyDemoWorld();
    $this->artisan('world:seed-demo', ['world' => 9999])->assertFailed();
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 'wrong'])->assertFailed();
    $this->artisan('world:seed-demo', ['world' => $world->id, '--year' => 'wrong'])->assertFailed();
    $this->assertDatabaseCount('teams', 0);
});

test('demo seed uses the existing season year and supports a smaller league', function () {
    $world = emptyDemoWorld();
    app(CurrentWorld::class)->id = $world->id;
    $league = $world->leagues()->create(['name' => 'Existing League']);
    $league->seasons()->create(['year' => 2035]);
    app(CurrentWorld::class)->id = null;
    $this->artisan('world:seed-demo', ['--teams' => 2])->assertSuccessful();
    $this->assertDatabaseCount('leagues', 1);
    $this->assertDatabaseCount('seasons', 1);
    $this->assertDatabaseCount('players', 106);
    $this->assertDatabaseCount('exhibitions', 1);
    expect(DB::table('team_players')->distinct()->pluck('team_year')->all())->toBe(['2035']);
    $this->withoutVite();
    $teamId = DB::table('teams')->where('world_id', $world->id)->value('id');
    $this->get('/teams/editor/teams/'.$teamId.'/players')->assertOk()->assertSee('Players (2035)');
});
