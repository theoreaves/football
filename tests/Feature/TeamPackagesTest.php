<?php

use App\Models\CommunityPackage;
use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\User;
use App\Models\World;
use App\Services\TeamPackages;
use App\Services\WorldPackages;
use App\Support\CurrentWorld;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('team_art');
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->world = World::create(['name' => 'Source World', 'owner_user_id' => $this->user->id]);
    $this->withSession(['current_world_id' => $this->world->id]);
    app(CurrentWorld::class)->id = $this->world->id;
    $this->team = Team::create(['city' => 'Test', 'name' => 'Warriors', 'conference' => 'A', 'division' => 'West']);
    app(TeamPackages::class)->importRoster($this->team, app(TeamPackages::class)->readRoster(app(TeamPackages::class)->starterRoster()), 2016, false);
});

test('roster CSV round trips editable player fields and exact ratings into another year', function () {
    $service = app(TeamPackages::class);
    $csv = $service->roster($this->team, '2016');
    expect($csv)->toContain('rating_throwing')->toContain('depth_chart_position');
    $rows = $service->readRoster(str_replace('Quarterback', 'Edited Player', $csv));
    $old = $this->team->players()->first();
    $service->importRoster($this->team, $rows, 2017, false);
    $new = $this->team->players()->wherePivot('team_year', '2017')->first();
    expect($new->id)->not->toBe($old->id);
    expect($new->lastname)->toBe('Edited Player');
    expect($new->simulation_ratings)->toBe($old->simulation_ratings);
    expect($this->team->players()->wherePivot('team_year', '2016')->count())->toBe(1);
});

test('bad CSV reports row errors and duplicate jerseys before changing data', function () {
    $service = app(TeamPackages::class);
    $csv = $service->starterRoster();
    $lines = explode("\n", trim($csv));
    expect(fn () => $service->readRoster($csv.$lines[1]."\n"))->toThrow(ValidationException::class, 'duplicate jersey');
    expect(fn () => $service->readRoster(str_replace(',25,QB,', ',999,QB,', $csv)))->toThrow(ValidationException::class, 'row 2');
    expect(TeamPlayer::count())->toBe(1);
});

test('append detects destination jersey conflicts and replace affects only selected roster year', function () {
    $service = app(TeamPackages::class);
    $rows = $service->readRoster($service->starterRoster());
    expect(fn () => $service->importRoster($this->team, $rows, 2016, false))->toThrow(ValidationException::class, 'already exists');
    $service->importRoster($this->team, $rows, 2017, false);
    $oldId = $this->team->players()->wherePivot('team_year', '2016')->value('players.id');
    $service->importRoster($this->team, $rows, 2016, true);
    expect($this->team->players()->count())->toBe(2);
    expect($this->team->players()->wherePivot('team_year', '2016')->value('players.id'))->not->toBe($oldId);
});

test('a team ZIP preserves independent art, SVG, stadium and remapped specialists', function () {
    $service = app(WorldPackages::class);
    Storage::disk('team_art')->put('teams/source/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0 L10 10"/></svg>');
    $player = $this->team->players()->first();
    $this->team->update(['team_logo' => 'teams/source/logo.svg', 'team_settings' => ['stadium' => ['capacity' => 60000], 'coaching' => ['pass' => 80], 'specialists' => ['K' => $player->id]]]);
    $path = $service->export($this->team);
    $package = $service->read($path);
    expect($package['teams'][0]['data']['team_logo'])->toStartWith('teams/team-1/assets/');
    $destination = World::create(['name' => 'Destination', 'owner_user_id' => $this->user->id]);
    $copy = $service->withinWorld($destination->id, fn () => $service->import($package, 2017)[0]);
    expect($copy->world_id)->toBe($destination->id);
    expect($copy->team_logo)->not->toBe($this->team->team_logo);
    expect($copy->team_settings['stadium']['capacity'])->toBe(60000);
    $service->withinWorld($destination->id, function () use ($copy, $player) {
        $new = $copy->players()->first();
        expect($new->id)->not->toBe($player->id);
        expect($copy->team_settings['specialists']['K'])->toBe($new->id);
    });
    Storage::disk('team_art')->assertExists($copy->team_logo);
});

test('world ZIP preserves multiple league assignments and does not import progress', function () {
    $first = League::create(['name' => 'League One']);
    $second = League::create(['name' => 'League Two']);
    $this->team->update(['league_id' => $second->id]);
    Season::create(['league_id' => $second->id, 'year' => 2016, 'current_week' => 5, 'phase' => 'regular']);
    $service = app(WorldPackages::class);
    $package = $service->read($service->export($this->world));
    $copy = $service->importWorld($package, '2017 World', $this->user->id, 2017);
    $service->withinWorld($copy->id, function () {
        expect(League::count())->toBe(2);
        expect(Season::count())->toBe(0);
        $team = Team::first();
        expect(League::find($team->league_id)->name)->toBe('League Two');
        expect($team->players()->first()->pivot->team_year)->toBe('2016');
        expect($team->conference)->toBe('A');
    });
});

test('replacement preserves team ID and alignment', function () {
    $service = app(WorldPackages::class);
    $package = $service->read($service->export($this->team));
    $replacement = Team::create(['city' => 'Other', 'name' => 'Knights', 'conference' => 'B', 'division' => 'East']);
    $id = $replacement->id;
    $service->import($package, 2017, $replacement);
    expect($replacement->fresh()->id)->toBe($id);
    expect($replacement->fresh()->division)->toBe('East');
    expect($replacement->fresh()->name)->toBe('Warriors');
});

test('ZIP import rejects missing assets, active SVG and forged identifiers', function () {
    $service = app(WorldPackages::class);
    foreach (['missing', 'active', 'identifier'] as $kind) {
        $path = $service->newPath();
        $zip = new PharData($path, 0, null, Phar::ZIP);
        $zip->addFromString('package.json', json_encode(['format' => 'websports-football', 'version' => 1, 'kind' => 'team', 'name' => 'Bad', 'teams' => [['directory' => 'team']], 'leagues' => []]));
        $data = ['city' => 'Test', 'name' => 'Bad'];
        if ($kind === 'identifier') {
            $data['world_id'] = $this->world->id;
        } else {
            $data['team_logo'] = 'assets/bad.svg';
            if ($kind === 'active') {
                $zip->addFromString('assets/bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
            }
        }
        $zip->addFromString('team/team.json', json_encode($data));
        $zip->addFromString('team/roster.csv', app(TeamPackages::class)->starterRoster());
        unset($zip);
        expect(fn () => $service->read($path))->toThrow(ValidationException::class);
    }
    expect(Team::count())->toBe(1);
});

test('upload preview and confirmation import CSV through authenticated routes', function () {
    $this->get(route('packages.index'))->assertOk()->assertSee('Share roster only');
    $csv = app(TeamPackages::class)->starterRoster();
    $this->post(route('packages.preview'), ['package' => UploadedFile::fake()->createWithContent('roster.csv', $csv)])
        ->assertOk()->assertSee('Confirm import')->assertSee('Quarterback');
    $token = session('package_preview.token');
    $this->post(route('packages.import'), ['token' => $token, 'team_id' => $this->team->id, 'year' => 2017, 'mode' => 'replace'])
        ->assertRedirect()->assertSessionMissing('package_preview');
    $this->assertDatabaseHas('team_players', ['team_id' => $this->team->id, 'team_year' => '2017']);
    $this->post(route('packages.import'), ['token' => $token, 'team_id' => $this->team->id, 'year' => 2017, 'mode' => 'replace'])->assertStatus(419);
});

test('another users teams and worlds cannot be exported or copied', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    $this->get(route('packages.team', $this->team->id))->assertNotFound();
    $this->get(route('packages.roster', $this->team->id))->assertNotFound();
    $this->get(route('packages.world', $this->world->id))->assertNotFound();
    $this->post(route('packages.copy', $this->team->id))->assertNotFound();
});

test('library publishes CSV snapshots that another user can independently import', function () {
    $this->post(route('packages.share'), ['team_id' => $this->team->id, 'kind' => 'roster', 'title' => '2016 Roster', 'year' => 2016])->assertRedirect(route('packages.index'));
    $listing = CommunityPackage::firstOrFail();
    expect($listing->kind)->toBe('roster');
    $other = User::factory()->create();
    $world = World::create(['name' => 'Other World', 'owner_user_id' => $other->id]);
    $team = app(WorldPackages::class)->withinWorld($world->id, fn () => Team::create(['city' => 'Other', 'name' => 'Owls']));
    $this->actingAs($other)->withSession(['current_world_id' => $world->id]);
    $this->delete(route('packages.unshare', $listing))->assertNotFound();
    $this->post(route('packages.library.copy', $listing))->assertOk();
    $this->post(route('packages.import'), ['token' => session('package_preview.token'), 'team_id' => $team->id, 'year' => 2017, 'mode' => 'add'])->assertRedirect();
    $this->assertDatabaseHas('players', ['world_id' => $world->id]);
    $this->assertDatabaseCount('community_packages', 1);
});

test('package creation guide and starter downloads work', function () {
    $this->get(route('packages.guide'))->assertOk()->assertSee('Create teams, worlds and roster files');
    $this->get(route('packages.starter', ['kind' => 'roster']))->assertDownload('roster-starter.csv');
    $response = $this->get(route('packages.starter', ['kind' => 'world']))->assertDownload('football-starter.zip');
    $package = app(WorldPackages::class)->read($response->baseResponse->getFile()->getPathname());
    expect($package['manifest']['kind'])->toBe('world');
});

test('historical world packages preserve multiple roster years for the same team', function () {
    $rosters = app(TeamPackages::class);
    $rows = $rosters->readRoster(str_replace('Quarterback', 'Next Year QB', $rosters->starterRoster()));
    $rosters->importRoster($this->team, $rows, 2017, false);
    $packages = app(WorldPackages::class);
    $copy = $packages->importWorld($packages->read($packages->export($this->world)), 'Historical NFL', $this->user->id, 2016);
    $packages->withinWorld($copy->id, function () {
        $team = Team::firstOrFail();
        expect($team->players()->wherePivot('team_year', '2016')->first()->lastname)->toBe('Quarterback');
        expect($team->players()->wherePivot('team_year', '2017')->first()->lastname)->toBe('Next Year QB');
        expect(Team::count())->toBe(1);
    });
});

test('team ZIP exported from a league-associated team remains independently importable', function () {
    $league = League::create(['name' => 'NFL']);
    $this->team->update(['league_id' => $league->id]);
    $package = app(WorldPackages::class)->read(app(WorldPackages::class)->export($this->team));
    expect($package['manifest']['kind'])->toBe('team');
});

test('real starter SVG graphics round trip without losing the roster', function () {
    Storage::disk('team_art')->put('teams/logo.svg', file_get_contents(resource_path('team-logos/pro/arf.svg')));
    $this->team->update(['team_logo' => 'teams/logo.svg']);
    $package = app(WorldPackages::class)->read(app(WorldPackages::class)->export($this->team));
    expect(count($package['teams'][0]['roster']))->toBe(1);
});

test('world upload creates an owned world and opens season setup with the chosen year', function () {
    $path = app(WorldPackages::class)->export($this->world);
    $this->post(route('packages.preview'), ['package' => new UploadedFile($path, 'NFL88.zip', 'application/zip', null, true)])
        ->assertOk()->assertSee('First season year');
    $this->post(route('packages.import'), ['token' => session('package_preview.token'), 'world_name' => 'NFL88', 'year' => 1988, 'mode' => 'add'])
        ->assertRedirect(route('seasons.create', ['year' => 1988]));
    $world = World::where('name', 'NFL88')->firstOrFail();
    expect($world->owner_user_id)->toBe($this->user->id);
    $this->get(route('seasons.create', ['year' => 1988]))->assertOk();
});

test('copying between owned worlds uses the current destination and preserves the source', function () {
    $destination = World::create(['name' => 'Target', 'owner_user_id' => $this->user->id]);
    $this->withSession(['current_world_id' => $destination->id]);
    $this->post(route('packages.copy', $this->team->id))->assertOk()->assertSee('Warriors');
    $this->post(route('packages.import'), ['token' => session('package_preview.token'), 'year' => 2017, 'mode' => 'add'])->assertRedirect(route('teams.index'));
    expect(Team::withoutGlobalScope('world')->where('world_id', $destination->id)->count())->toBe(1);
    expect(Team::withoutGlobalScope('world')->where('world_id', $this->world->id)->count())->toBe(1);
});

test('roster preview cannot be confirmed against a team outside the open world', function () {
    $otherWorld = World::create(['name' => 'Other', 'owner_user_id' => $this->user->id]);
    $otherTeam = app(WorldPackages::class)->withinWorld($otherWorld->id, fn () => Team::create(['city' => 'Elsewhere', 'name' => 'Owls']));
    $this->post(route('packages.preview'), ['package' => UploadedFile::fake()->createWithContent('Bills88.csv', app(TeamPackages::class)->starterRoster())])->assertOk();
    $this->post(route('packages.import'), ['token' => session('package_preview.token'), 'year' => 1988, 'team_id' => $otherTeam->id, 'mode' => 'replace'])->assertNotFound();
    $this->assertDatabaseMissing('team_players', ['team_id' => $otherTeam->id]);
});

test('imported custom league alignment prefills season setup and the chosen roster year', function () {
    $league = League::create(['name' => 'Historical League']);
    $this->team->update(['league_id' => $league->id, 'conference' => 'Historical East', 'division' => 'One']);
    foreach ([['Historical East', 'One'], ['Historical West', 'Two'], ['Historical West', 'Two']] as $i => $alignment) {
        Team::create(['city' => 'City '.$i, 'name' => 'Team '.$i, 'league_id' => $league->id, 'conference' => $alignment[0], 'division' => $alignment[1]]);
    }
    $this->get(route('seasons.create', ['league' => $league->id, 'year' => 1988]))->assertOk()
        ->assertSee('Historical East')->assertSee('Historical West')->assertSee('value="1988"', false);
});

test('roster download uses the selected historical year and protects spreadsheet formulas', function () {
    $player = $this->team->players()->first();
    $player->update(['lastname' => '=SUM(1,2)']);
    $response = $this->get(route('packages.roster', ['team' => $this->team->id, 'year' => 2016]));
    $response->assertDownload('test-warriors-2016-roster.csv');
    $csv = $response->streamedContent();
    expect($csv)->toContain("'=SUM(1,2)");
    expect(app(TeamPackages::class)->readRoster($csv)[0]['player']['lastname'])->toBe('=SUM(1,2)');
});

test('replacing a historical roster leaves later year specialists and graphics intact', function () {
    $service = app(TeamPackages::class);
    $rows = $service->readRoster($service->starterRoster());
    $service->importRoster($this->team, $rows, 2017, false);
    $newer = $this->team->players()->wherePivot('team_year', '2017')->first();
    $this->team->update(['team_logo' => 'teams/unchanged.png', 'team_settings' => ['coaching' => ['pass' => 75], 'specialists' => ['fourth_down_back' => $newer->id]]]);
    $service->importRoster($this->team, $rows, 2016, true);
    expect($this->team->fresh()->team_logo)->toBe('teams/unchanged.png');
    expect($this->team->fresh()->team_settings['specialists']['fourth_down_back'])->toBe($newer->id);
    expect($this->team->fresh()->team_settings['coaching']['pass'])->toBe(75);
});

test('team library sharing publishes a frozen ZIP and the owner can unshare it', function () {
    $this->get(route('packages.share.form', ['team' => $this->team->id]))->assertOk()->assertSee('Publish to library');
    $this->post(route('packages.share'), ['team_id' => $this->team->id, 'kind' => 'team', 'title' => 'Warriors 2016'])->assertRedirect(route('packages.index'));
    $listing = CommunityPackage::firstOrFail();
    app(WorldPackages::class)->withinWorld($this->world->id, fn () => $this->team->update(['name' => 'Changed']));
    $package = app(WorldPackages::class)->read(Storage::disk('local')->path($listing->path));
    expect($package['teams'][0]['data']['name'])->toBe('Warriors');
    $this->delete(route('packages.unshare', $listing))->assertRedirect();
    Storage::disk('local')->assertMissing($listing->path);
});

test('team ZIP preview accepts storage paths reached through a deployment symlink', function () {
    $service = app(WorldPackages::class);
    $path = $service->export($this->team);
    $link = dirname($path).'-linked';
    symlink(dirname($path), $link);
    try {
        $package = $service->read($link.'/'.basename($path));
        expect($package['manifest']['kind'])->toBe('team');
        expect($package['teams'][0]['data']['name'])->toBe('Warriors');
    } finally {
        unlink($link);
    }
});

test('world download builds a complete multi-team archive and cleans its staging files', function () {
    $service = app(WorldPackages::class);
    $rosters = app(TeamPackages::class);
    Storage::disk('team_art')->put('teams/shared.svg', file_get_contents(resource_path('team-logos/pro/arf.svg')));
    $rows = $rosters->readRoster($rosters->starterRoster());
    for ($i = 0; $i < 31; $i++) {
        $team = Team::create(['city' => 'City '.$i, 'name' => 'Team '.$i, 'team_logo' => 'teams/shared.svg']);
        $rosters->importRoster($team, $rows, 1988, false);
    }
    $response = $this->get(route('packages.world', $this->world->id))->assertDownload('source-world.zip');
    $path = $response->baseResponse->getFile()->getPathname();
    $package = $service->read($path);
    expect(count($package['teams']))->toBe(32);
    expect(is_dir($path.'.files'))->toBeFalse();
});

test('missing league and team artwork does not block world download or alter its source', function () {
    $league = League::create(['name' => 'NFL', 'logo_path' => 'teams/1/nfl-logo.png']);
    $this->team->update(['team_logo' => 'teams/missing-logo.png']);
    $response = $this->get(route('packages.world', $this->world->id))->assertDownload('source-world.zip');
    $path = $response->baseResponse->getFile()->getPathname();
    $package = app(WorldPackages::class)->read($path);
    expect($package['manifest']['leagues'][0]['logo'])->toBeNull();
    expect($package['teams'][0]['data']['team_logo'])->toBeNull();
    expect(count($package['teams'][0]['roster']))->toBe(1);
    expect($package['manifest']['warnings'])->toHaveCount(2);
    expect($package['files']['EXPORT_WARNINGS.txt'])->toContain('teams/1/nfl-logo.png');
    expect($league->fresh()->logo_path)->toBe('teams/1/nfl-logo.png');
    expect($this->team->fresh()->team_logo)->toBe('teams/missing-logo.png');
});
