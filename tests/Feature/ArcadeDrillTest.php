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
        $response->assertOk()->assertViewIs('arcade.drill')->assertViewHas('setupMessage', null)
            ->assertViewHas('mode', 'running')->assertViewHas('quarterback', null)->assertViewHas('receivers', []);
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
    [['mode' => 'kicking'], 'mode'],
    [['mode' => ['passing']], 'mode'],
    [['mode' => 'passing', 'quarterback' => -1], 'quarterback'],
    [['mode' => 'passing', 'quarterback' => ['bad']], 'quarterback'],
]);

test('arcade prefers a primary running back over a fullback at equal depth', function () {
    [$home] = arcadeTeams();
    arcadePlayer($home, 'FB', 'FB1');
    $runner = arcadePlayer($home, 'RB', 'RB1');
    expect(app(\App\Services\Arcade\DrillRoster::class)->runners($home)[0]['id'])->toBe($runner->id);
});

test('arcade passing reads actual latest roster identities ratings and appearance without football writes', function () {
    [$home, $away] = arcadeTeams();
    arcadePlayer($home, 'QB', 'QB1', '2029');
    arcadePlayer($home, 'WR', 'WR1', '2029');
    arcadePlayer($away, 'CB', 'CB1', '2029');
    arcadePlayer($home, 'QB', 'QB2');
    $quarterback = arcadePlayer($home, 'QB', 'QB1', attributes: ['firstname' => 'Caleb', 'lastname' => 'Reed', 'simulation_ratings' => ['throwing' => 92, 'awareness' => 86], 'appearance' => ['visor' => 'clear'], 'height_inches' => 75, 'weight_pounds' => 225, 'skin_tone' => '#8c5536']);
    $tightEnd = arcadePlayer($home, 'TE', 'TE1', attributes: ['simulation_ratings' => ['catching' => 88, 'ball_security' => 91]]);
    $receiver = arcadePlayer($home, 'WR', 'WR1', attributes: ['firstname' => 'Jordan', 'lastname' => 'Wells', 'simulation_ratings' => ['speed' => 94, 'acceleration' => 90, 'catching' => 89], 'appearance' => ['gloves' => 'white'], 'height_inches' => 73, 'weight_pounds' => 205]);
    arcadePlayer($home, 'WR', 'WR2');
    $linebacker = arcadePlayer($away, 'LB', 'LB1');
    $corner = arcadePlayer($away, 'CB', 'CB1', attributes: ['simulation_ratings' => ['coverage' => 93, 'awareness' => 89]]);
    arcadePlayer($away, 'S', 'S2');
    $tables = collect(Schema::getTableListing())->reject(fn ($table) => in_array($table, ['sessions', 'sqlite_sequence'], true));
    $before = $tables->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    foreach (range(1, 2) as $attempt) {
        $response = $this->get(route('arcade.drill', ['mode' => 'passing']));
        $response->assertOk()->assertViewIs('arcade.drill')->assertViewHas('mode', 'passing')
            ->assertViewHas('setupMessage', null)->assertViewHas('runner', null)->assertViewHas('runners', []);
        expect($response['quarterback']['id'])->toBe($quarterback->id)
            ->and($response['quarterback']['name'])->toBe('Caleb Reed')
            ->and($response['quarterback']['number'])->toBe($quarterback->id)
            ->and($response['quarterback']['position'])->toBe('QB')
            ->and($response['quarterback']['ratings'])->toBe(app(PlayerRatings::class)->forPlayer($quarterback))
            ->and($response['quarterback']['appearance'])->toBe(['visor' => 'clear'])
            ->and($response['quarterback']['height_inches'])->toBe(75)
            ->and($response['quarterback']['weight_pounds'])->toBe(225)
            ->and($response['quarterback']['skin_tone'])->toBe('#8c5536')
            ->and(array_column($response['receivers'], 'id'))->toBe([$receiver->id, $tightEnd->id])
            ->and($response['receivers'][0]['name'])->toBe('Jordan Wells')
            ->and($response['receivers'][0]['ratings'])->toBe(app(PlayerRatings::class)->forPlayer($receiver))
            ->and($response['receivers'][0]['appearance'])->toBe(['gloves' => 'white'])
            ->and($response['receivers'][0]['height_inches'])->toBe(73)
            ->and($response['receivers'][0]['weight_pounds'])->toBe(205)
            ->and(array_column($response['defenders'], 'id'))->toBe([$corner->id, $linebacker->id])
            ->and($response['defenders'][0]['ratings']['coverage'])->toBe(93);
    }

    expect($tables->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all())->toBe($before)
        ->and($writes)->toBe([])
        ->and($linebacker->fresh()->simulation_ratings)->toBeNull()
        ->and($linebacker->fresh()->height_inches)->toBeNull();
});

test('arcade passing selects a quarterback only from the selected latest home roster', function () {
    [$home, $away] = arcadeTeams();
    $historical = arcadePlayer($home, 'QB', 'QB1', '2029');
    $starter = arcadePlayer($home, 'QB', 'QB1');
    $selected = arcadePlayer($home, 'QB', 'QB2');
    $receiver = arcadePlayer($home, 'WR', 'WR1');
    $opponent = arcadePlayer($away, 'QB', 'QB1');
    arcadePlayer($away, 'DB', 'DB1');
    $this->get(route('arcade.drill', ['mode' => 'passing']))
        ->assertOk()->assertViewHas('quarterback', fn ($quarterback) => $quarterback['id'] === $starter->id);
    $this->get(route('arcade.drill', ['mode' => 'passing', 'home' => $home->id, 'away' => $away->id, 'quarterback' => $selected->id]))
        ->assertOk()->assertViewHas('quarterback', fn ($quarterback) => $quarterback['id'] === $selected->id);
    foreach ([$historical, $receiver, $opponent] as $ineligible) {
        $this->get(route('arcade.drill', ['mode' => 'passing', 'quarterback' => $ineligible->id]))->assertNotFound();
    }
    $this->getJson(route('arcade.drill', ['mode' => 'passing', 'home' => $home->id, 'away' => $home->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('away');
});

test('arcade passing cannot use a foreign world quarterback receiver or defense', function () {
    [$foreignHome, $foreignAway] = arcadeTeams();
    $foreignQuarterback = arcadePlayer($foreignHome, 'QB', 'QB1');
    arcadePlayer($foreignHome, 'WR', 'WR1');
    arcadePlayer($foreignAway, 'DB', 'DB1');
    openFootballSave(World::create(['name' => 'Current passing world']));
    [$home, $away] = arcadeTeams();
    $quarterback = arcadePlayer($home, 'QB', 'QB1');
    $receiver = arcadePlayer($home, 'TE', 'TE1');
    $defender = arcadePlayer($away, 'LB', 'LB1');
    $this->get(route('arcade.drill', ['mode' => 'passing']))->assertOk()
        ->assertViewHas('teams', fn ($teams) => $teams->pluck('id')->all() === [$home->id, $away->id])
        ->assertViewHas('quarterback', fn ($player) => $player['id'] === $quarterback->id)
        ->assertViewHas('receivers', fn ($players) => array_column($players, 'id') === [$receiver->id])
        ->assertViewHas('defenders', fn ($players) => array_column($players, 'id') === [$defender->id]);
    $this->get(route('arcade.drill', ['mode' => 'passing', 'quarterback' => $foreignQuarterback->id]))->assertNotFound();
    $this->get(route('arcade.drill', ['mode' => 'passing', 'home' => $foreignHome->id]))->assertNotFound();
    $this->get(route('arcade.drill', ['mode' => 'passing', 'away' => $foreignAway->id]))->assertNotFound();
});

test('arcade passing reports missing teams quarterback receiver and defender without synthetic players', function () {
    $world = World::firstOrFail();
    $query = ['mode' => 'passing'];
    $this->get(route('arcade.drill', $query))->assertOk()->assertViewHas('quarterback', null)->assertViewHas('receivers', [])
        ->assertViewHas('defenders', [])->assertViewHas('setupMessage', fn ($message) => str_contains($message, 'two teams'));
    openFootballSave($world);
    [$home, $away] = arcadeTeams();
    arcadePlayer($home, 'RB', 'RB1');
    $this->get(route('arcade.drill', $query))->assertOk()->assertViewHas('quarterback', null)
        ->assertViewHas('setupMessage', fn ($message) => str_contains($message, 'Add a QB'));
    openFootballSave($world);
    arcadePlayer($home, 'QB', 'QB1');
    $this->get(route('arcade.drill', $query))->assertOk()->assertViewHas('receivers', [])
        ->assertViewHas('setupMessage', fn ($message) => str_contains($message, 'WR or TE'));
    openFootballSave($world);
    arcadePlayer($home, 'TE', 'TE1');
    arcadePlayer($away, 'DE', 'DE1');
    $this->get(route('arcade.drill', $query))->assertOk()->assertViewHas('defenders', [])
        ->assertViewHas('setupMessage', fn ($message) => str_contains($message, 'defensive back'));
    openFootballSave($world);
    arcadePlayer($away, 'LB', 'LB1');
    $this->get(route('arcade.drill', $query))->assertOk()->assertViewHas('setupMessage', null)
        ->assertViewHas('receivers', fn ($players) => count($players) === 1)
        ->assertViewHas('defenders', fn ($players) => count($players) === 1);
});

test('arcade passing does not fill missing latest roster roles from historical players', function () {
    [$home, $away] = arcadeTeams();
    arcadePlayer($home, 'QB', 'QB1', '2029');
    arcadePlayer($home, 'WR', 'WR1', '2029');
    arcadePlayer($away, 'DB', 'DB1', '2029');
    arcadePlayer($home, 'RB', 'RB1', '2030');
    arcadePlayer($away, 'DE', 'DE1', '2030');
    $roster = app(\App\Services\Arcade\DrillRoster::class);
    expect($roster->quarterbacks($home))->toBe([])
        ->and($roster->receivers($home))->toBe([])
        ->and($roster->coverageDefenders($away))->toBe([]);
    $this->get(route('arcade.drill', ['mode' => 'passing']))->assertOk()->assertViewHas('quarterback', null)
        ->assertViewHas('receivers', [])->assertViewHas('defenders', [])
        ->assertViewHas('setupMessage', fn ($message) => str_contains($message, 'Add a QB'));
});

test('arcade speed scale is configurable bounded and safe for invalid environment values', function (string $value, float $expected) {
    $previousEnv = $_ENV['ARCADE_SPEED_SCALE'] ?? null;
    $previousServer = $_SERVER['ARCADE_SPEED_SCALE'] ?? null;
    $_ENV['ARCADE_SPEED_SCALE'] = $value;
    $_SERVER['ARCADE_SPEED_SCALE'] = $value;
    try {
        expect((require base_path('config/arcade.php'))['speed_scale'])->toBe($expected);
    } finally {
        if ($previousEnv === null) {
            unset($_ENV['ARCADE_SPEED_SCALE']);
        } else {
            $_ENV['ARCADE_SPEED_SCALE'] = $previousEnv;
        }
        if ($previousServer === null) {
            unset($_SERVER['ARCADE_SPEED_SCALE']);
        } else {
            $_SERVER['ARCADE_SPEED_SCALE'] = $previousServer;
        }
    }
})->with([
    'default multiplier' => ['0.925', 0.925],
    'custom multiplier' => ['0.98', 0.98],
    'lower bound' => ['-2', 0.75],
    'upper bound' => ['3', 1.1],
    'non-numeric fallback' => ['invalid', 0.925],
    'empty fallback' => ['', 0.925],
    'null fallback' => ['null', 0.925],
    'boolean fallback' => ['true', 0.925],
    'non-finite fallback' => ['1e999', 0.925],
]);

test('both arcade modes expose local replay controls with automatic highlights off by default', function (string $mode) {
    [$home, $away] = arcadeTeams();
    arcadePlayer($home, 'RB', 'RB1');
    arcadePlayer($home, 'QB', 'QB1');
    arcadePlayer($home, 'WR', 'WR1');
    arcadePlayer($away, 'DB', 'DB1');
    $this->get(route('arcade.drill', ['mode' => $mode]))->assertOk()
        ->assertSee('data-arcade-replay', false)
        ->assertSee('Open replay file')
        ->assertSee('data-arcade-import-file', false)
        ->assertSee('<option value="off" selected>Off</option>', false)
        ->assertSee('Replay files stay on this device.')
        ->assertDontSee('A replay viewer is planned separately.');
})->with(['running', 'passing']);
