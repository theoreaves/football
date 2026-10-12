<?php

use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\NflPresentationDefaults;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
});

function nflPresentationTeams()
{
    return collect(config('nfl-import'))->map(function ($identity) {
        $entry = config('pro-football.teams')[$identity['template']];

        return Team::create(['city' => $identity['city'], 'name' => $identity['name'], 'abbr' => $identity['abbr'],
            'conference' => $entry[3], 'division' => $entry[4], 'team_color1' => $entry[5],
            'team_settings' => ['nfl_import' => ['source' => 'ESPN']]]);
    })->values();
}

test('NFL season setup offers real labels and geographically correct team assignments', function () {
    $world = World::create(['name' => 'NFL']);
    openFootballSave($world);
    League::create(['name' => 'National Football League']);
    $teams = nflPresentationTeams();
    $setup = app(NflPresentationDefaults::class)->setup($teams);
    expect($setup['groups']['A1']['conference'])->toBe('AFC');
    foreach ($teams as $team) {
        $group = $setup['groups'][$setup['assignments'][$team->id]];
        expect($group['conference'])->toBe($team->conference)->and($group['division'])->toBe($team->division);
    }
    $this->get(route('seasons.create'))->assertOk()->assertSee('National Football League')->assertSee('value="AFC"', false)->assertSee('value="NFC"', false)->assertSee('value="East"', false)->assertSee('NFL 2026')->assertSee('data-default-group=', false);
    expect(app(NflPresentationDefaults::class)->setup($teams->take(31)))->toBeNull();
});

test('existing save updates are scoped repeatable and preserve custom presentation and season progress', function () {
    $other = World::create(['name' => 'Other']);
    app(CurrentWorld::class)->id = $other->id;
    $untouched = Team::create(['city' => 'Other', 'name' => 'Team', 'abbr' => 'BUF']);
    $world = World::create(['name' => 'NFL']);
    app(CurrentWorld::class)->id = $world->id;
    $teams = nflPresentationTeams();
    $league = League::create(['name' => 'NFL']);
    $setup = app(NflPresentationDefaults::class)->setup($teams);
    $members = [];
    foreach ($teams as $team) {
        $key = $setup['assignments'][$team->id];
        $members[$team->id] = ['name' => $team->name, 'group' => $key, 'conference' => $key[0], 'conference_name' => 'Conference '.$key[0], 'division' => 'Division '.$key[1], 'control' => 'cpu'];
    }
    $season = Season::create(['league_id' => $league->id, 'year' => 2026, 'current_week' => 8, 'phase' => 'regular_season', 'settings' => ['members' => $members, 'games' => 17, 'playoffs' => 'nfl14']]);
    $dallas = $teams->firstWhere('abbr', 'DAL');
    $settings = $dallas->team_settings;
    $settings['stadium']['name'] = 'My stadium';
    $settings['mascot'] = ['name' => 'My mascot', 'character' => 'king'];
    $dallas->update(['team_settings' => $settings]);
    app(CurrentWorld::class)->id = $other->id;
    $this->artisan('football:apply-nfl-presentation', ['world' => $world->id])->assertSuccessful();
    expect(app(CurrentWorld::class)->id)->toBe($other->id)->and($untouched->fresh()->team_settings)->toBeNull();
    app(CurrentWorld::class)->id = $world->id;
    $teams = Team::get();
    expect($league->fresh()->name)->toBe('National Football League')->and($season->fresh()->current_week)->toBe(8);
    foreach ($teams as $team) {
        expect($season->fresh()->settings['members'][$team->id]['conference_name'])->toBe($team->conference);
        expect($season->fresh()->settings['members'][$team->id]['division'])->toBe($team->division);
    }
    expect($teams->firstWhere('abbr', 'HOU')->team_settings['stadium']['name'])->toBe('Reliant Stadium');
    expect($teams->firstWhere('abbr', 'BUF')->team_settings['mascot']['character'])->toBe('buffalo');
    foreach (['NYG', 'NYJ', 'GB', 'LAC'] as $abbr) {
        expect($teams->firstWhere('abbr', $abbr)->team_settings['mascot']['enabled'])->toBeFalse();
    }
    expect($dallas->fresh()->team_settings['mascot']['name'])->toBe('My mascot')->and($dallas->fresh()->team_settings['stadium']['name'])->toBe('My stadium');
    $before = Team::get()->toArray();
    $this->artisan('football:apply-nfl-presentation', ['world' => $world->id])->assertSuccessful();
    expect(Team::get()->toArray())->toBe($before);
    $this->artisan('football:apply-nfl-presentation', ['world' => $world->id, '--replace-custom' => true])->assertSuccessful();
    expect($dallas->fresh()->team_settings['mascot']['name'])->toBe('Rowdy')->and($dallas->fresh()->team_settings['stadium']['name'])->toBe('AT&T Stadium');
});

test('mixed existing season groups are never moved or relabeled as real NFL divisions', function () {
    $world = World::create(['name' => 'Mixed NFL']);
    app(CurrentWorld::class)->id = $world->id;
    $teams = nflPresentationTeams();
    $league = League::create(['name' => 'NFL']);
    $buffalo = $teams->firstWhere('abbr', 'BUF');
    $dallas = $teams->firstWhere('abbr', 'DAL');
    $members = [];
    foreach ([$buffalo, $dallas] as $team) {
        $members[$team->id] = ['group' => 'A1', 'conference' => 'A', 'conference_name' => 'Conference A', 'division' => 'Division 1', 'control' => 'human'];
    }
    $season = Season::create(['league_id' => $league->id, 'year' => 2026, 'settings' => ['members' => $members]]);
    $this->artisan('football:apply-nfl-presentation', ['world' => $world->id, '--replace-custom' => true])->assertSuccessful();
    expect($season->fresh()->settings['members'])->toBe($members);
});
