<?php

use App\Http\Controllers\PracticeController;
use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PlayerRatings;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
    $world = World::create(['name' => 'Team settings']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    openFootballSave($world);
    app(CurrentWorld::class)->id = $world->id;
    $this->artisan('world:seed-demo', ['world' => $world->id, '--teams' => 2])->assertSuccessful();
    app(CurrentWorld::class)->id = $world->id;
});

test('team settings tabs save validated profiles and reject out of range coaching preferences', function () {
    $team = Team::first();
    $this->get(route('teams.editor.edit', $team))->assertOk()->assertSee('data-team-panel="uniforms"', false)->assertSee('data-team-panel="coaching"', false)->assertSee('data-team-panel="mascot"', false);
    $settings = ['white_at_home' => 1, 'coaching' => ['pass' => 85, 'blitz' => 20], 'staff' => ['head' => ['name' => 'Theo', 'skin' => '#593b2c', 'hair' => 'short', 'build' => 'stocky']], 'cheerleaders' => ['enabled' => 0, 'primary' => '#123456', 'secondary' => '#abcdef', 'accent' => '#fedcba'], 'mascot' => ['name' => 'Chief', 'character' => 'bear']];
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => $settings])->assertRedirect()->assertSessionHasNoErrors();
    expect($team->fresh()->team_settings['coaching']['pass'])->toBe(85)->and($team->fresh()->team_settings['staff']['head']['name'])->toBe('Theo');
    expect((bool) $team->fresh()->team_settings['cheerleaders']['enabled'])->toBeFalse();
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => ['white_at_home' => 0]])->assertRedirect();
    expect($team->fresh()->team_settings['staff']['head']['name'])->toBe('Theo');
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => ['coaching' => ['pass' => 101]]])->assertSessionHasErrors('team_settings.coaching.pass');
});

test('white at home swaps both kits and new games snapshot coaching and presentation', function () {
    [$home, $away] = Team::all()->all();
    $home->update(['uniform_home_shirt' => '#112233', 'uniform_away_shirt' => '#ffffff', 'team_settings' => ['white_at_home' => true, 'coaching' => ['pass' => 90], 'staff' => ['head' => ['name' => 'Head']], 'mascot' => ['character' => 'dog', 'name' => 'Chief'], 'cheerleaders' => ['enabled' => false, 'primary' => '#123456'], 'stadium' => ['name' => 'Original Field', 'tiers' => 2]]]);
    $away->update(['uniform_home_shirt' => '#445566', 'uniform_away_shirt' => '#eeeeee', 'team_settings' => ['white_at_home' => true, 'coaching' => ['blitz' => 80]]]);
    $this->get(route('practice', ['home' => $home->id, 'away' => $away->id]))->assertOk()->assertViewHas('appearance', fn ($a) => $a['home']['uniform']['shirt'] === '#ffffff' && $a['away']['uniform']['shirt'] === '#445566');
    $this->post(route('exhibitions.store'), ['home' => $home->id, 'away' => $away->id, 'quarter_length' => 180])->assertRedirect()->assertSessionHasNoErrors();
    // Request middleware clears CurrentWorld after each HTTP response.
    app(CurrentWorld::class)->id = (int) $home->world_id;
    $game = Exhibition::latest('id')->firstOrFail();
    expect($game->state['team_appearance']['home']['stadium'])->toMatchArray(['name' => 'Original Field', 'tiers' => 2]);
    expect($game->state['team_appearance']['home']['cheerleaders'])->toMatchArray(['enabled' => false, 'primary' => '#123456']);
    expect($game->state['team_appearance']['home']['mascot'])->toMatchArray(['character' => 'dog', 'name' => 'Chief']);
    expect($game->state['coaching']['home']['pass'])->toBe(90)->and($game->state['coaching']['away']['blitz'])->toBe(80);
    $home->update(['uniform_away_shirt' => '#ff0000', 'team_settings' => ['white_at_home' => false, 'coaching' => ['pass' => 10], 'mascot' => ['character' => 'wolf']]]);
    $this->get(route('exhibitions.show', $game))->assertOk()->assertViewHas('appearance', fn ($a) => $a['home']['uniform']['shirt'] === '#ffffff' && $a['home']['mascot']['character'] === 'dog' && $a['home']['cheerleaders']['enabled'] === false && $a['home']['stadium']['name'] === 'Original Field');
    app(CurrentWorld::class)->id = (int) $home->world_id;
    expect($game->fresh()->state['coaching']['home']['pass'])->toBe(90);
    $normal = app(PracticeController::class)->matchup($home->fresh(), $away);
    expect($normal['home']['uniform']['shirt'])->toBe('#112233')->and($normal['away']['uniform']['shirt'])->toBe('#eeeeee');
});

test('coaching tendencies change deterministic distributions while neutral and late clock decisions remain intact', function () {
    $coach = app(CpuCoach::class);
    $ratings = array_fill_keys(PlayerRatings::FIELDS, 70);
    $players = array_fill_keys(['QB', 'RB', 'WR1', 'C', 'K'], ['ratings' => $ratings]);
    $rosters = ['home' => ['players' => $players], 'away' => ['players' => $players]];
    $counts = ['run' => 0, 'pass' => 0, 'blitz_low' => 0, 'blitz_high' => 0, 'zone' => 0, 'man' => 0, 'short' => 0, 'deep' => 0, 'go_low' => 0, 'go_high' => 0, 'run_stop_low' => 0, 'run_stop_high' => 0, 'tempo_low' => 0, 'tempo_high' => 0];
    for ($seed = 1; $seed <= 300; $seed++) {
        $state = array_merge(app(ExhibitionEngine::class)->initial(900, $seed, false), ['phase' => 'scrimmage', 'possession' => 'home', 'down' => 1, 'distance' => 10, 'spot' => 40, 'quarter' => 1, 'clock' => 600]);
        $neutral = $state;
        $neutral['coaching'] = ['home' => array_fill_keys(array_keys(config('team-settings.coaching')), 50), 'away' => array_fill_keys(array_keys(config('team-settings.coaching')), 50)];
        expect($coach->offense($state, $rosters))->toBe($coach->offense($neutral, $rosters));
        expect($coach->defense($state, 'inside_run'))->toBe($coach->defense($neutral, 'inside_run'));
        foreach (['run' => 0, 'pass' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['home' => ['pass' => $value]]]);
            $decision = $coach->offense($biased, $rosters);
            expect($decision)->toBe($coach->offense($biased, $rosters));
            if (! in_array(\App\Services\Simulation\OffensiveOptions::canonical($decision['call']), ['inside_run', 'outside_run'])) {
                $counts[$label]++;
            }
        }
        foreach (['blitz_low' => 0, 'blitz_high' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['away' => ['blitz' => $value]]]);
            $counts[$label] += $coach->defense($biased, 'inside_run')['call'] === 'blitz' ? 1 : 0;
        }
        foreach (['zone' => 0, 'man' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['away' => ['man' => $value]]]);
            $counts[$label] += $coach->defense($biased, 'inside_run')['call'] === 'man_to_man' ? 1 : 0;
        }
        foreach (['short' => 0, 'deep' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['home' => ['depth' => $value, 'pass' => 100]]]);
            $counts[$label] += \App\Services\Simulation\OffensiveOptions::canonical($coach->offense($biased, $rosters)['call']) === 'deep_pass' ? 1 : 0;
        }
        foreach (['go_low' => 0, 'go_high' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['down' => 4, 'distance' => 2, 'spot' => 50, 'coaching' => ['home' => ['fourth_down' => $value]]]);
            $counts[$label] += $coach->offense($biased, $rosters)['call'] !== 'punt' ? 1 : 0;
        }
        foreach (['run_stop_low' => 0, 'run_stop_high' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['away' => ['run_stop' => $value]]]);
            $counts[$label] += $coach->defense($biased, 'inside_run')['call'] === 'run_stop' ? 1 : 0;
        }
        foreach (['tempo_low' => 0, 'tempo_high' => 100] as $label => $value) {
            $biased = array_replace_recursive($state, ['coaching' => ['home' => ['tempo' => $value]]]);
            $counts[$label] += $coach->management($biased)['tempo'] === 'hurry' ? 1 : 0;
        }
        $late = array_replace_recursive($state, ['quarter' => 4, 'clock' => 10, 'home_score' => 7, 'timeouts' => ['away' => 0], 'coaching' => ['home' => ['pass' => 100, 'tempo' => 100]]]);
        expect($coach->offense($late, $rosters)['call'])->toBe('kneel');
    }
    expect($counts['pass'])->toBeGreaterThan($counts['run']);
    expect($counts['blitz_high'])->toBeGreaterThan($counts['blitz_low']);
    expect($counts['man'])->toBeGreaterThan($counts['zone']);
    expect($counts['deep'])->toBeGreaterThan($counts['short']);
    expect($counts['go_high'])->toBeGreaterThan($counts['go_low']);
    expect($counts['run_stop_high'])->toBeGreaterThan($counts['run_stop_low']);
    expect($counts['tempo_high'])->toBeGreaterThan($counts['tempo_low']);
});

test('all mascot choices render and save while unknown characters are rejected', function () {
    $team = Team::first();
    $page = $this->get(route('teams.editor.edit', $team))->assertOk();
    foreach (config('team-settings.mascot_characters') as $character => $label) {
        $page->assertSee('value="'.$character.'"', false);
        $this->put(route('teams.editor.update', $team), [
            'city' => $team->city, 'name' => $team->name,
            'team_settings' => ['mascot' => ['character' => $character, 'name' => 'Chief', 'fur' => '#123456', 'shirt' => '#abcdef']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect($team->fresh()->team_settings['mascot'])->toMatchArray([
            'character' => $character, 'name' => 'Chief', 'fur' => '#123456', 'shirt' => '#abcdef',
        ]);
    }
    $this->put(route('teams.editor.update', $team), [
        'city' => $team->city, 'name' => $team->name,
        'team_settings' => ['mascot' => ['character' => 'unknown-character']],
    ])->assertSessionHasErrors('team_settings.mascot.character');
    expect($team->fresh()->team_settings['mascot']['character'])->toBe('elf');
});

test('team audio uploads preview reset and preserve immutable game profiles', function () {
    \Illuminate\Support\Facades\Storage::fake('team_art');
    $team = Team::first();
    $other = Team::where('id', '!=', $team->id)->first();
    $world = (int) $team->world_id;
    $payload = ['city' => $team->city, 'name' => $team->name];
    $this->get(route('teams.editor.edit', $team))->assertOk()->assertSee('data-team-panel="audio"', false);
    $upload = fn () => new \Illuminate\Http\UploadedFile(public_path('audio/stadium/v1/touchdown.mp3'), 'touchdown.mp3', 'audio/mpeg', null, true);
    $this->put(route('teams.editor.update', $team), $payload + ['audio_uploads' => ['touchdown' => $upload()]])->assertRedirect()->assertSessionHasNoErrors();
    app(CurrentWorld::class)->id = $world;
    $team->refresh();
    $path = $team->team_settings['audio']['touchdown'];
    \Illuminate\Support\Facades\Storage::disk('team_art')->assertExists($path);
    $appearance = app(PracticeController::class)->matchup($team, $other);
    $url = $appearance['home']['audio']['touchdown'];
    $this->post(route('exhibitions.store'), ['home' => $team->id, 'away' => $other->id, 'quarter_length' => 180])->assertRedirect()->assertSessionHasNoErrors();
    app(CurrentWorld::class)->id = $world;
    $game = Exhibition::latest('id')->firstOrFail();
    expect($game->state['team_appearance']['home']['audio']['touchdown'])->toBe($url);
    expect($appearance['away']['audio'])->toBe([]);
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('teams.audio', ['team' => $other, 'clip' => basename($path)]))->assertNotFound();
    $this->put(route('teams.editor.update', $team), $payload + ['audio_clear' => ['touchdown' => 1]])->assertRedirect()->assertSessionHasNoErrors();
    app(CurrentWorld::class)->id = $world;
    expect($team->fresh()->team_settings['audio'] ?? [])->toBe([]);
    expect($game->fresh()->state['team_appearance']['home']['audio']['touchdown'])->toBe($url);
    // Old snapshots continue to resolve their immutable clip after a reset.
    $this->get($url)->assertOk();
    $this->put(route('teams.editor.update', $team), $payload + ['audio_uploads' => ['touchdown' => \Illuminate\Http\UploadedFile::fake()->create('bad.txt', 1, 'text/plain')]])->assertSessionHasErrors('audio_uploads.touchdown');
    $this->put(route('teams.editor.update', $team), $payload + ['team_settings' => ['audio' => ['touchdown' => '/arbitrary/path']]])->assertSessionHasErrors('team_settings');
    $secondWorld = World::create(['name' => 'Another audio save']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $secondWorld->id]);
    openFootballSave($secondWorld);
    app(CurrentWorld::class)->id = (int) $secondWorld->id;
    $this->get($url)->assertNotFound();
});

test('home and away helmet designs and decal controls save and validate', function () {
    $team = Team::first();
    $world = (int) $team->world_id;
    $settings = ['helmets' => ['home' => ['design' => 'cat_stripes'], 'away' => ['design' => 'ram_horns'], 'flip_left' => 0, 'flip_right' => 1, 'logo_color' => '#ffffff']];
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => $settings])->assertRedirect()->assertSessionHasNoErrors();
    app(CurrentWorld::class)->id = $world;
    $team->refresh();
    expect(app(PracticeController::class)->appearance($team, 'home')['uniform']['helmet_design'])->toBe('cat_stripes');
    expect(app(PracticeController::class)->appearance($team, 'away')['uniform']['helmet_design'])->toBe('ram_horns');
    $this->get(route('teams.editor.edit', $team))->assertOk()->assertSee('Ram horns')->assertSee('Cat stripes');
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => ['helmets' => ['home' => ['design' => 'unknown']]]])->assertSessionHasErrors('team_settings.helmets.home.design');
});

test('specialists save only eligible current team players and are captured for new games', function () {
    [$home, $away] = Team::all()->all();
    $roster = app(\App\Services\Simulation\RosterBuilder::class)->build($home);
    $rb = collect($roster['pool'])->firstWhere('position', 'RB')['id'];
    $wr = collect($roster['pool'])->firstWhere('position', 'WR')['id'];
    $qb = $roster['players']['QB']['id'];
    $foreign = $away->players()->firstOrFail()->id;
    $this->get(route('teams.editor.edit', $home))->assertOk()->assertSee('data-team-panel="specialists"', false)->assertSee('Third-down / short-yardage back');
    $payload = ['city' => $home->city, 'name' => $home->name, 'team_settings' => ['specialists' => ['third_down_back' => $rb, 'kick_returner' => $wr, 'punt_returner' => $wr]]];
    $this->put(route('teams.editor.update', $home), $payload)->assertRedirect()->assertSessionHasNoErrors();
    app(CurrentWorld::class)->id = (int) $home->world_id;
    $snapshot = app(\App\Services\Simulation\RosterBuilder::class)->build($home->fresh());
    expect($snapshot['specialists'])->toMatchArray($payload['team_settings']['specialists']);
    foreach ([$foreign, $qb] as $id) {
        $payload['team_settings']['specialists']['third_down_back'] = $id;
        $this->put(route('teams.editor.update', $home), $payload)->assertSessionHasErrors('team_settings.specialists.third_down_back');
    }
    $payload['team_settings']['specialists'] = ['third_down_back' => null, 'kick_returner' => null, 'punt_returner' => null];
    $this->put(route('teams.editor.update', $home), $payload)->assertRedirect()->assertSessionHasNoErrors();
    app(CurrentWorld::class)->id = (int) $home->world_id;
    expect($home->fresh()->team_settings['specialists']['third_down_back'])->toBeNull();
    expect($snapshot['specialists']['third_down_back'])->toBe($rb);
});
