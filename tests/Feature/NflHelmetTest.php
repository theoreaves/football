<?php

use App\Http\Controllers\PracticeController;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\NflHelmetDefaults;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('team_art');
});

test('NFL helmet defaults configure directional white blank striped and paired letter decals', function () {
    $world = World::create(['name' => 'Helmet test']);
    app(CurrentWorld::class)->id = (int) $world->id;
    foreach (['KC', 'BAL', 'PHI', 'PIT', 'CLE', 'NYG', 'NYJ', 'ATL', 'BUF', 'DEN', 'DET', 'MIA', 'CIN', 'LAR'] as $abbr) {
        $team = Team::create(['city' => 'Test', 'name' => $abbr, 'abbr' => $abbr, 'team_logo' => 'teams/1/team.png',
            'helmet_logo_left' => null, 'helmet_logo_right' => null, 'team_settings' => ['nfl_import' => ['source' => 'ESPN']]]);
        $base = "teams/{$team->id}/nfl-logo.png";
        $team->update(['helmet_logo_left' => $base, 'helmet_logo_right' => $base]);
        app(NflHelmetDefaults::class)->apply($team);
        $profile = app(PracticeController::class)->appearance($team, 'home')['uniform'];
        if (in_array($abbr, ['CLE', 'CIN', 'LAR'])) {
            expect($team->helmet_logo_left)->toBeNull()->and($team->helmet_logo_right)->toBeNull();
        }
        if ($abbr === 'PIT') {
            expect($team->helmet_logo_left)->toBeNull()->and($team->helmet_logo_right)->toBe($base);
        }
        if (in_array($abbr, ['NYG', 'NYJ'])) {
            expect($profile['helmet_logo_color'])->toBe('#ffffff');
        }
        if (in_array($abbr, ['ATL', 'BUF', 'DEN', 'DET', 'MIA'])) {
            expect($profile['helmet_logo_right_flip'])->toBeTrue();
        }
        if (in_array($abbr, ['KC', 'BAL', 'PHI'])) {
            Storage::disk('team_art')->assertExists($team->helmet_logo_left);
            Storage::disk('team_art')->assertExists($team->helmet_logo_right);
            expect($team->helmet_logo_left)->not->toBe($team->helmet_logo_right);
            expect(Storage::disk('team_art')->get($team->helmet_logo_right))->toContain('<svg');
        }
        if ($abbr === 'CIN') {
            expect($profile['helmet_design'])->toBe('cat_stripes')->and($profile['helmet_stripe'])->toBe('#000000');
        }
        if ($abbr === 'LAR') {
            expect($profile['helmet_design'])->toBe('ram_horns')->and($profile['helmet_stripe'])->toBe('#ffa300');
        }
    }
});

test('existing-save helmet command preserves custom artwork and other worlds and is repeatable', function () {
    $old = World::create(['name' => 'Other']);
    app(CurrentWorld::class)->id = (int) $old->id;
    $other = Team::create(['city' => 'Old', 'name' => 'Keep', 'abbr' => 'CLE']);
    $world = World::create(['name' => 'NFL']);
    app(CurrentWorld::class)->id = (int) $world->id;
    $team = Team::create(['city' => 'Cincinnati', 'name' => 'Bengals', 'abbr' => 'CIN', 'helmet_logo_left' => 'custom.png', 'team_settings' => ['nfl_import' => ['source' => 'ESPN']]]);
    $this->artisan('football:apply-nfl-helmets', ['world' => $world->id])->assertSuccessful();
    expect(app(CurrentWorld::class)->id)->toBe((int) $world->id);
    expect($team->fresh()->helmet_logo_left)->toBe('custom.png');
    $first = $team->fresh()->getAttributes();
    $this->artisan('football:apply-nfl-helmets', ['world' => $world->id])->assertSuccessful();
    expect($team->fresh()->getAttributes())->toBe($first);
    $this->artisan('football:apply-nfl-helmets', ['world' => $world->id, '--replace-custom' => true])->assertSuccessful();
    expect($team->fresh()->helmet_logo_left)->toBeNull();
    app(CurrentWorld::class)->id = (int) $old->id;
    expect($other->fresh()->name)->toBe('Keep');
});
