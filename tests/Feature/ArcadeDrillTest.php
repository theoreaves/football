<?php

use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\User;
use App\Models\World;
use App\Services\Simulation\PlayerRatings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->withoutVite();
    config(['arcade.enabled' => true]);
    openFootballSave(World::create(['name' => 'Arcade world']));
});

function arcadePlayer(Team $team, string $position, string $depth, string $year = '2030', array $attributes = []): Player
{
    $player = Player::create(array_merge(['firstname' => 'Real', 'lastname' => $depth, 'age' => 24, 'position' => $position], $attributes));
    TeamPlayer::create(['team_id' => $team->id, 'player_id' => $player->id, 'position' => $position, 'depth_chart_position' => $depth, 'team_year' => $year, 'jersey_number' => $player->id]);

    return $player;
}

function arcadeTeams(): array
{
    return [Team::create(['city' => 'Alpha', 'name' => 'Runners']), Team::create(['city' => 'Beta', 'name' => 'Defenders'])];
}

test('arcade is default off and enforces the server gate', function () {
    expect(require base_path('config/arcade.php'))->toHaveKey('enabled');
    config(['arcade.enabled' => false]);
    $this->get(route('arcade.drill'))->assertNotFound();
});

test('arcade requires authentication verification and a current world', function () {
    auth()->logout();
    $this->get(route('arcade.drill'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create());
    openFootballSave(World::create(['name' => 'Unverified world']));
    $this->get(route('arcade.drill'))->assertRedirect(route('verification.notice'));
    $this->actingAs(User::factory()->create())->withSession(['current_world_id' => null]);
    $this->get(route('arcade.drill'))->assertRedirect(route('worlds.index'));
});

test('arcade reads actual latest roster snapshots deterministically without football writes', function () {
    [$home, $away] = arcadeTeams();
    arcadePlayer($home, 'RB', 'RB1', '2029');
    arcadePlayer($home, 'RB', 'RB2');
    $runner = arcadePlayer($home, 'RB', 'RB1', attributes: ['simulation_ratings' => ['speed' => 87, 'ball_security' => 91], 'appearance' => ['visor' => 'clear'], 'height_inches' => 73, 'weight_pounds' => 221]);
    $defender = arcadePlayer($away, 'LB', 'LB1');
    arcadePlayer($away, 'CB', 'CB2');
    arcadePlayer($away, 'S', 'S3');
    $tables = collect(Schema::getTableListing())->reject(fn ($table) => in_array($table, ['sessions', 'sqlite_sequence'], true));
    $before = $tables->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    foreach (range(1, 2) as $attempt) {
        $response = $this->get(route('arcade.drill'));
        $response->assertOk()->assertViewIs('arcade.drill')->assertViewHas('setupMessage', null);
        expect($response['runner']['id'])->toBe($runner->id)
            ->and($response['runner']['ratings'])->toBe(app(PlayerRatings::class)->forPlayer($runner))
            ->and($response['runner']['appearance'])->toBe(['visor' => 'clear'])
            ->and($response['runner']['height_inches'])->toBe(73)
            ->and(array_column($response['defenders'], 'id'))->toHaveCount(2)
            ->and($response['defenders'][0]['id'])->toBe($defender->id);
    }
    expect($tables->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all())->toBe($before)
        ->and($writes)->toBe([])
        ->and($defender->fresh()->simulation_ratings)->toBeNull()
        ->and($defender->fresh()->height_inches)->toBeNull();
});

test('arcade allows selecting a real eligible runner only from the selected home roster', function () {
    [$home, $away] = arcadeTeams();
    arcadePlayer($home, 'RB', 'RB1');
    $selected = arcadePlayer($home, 'FB', 'FB2');
    $opponent = arcadePlayer($away, 'RB', 'RB1');
    arcadePlayer($away, 'DB', 'DB1');
    $this->get(route('arcade.drill', ['home' => $home->id, 'away' => $away->id, 'runner' => $selected->id]))
        ->assertOk()->assertViewHas('runner', fn ($runner) => $runner['id'] === $selected->id);
    $this->get(route('arcade.drill', ['runner' => $opponent->id]))->assertNotFound();
    $this->getJson(route('arcade.drill', ['home' => $home->id, 'away' => $home->id]))->assertUnprocessable()->assertJsonValidationErrors('away');
});

test('arcade cannot select teams or players outside the current world', function () {
    [$foreignHome, $foreignAway] = arcadeTeams();
    $foreignRunner = arcadePlayer($foreignHome, 'RB', 'RB1');
    openFootballSave(World::create(['name' => 'Another world']));
    [$home, $away] = arcadeTeams();
    arcadePlayer($home, 'RB', 'RB1');
    arcadePlayer($away, 'LB', 'LB1');
    $this->get(route('arcade.drill'))->assertOk()->assertViewHas('teams', fn ($teams) => $teams->pluck('id')->all() === [$home->id, $away->id]);
    $this->get(route('arcade.drill', ['home' => $foreignHome->id]))->assertNotFound();
    $this->get(route('arcade.drill', ['away' => $foreignAway->id]))->assertNotFound();
    $this->get(route('arcade.drill', ['runner' => $foreignRunner->id]))->assertNotFound();
});

test('arcade reports missing teams runners and defenders without synthetic players', function () {
    $world = World::firstOrFail();
    $this->get(route('arcade.drill'))->assertOk()->assertViewHas('runner', null)->assertViewHas('defenders', [])->assertViewHas('setupMessage', fn ($message) => str_contains($message, 'two teams'));
    openFootballSave($world);
    [$home, $away] = arcadeTeams();
    $this->get(route('arcade.drill'))->assertOk()->assertViewHas('setupMessage', fn ($message) => str_contains($message, 'RB or FB'));
    openFootballSave($world);
    arcadePlayer($home, 'RB', 'RB1');
    $this->get(route('arcade.drill'))->assertOk()->assertViewHas('defenders', [])->assertViewHas('setupMessage', fn ($message) => str_contains($message, 'defensive back'));
    openFootballSave($world);
    arcadePlayer($away, 'LB', 'LB1');
    $this->get(route('arcade.drill'))->assertOk()->assertViewHas('setupMessage', null)->assertViewHas('defenders', fn ($defenders) => count($defenders) === 1);
});

test('arcade roster defaults stay in memory and latest year excludes historical runners', function () {
    [$home, $away] = arcadeTeams();
    arcadePlayer($home, 'RB', 'RB1', '2029');
    arcadePlayer($home, 'WR', 'WR1', '2030');
    $player = arcadePlayer($away, 'DB', 'DB1');
    $before = $player->fresh()->getRawOriginal();
    $roster = app(\App\Services\Arcade\DrillRoster::class);
    expect($roster->runners($home))->toBe([]);
    $snapshot = $roster->defenders($away)[0];
    expect($snapshot['id'])->toBe($player->id)
        ->and($snapshot['height_inches'])->toBe(72)
        ->and($snapshot['weight_pounds'])->toBe(215)
        ->and($snapshot['ratings'])->toBe(app(PlayerRatings::class)->forPlayer($player))
        ->and($player->fresh()->getRawOriginal())->toBe($before);
});

test('arcade rejects malformed selector input', function (array $query, string $field) {
    $this->getJson(route('arcade.drill', $query))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['home' => 'invalid'], 'home'],
    [['away' => -1], 'away'],
    [['runner' => ['bad']], 'runner'],
]);

test('arcade prefers a primary running back over a fullback at equal depth', function () {
    [$home] = arcadeTeams();
    arcadePlayer($home, 'FB', 'FB1');
    $runner = arcadePlayer($home, 'RB', 'RB1');
    expect(app(\App\Services\Arcade\DrillRoster::class)->runners($home)[0]['id'])->toBe($runner->id);
});
