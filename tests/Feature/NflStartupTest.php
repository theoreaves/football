<?php

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Models\World;
use App\Services\Simulation\NflRosterImport;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function nflTestBundle(): array
{
    $bundle = ['schema' => 1, 'season' => 2026, 'captured_at' => '2026-10-11T12:00:00Z', 'teams' => []];
    foreach (config('nfl-import') as $id => $identity) {
        $players = [];
        $slots = [];
        foreach (['QB' => 2, 'RB' => 2, 'WR' => 3, 'TE' => 1, 'OL' => 5, 'DL' => 4, 'LB' => 3, 'CB' => 2, 'S' => 2, 'PK' => 1, 'P' => 1] as $position => $count) {
            $slots[$position] = ['athletes' => []];
            for ($i = 0; $i < $count; $i++) {
                $key = (string) ($id * 1000 + count($players) + 1);
                $slots[$position]['athletes'][] = ['id' => $key];
                $players[] = ['id' => $key, 'firstName' => 'Real', 'lastName' => $identity['abbr'].' '.$position.' '.$i,
                    'height' => 76, 'weight' => 240, 'age' => 26, 'jersey' => (string) count($players),
                    'experience' => ['years' => 4], 'position' => ['abbreviation' => $position], 'status' => ['type' => 'active']];
            }
        }
        $bundle['teams'][$id] = [
            'roster' => ['season' => ['year' => 2026], 'team' => ['id' => (string) $id], 'coach' => [['firstName' => 'Head', 'lastName' => 'Coach']], 'athletes' => [['items' => $players]]],
            'depth' => ['season' => ['year' => 2026], 'team' => ['id' => (string) $id], 'depthchart' => [['positions' => $slots]]],
        ];
    }

    return $bundle;
}

beforeEach(function () {
    Storage::fake('team_art');
    Http::preventStrayRequests();
});

test('NFL startup resolves an existing owner and creates an isolated playable save from a reusable snapshot', function () {
    $owner = User::factory()->create(['name' => 'Theo Test', 'email' => 'theo-test@example.test']);
    $old = World::create(['name' => 'Keep this save', 'owner_user_id' => $owner->id]);
    app(CurrentWorld::class)->id = (int) $old->id;
    Team::create(['city' => 'Old', 'name' => 'Team']);
    $file = tempnam(sys_get_temp_dir(), 'nfl-test-');
    file_put_contents($file, json_encode(nflTestBundle()));
    try {
        $this->artisan('football:seed-nfl', ['user' => 'Theo Test', '--from' => $file, '--no-logos' => true])->assertSuccessful();
        expect(app(CurrentWorld::class)->id)->toBe((int) $old->id);
        expect(Team::count())->toBe(1);
        $new = World::where('name', 'NFL 2026')->firstOrFail();
        expect($new->owner_user_id)->toBe($owner->id);
        app(CurrentWorld::class)->id = (int) $new->id;
        expect(Team::count())->toBe(32)->and(Player::count())->toBe(832);
        $dallas = Team::where('abbr', 'DAL')->firstOrFail();
        expect($dallas->name)->toBe('Cowboys')->and($dallas->team_settings['white_at_home'])->toBeTrue();
        foreach (Team::get() as $team) {
            $roster = app(RosterBuilder::class)->build($team, '2026');
            expect($roster['players'])->toHaveKeys(['QB', 'K', 'P', 'WR1', 'S1']);
            expect($team->team_settings['nfl_import']['ratings_method'])->toContain('estimated');
        }
    } finally {
        unlink($file);
    }
});

test('NFL startup validates all data before writes and rejects ambiguous owners without network requests', function () {
    User::factory()->create(['name' => 'Duplicate']);
    User::factory()->create(['name' => 'Duplicate']);
    $this->artisan('football:seed-nfl', ['user' => 'Duplicate'])->assertFailed();
    $this->artisan('football:seed-nfl', ['user' => 'missing@example.test'])->assertFailed();
    Http::assertNothingSent();
    $owner = User::factory()->create(['email' => 'owner@example.test']);
    $bundle = nflTestBundle();
    $bundle['teams'][6]['roster']['season']['year'] = 2025;
    $file = tempnam(sys_get_temp_dir(), 'nfl-invalid-');
    file_put_contents($file, json_encode($bundle));
    try {
        $this->artisan('football:seed-nfl', ['user' => $owner->email, '--from' => $file, '--no-logos' => true])->assertFailed();
        expect(World::count())->toBe(0);
    } finally {
        unlink($file);
    }
});

test('NFL estimates and depth assignment are deterministic and reserve jersey conflicts preserve source data', function () {
    $importer = app(NflRosterImport::class);
    $bundle = nflTestBundle();
    $players = &$bundle['teams'][6]['roster']['athletes'][0]['items'];
    $players[1]['jersey'] = $players[0]['jersey'];
    $players[2]['status']['type'] = 'practice-squad';
    $data = $importer->prepare($bundle);
    $again = $importer->prepare($bundle);
    expect($data)->toBe($again);
    $qbs = array_values(array_filter($data[6]['players'], fn ($entry) => $entry['player']['position'] === 'QB'));
    expect($qbs[0]['roster']['jersey_number'])->toBe(0)->and($qbs[1]['roster']['jersey_number'])->toBeNull();
    expect($qbs[1]['player']['appearance']['nfl_import']['source_jersey'])->toBe('0');
    expect(count($importer->prepare($bundle, true)[6]['players']))->toBe(count($data[6]['players']) + 1);
    foreach ($data as $team) {
        foreach ($team['players'] as $entry) {
            expect(array_keys($entry['player']['simulation_ratings']))->toBe(PlayerRatings::FIELDS);
            foreach ($entry['player']['simulation_ratings'] as $rating) {
                expect($rating)->toBeBetween(10, 99);
            }
        }
    }
});

test('NFL live download requests 2026 rosters and depth and dry runs create no save', function () {
    $owner = User::factory()->create(['email' => 'live@example.test']);
    $bundle = nflTestBundle();
    Http::fake(function ($request) use ($bundle) {
        preg_match('~/teams/(\d+)/(roster|depthcharts)~', $request->url(), $match);
        if (! $match || ! str_contains($request->url(), 'season=2026')) {
            throw new RuntimeException('Unexpected request');
        }

        return Http::response($bundle['teams'][(int) $match[1]][$match[2] === 'roster' ? 'roster' : 'depth']);
    });
    $this->artisan('football:seed-nfl', ['user' => $owner->email, '--dry-run' => true])->assertSuccessful();
    Http::assertSentCount(64);
    expect(World::count())->toBe(0);
});

test('NFL startup stores real team logo slots and refuses to overwrite a roster snapshot', function () {
    $owner = User::factory()->create(['email' => 'logos@example.test']);
    $file = tempnam(sys_get_temp_dir(), 'nfl-logos-');
    file_put_contents($file, json_encode(nflTestBundle()));
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRyQAAAAASUVORK5CYII=');
    Http::fake(['https://a.espncdn.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    try {
        $this->artisan('football:seed-nfl', ['user' => $owner->email, '--from' => $file])->assertSuccessful();
        Http::assertSentCount(32);
        $world = World::where('owner_user_id', $owner->id)->firstOrFail();
        app(CurrentWorld::class)->id = (int) $world->id;
        $dallas = Team::where('abbr', 'DAL')->firstOrFail();
        Storage::disk('team_art')->assertExists($dallas->team_logo);
        expect($dallas->helmet_logo_left)->toBe($dallas->team_logo)->and($dallas->midfield_logo)->toBe($dallas->team_logo);
        expect(Team::where('abbr', 'MIA')->firstOrFail()->team_settings['white_at_home'])->toBeTrue();
        expect(Team::where('abbr', 'WSH')->firstOrFail()->team_settings['white_at_home'])->toBeFalse();
        $this->artisan('football:seed-nfl', ['user' => $owner->email, '--from' => $file, '--snapshot' => $file, '--dry-run' => true])->assertFailed();
        expect(World::count())->toBe(1);
    } finally {
        unlink($file);
    }
});

test('failed NFL logo downloads cannot leave a partial save', function () {
    $owner = User::factory()->create(['email' => 'failure@example.test']);
    $file = tempnam(sys_get_temp_dir(), 'nfl-failure-');
    file_put_contents($file, json_encode(nflTestBundle()));
    Http::fake(['https://a.espncdn.com/*' => Http::response('not an image', 200)]);
    try {
        $this->artisan('football:seed-nfl', ['user' => $owner->email, '--from' => $file])->assertFailed();
        expect(World::count())->toBe(0);
        expect(Storage::disk('team_art')->allFiles())->toBe([]);
    } finally {
        unlink($file);
    }
});
