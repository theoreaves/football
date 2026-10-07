<?php

use App\Models\Exhibition;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Models\World;
use App\Services\Simulation\MiddleEarthRosterGenerator;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\ProRosterGenerator;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
    \Illuminate\Support\Facades\Storage::fake('team_art');
});

test('pro template contains fictional identities and four teams per NFL-style division', function () {
    $teams = collect(config('pro-football.teams'));
    expect($teams)->toHaveCount(32)->and($teams->pluck(2)->unique())->toHaveCount(32);
    $realNames = ['Bills', 'Dolphins', 'Patriots', 'Jets', 'Ravens', 'Bengals', 'Browns', 'Steelers', 'Texans', 'Colts', 'Jaguars', 'Titans', 'Broncos', 'Chiefs', 'Raiders', 'Chargers', 'Cowboys', 'Giants', 'Eagles', 'Commanders', 'Bears', 'Lions', 'Packers', 'Vikings', 'Falcons', 'Panthers', 'Saints', 'Buccaneers', 'Cardinals', 'Rams', '49ers', 'Seahawks'];
    foreach ($teams as $team) {
        expect($team[1])->not->toBeIn($realNames);
        foreach (array_slice($team, 5) as $color) {
            expect($color)->toMatch('/^#[a-f0-9]{6}$/');
        }
    }
    $divisions = $teams->groupBy(fn ($team) => $team[3].':'.$team[4]);
    expect($divisions)->toHaveCount(8);
    foreach ($divisions as $division) {
        expect($division)->toHaveCount(4);
    }
    expect($teams->first(fn ($t) => $t[0] === 'Kansas City')[1])->toBe('Warriors');
    expect($teams->first(fn ($t) => $t[0] === 'New York' && $t[3] === 'AFC')[1])->toBe('Planes');
});

test('startup picker creates a private pro league with complete playable rosters', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $existing = World::create(['name' => 'Old save', 'owner_user_id' => $user->id]);
    app(CurrentWorld::class)->id = $existing->id;
    $oldTeam = Team::create(['city' => 'Old', 'name' => 'Club']);
    $this->actingAs($user)->get('/worlds')->assertOk()->assertSee('32 NFL-inspired fictional teams');
    $this->post('/worlds', ['name' => 'Pro Test', 'league_name' => 'Fictional Pro League', 'year' => 2031, 'preset' => 'pro', 'owner_user_id' => $other->id])->assertRedirect(route('home'));
    $world = World::where('name', 'Pro Test')->firstOrFail();
    expect($world->owner_user_id)->toBe($user->id);
    app(CurrentWorld::class)->id = $world->id;
    expect(Team::count())->toBe(32)->and(Player::count())->toBe(1696);
    $builder = app(RosterBuilder::class);
    foreach (Team::all() as $team) {
        foreach (['team_logo', 'helmet_logo_left', 'helmet_logo_right', 'midfield_logo'] as $field) {
            expect($team->{$field})->not->toBeNull();
            \Illuminate\Support\Facades\Storage::disk('team_art')->assertExists($team->{$field});
        }
        expect(collect([$team->team_logo, $team->helmet_logo_left, $team->helmet_logo_right, $team->midfield_logo])->unique())->toHaveCount(4);
        expect((bool) $team->uniform_home_name_enabled)->toBeTrue()->and((bool) $team->uniform_away_pants_stripe_enabled)->toBeTrue()
            ->and($team->uniform_home_helmet_stripe)->not->toBe($team->uniform_home_helmet);
        $roster = $builder->build($team);
        expect($roster['year'])->toBe('2031')->and($roster['pool'])->toHaveCount(53)->and($roster['players'])->toHaveCount(28);
        foreach ($roster['pool'] as $player) {
            foreach (PlayerRatings::FIELDS as $field) {
                expect($player['ratings'][$field])->toBeBetween(1, 99);
            }
        }
        expect($team->uniform_away_shirt)->toBe('#ffffff')->and($team->endzone_text)->toBe(strtoupper($team->name));
    }
    $branded = Team::first();
    $this->get(route('teams.art', [$branded, 'team_logo']))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $oldHelmet = $branded->helmet_logo_left;
    $this->put(route('teams.editor.update', $branded), ['city' => $branded->city, 'name' => $branded->name, 'clear_helmet_logo_left' => true])->assertRedirect();
    \Illuminate\Support\Facades\Storage::disk('team_art')->assertMissing($oldHelmet);
    foreach (['team_logo', 'helmet_logo_right', 'midfield_logo'] as $field) {
        \Illuminate\Support\Facades\Storage::disk('team_art')->assertExists($branded->{$field});
    }
    $this->assertDatabaseHas('teams', ['id' => $oldTeam->id, 'world_id' => $existing->id, 'name' => 'Club']);
    app(CurrentWorld::class)->id = $world->id;
    $teams = Team::whereIn('abbr', ['KCW', 'NYP'])->get();
    $this->post(route('exhibitions.store'), ['home' => $teams[0]->id, 'away' => $teams[1]->id, 'quarter_length' => 180])->assertRedirect();
    $game = Exhibition::withoutGlobalScopes()->latest('id')->firstOrFail();
    $this->post(route('exhibitions.play', $game), ['version' => 0, 'call' => 'kickoff', 'defense' => 'kickoff_return'])->assertRedirect();
    expect($game->fresh()->history[0]['animation']['players'])->toHaveCount(22);
    $this->actingAs($other)->withSession(['current_world_id' => null])->get('/worlds')->assertDontSee('Pro Test');
    $this->post('/worlds/'.$world->id.'/select')->assertNotFound();
});

test('pro command validates ownership and refuses to overwrite an occupied save', function () {
    $user = User::factory()->create(['email' => 'coach@example.com']);
    $previous = World::create(['name' => 'Previous', 'owner_user_id' => $user->id]);
    app(CurrentWorld::class)->id = $previous->id;
    $this->artisan('football:seed-pro-league', ['--owner' => $user->email, '--seed' => 42])->assertSuccessful();
    expect(app(CurrentWorld::class)->id)->toBe($previous->id);
    $world = World::where('name', 'Pro Football')->firstOrFail();
    expect($world->owner_user_id)->toBe($user->id);
    $this->artisan('football:seed-pro-league', ['world' => $world->id])->assertFailed();
    $this->assertDatabaseCount('teams', 32);
    $this->assertDatabaseCount('players', 1696);
    $this->artisan('football:seed-pro-league', ['--owner' => 'missing@example.com'])->assertFailed();
    $this->artisan('football:seed-pro-league', ['--owner' => $user->email, '--year' => 1800])->assertFailed();
    $this->assertDatabaseCount('worlds', 2);
});

test('pro rosters have reproducible names ratings and depth while Middle Earth names retain their generator', function () {
    $pro = app(ProRosterGenerator::class);
    $first = $pro->generate(42);
    expect($first)->toHaveCount(53)->and($pro->generate(42))->toBe($first)->and($pro->generate(43))->not->toBe($first);
    expect(collect($first)->pluck('player.firstname')->contains('Aradan'))->toBeFalse();
    $fantasy = app(MiddleEarthRosterGenerator::class)->generate(42);
    expect($fantasy)->not->toBe($first);
    $depths = collect($first)->pluck('roster.depth_chart_position');
    expect($depths)->toContain('QB1', 'QB2', 'WR1', 'WR6', 'K1', 'P1');
});

test('pro appearance defaults enable names and visible stripes and distribute all ten stadiums', function () {
    $appearance = app(\App\Services\Simulation\ProTeamAppearance::class);
    $styles = [];
    foreach (config('pro-football.teams') as $entry) {
        $settings = $appearance->defaults($entry);
        $styles[] = $settings['stadium_style'];
        foreach (['home', 'away'] as $venue) {
            expect($settings["uniform_{$venue}_name_enabled"])->toBeTrue();
            foreach (['helmet', 'shoulder', 'pants'] as $part) {
                expect($settings["uniform_{$venue}_{$part}_stripe_enabled"])->toBeTrue();
            }
            expect($settings["uniform_{$venue}_helmet_stripe"])->not->toBe($entry[8]);
            expect($settings["uniform_{$venue}_pants_stripe"])->not->toBe($entry[9]);
        }
    }
    expect(array_unique($styles))->toHaveCount(10);
});

test('existing-save appearance updates matching pro teams only in the requested save', function () {
    $owner = User::factory()->create();
    $world = World::create(['name' => 'Pro', 'owner_user_id' => $owner->id]);
    app(CurrentWorld::class)->id = $world->id;
    $pro = Team::create(['city' => 'Minnesota', 'name' => 'Norsemen', 'abbr' => 'MIN', 'team_logo' => 'custom.png']);
    $custom = Team::create(['city' => 'Custom', 'name' => 'Bison', 'abbr' => 'BFB']);
    $other = World::create(['name' => 'Other', 'owner_user_id' => $owner->id]);
    app(CurrentWorld::class)->id = $other->id;
    $outside = Team::create(['city' => 'Minnesota', 'name' => 'Norsemen', 'abbr' => 'MIN']);
    $this->artisan('football:apply-pro-appearance', ['world' => $world->id])->assertSuccessful();
    expect(app(CurrentWorld::class)->id)->toBe($other->id);
    $this->assertDatabaseHas('teams', ['id' => $pro->id, 'stadium_style' => 'indoor_dome', 'uniform_home_name_enabled' => 1, 'uniform_away_helmet_stripe_enabled' => 1, 'team_logo' => 'custom.png']);
    $this->assertDatabaseHas('teams', ['id' => $custom->id, 'stadium_style' => 'classic_oval', 'uniform_home_name_enabled' => 0]);
    $this->assertDatabaseHas('teams', ['id' => $outside->id, 'stadium_style' => 'classic_oval', 'uniform_home_name_enabled' => 0]);
});
