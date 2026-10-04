<?php

use App\Models\User;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\DB;

function emptyDemoWorld(User $user): World
{
    $world = World::create(['name' => 'Test World', 'owner_user_id' => $user->id]);
    $world->users()->attach($user->id, ['role' => 'owner']);
    $user->forceFill(['current_world_id' => $world->id])->save();

    return $world;
}

test('demo seed creates complete rosters and exhibitions only in the selected world', function () {
    $user = User::factory()->create();
    $world = emptyDemoWorld($user);
    $other = emptyDemoWorld(User::factory()->create());
    app(CurrentWorld::class)->id = $other->id;
    $this->artisan('world:seed-demo', ['email' => $user->email, '--year' => 2030])->assertSuccessful();
    expect(app(CurrentWorld::class)->id)->toBe($other->id);
    $this->assertDatabaseCount('teams', 4);
    $this->assertDatabaseCount('players', 212);
    $this->assertDatabaseCount('team_players', 212);
    $this->assertDatabaseCount('games', 6);
    $this->assertDatabaseHas('seasons', ['world_id' => $world->id, 'year' => 2030]);
    expect(DB::table('teams')->where('world_id', $other->id)->count())->toBe(0);
    foreach (DB::table('teams')->get() as $team) {
        $roster = DB::table('team_players')->where('team_id', $team->id)->get();
        expect($roster)->toHaveCount(53);
        expect($roster->pluck('jersey_number')->unique())->toHaveCount(53);
        expect($roster->pluck('depth_chart_position'))->toContain('QB1', 'K1', 'P1', 'OL1', 'S2');
        foreach (['catch', 'catch_plus', 'rush', 'sack', 'interception', 'tackle', 'kick', 'punt'] as $prefix) {
            $covered = [];
            foreach ($roster as $row) {
                if ($row->{$prefix.'_from'} > 0) {
                    $covered = array_merge($covered, range($row->{$prefix.'_from'}, $row->{$prefix.'_to'}));
                }
            }
            sort($covered);
            expect($covered)->toBe(range(1, 20));
        }
    }
    $this->artisan('world:seed-demo', ['email' => $user->email])->assertFailed();
    $this->assertDatabaseCount('players', 212);
});

test('demo seed rejects unknown users invalid options and another users world', function () {
    $user = User::factory()->create();
    emptyDemoWorld($user);
    $foreign = emptyDemoWorld(User::factory()->create());
    $this->artisan('world:seed-demo', ['email' => 'missing@example.com'])->assertFailed();
    $this->artisan('world:seed-demo', ['email' => $user->email, '--world' => $foreign->id])->assertFailed();
    $this->artisan('world:seed-demo', ['email' => $user->email, '--teams' => 'wrong'])->assertFailed();
    $this->artisan('world:seed-demo', ['email' => $user->email, '--year' => 'wrong'])->assertFailed();
    $this->assertDatabaseCount('teams', 0);
});

test('demo seed uses the existing season year and supports a smaller league', function () {
    $user = User::factory()->create();
    $world = emptyDemoWorld($user);
    app(CurrentWorld::class)->id = $world->id;
    $league = $world->leagues()->create(['name' => 'Existing League']);
    $league->seasons()->create(['year' => 2035]);
    app(CurrentWorld::class)->id = null;
    $this->artisan('world:seed-demo', ['email' => $user->email, '--teams' => 2])->assertSuccessful();
    $this->assertDatabaseCount('leagues', 1);
    $this->assertDatabaseCount('seasons', 1);
    $this->assertDatabaseCount('players', 106);
    $this->assertDatabaseCount('games', 1);
    expect(DB::table('team_players')->distinct()->pluck('team_year')->all())->toBe(['2035']);
    $this->withoutVite();
    $teamId = DB::table('teams')->where('world_id', $world->id)->value('id');
    $this->actingAs($user)->get('/teams/editor/teams/'.$teamId.'/players')->assertOk()->assertSee('Players (2035)');
});
