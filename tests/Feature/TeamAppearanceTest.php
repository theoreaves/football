<?php

use App\Models\LocalSetting;
use App\Models\Team;
use App\Models\World;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
    $world = World::create(['name' => 'Appearance']);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    app(CurrentWorld::class)->id = $world->id;
});

test('team appearance saves and the practice field uses home and away uniforms', function () {
    $home = Team::create(['city' => 'Memphis', 'name' => 'Tigers']);
    $away = Team::create(['city' => 'Jackson', 'name' => 'Hawks', 'uniform_away_shirt' => '#abcdef', 'uniform_away_number' => '#123abc', 'uniform_away_number_outline' => '#fedcba']);
    $data = [
        'city' => 'Memphis', 'name' => 'Tigers',
        'playcalling_behind' => 0, 'playcalling_tied' => 0, 'playcalling_ahead' => 0,
        'ol_rush' => 0, 'ol_power' => 0, 'ol_pass' => 0, 'ol_protect' => 0,
        'wear_white_at_home' => 0,
        'uniform_home_helmet' => '#112233', 'uniform_home_shirt' => '#445566',
        'uniform_home_number' => '#aabb00', 'uniform_home_number_outline' => '#112244',
        'uniform_away_number' => '#123456', 'uniform_away_number_outline' => '#654321',
        'uniform_home_pants' => '#778899', 'uniform_home_socks' => '#aabbcc',
        'endzone_text' => 'TIGERS', 'endzone_background' => '#123456', 'endzone_text_color' => '#ffffff',
    ];
    $this->put(route('teams.editor.update', $home), $data)->assertRedirect(route('teams.editor.edit', $home));
    $this->assertDatabaseHas('teams', ['id' => $home->id, 'uniform_home_helmet' => '#112233', 'endzone_text' => 'TIGERS', 'uniform_home_number' => '#aabb00', 'uniform_home_number_outline' => '#112244', 'uniform_away_number' => '#123456', 'uniform_away_number_outline' => '#654321']);
    $this->get(route('practice', ['home' => $home->id, 'away' => $away->id]))
        ->assertOk()->assertViewHas('appearance', fn ($value) => $value['home']['uniform']['shirt'] === '#445566'
            && $value['away']['uniform']['shirt'] === '#abcdef'
            && $value['home']['uniform']['number'] === '#aabb00'
            && $value['home']['uniform']['number_outline'] === '#112244'
            && $value['away']['uniform']['number'] === '#123abc'
            && $value['away']['uniform']['number_outline'] === '#fedcba'
            && $value['home']['endzone_text'] === 'TIGERS');
    $this->get(route('teams.editor.edit', $home))->assertOk()->assertSee('Number color')->assertSee('uniform_away_number_outline');
    $this->put(route('teams.editor.update', $home), array_merge($data, ['uniform_home_number' => 'red', 'uniform_away_number_outline' => '#bad']))->assertSessionHasErrors(['uniform_home_number', 'uniform_away_number_outline']);
    $this->put(route('teams.editor.update', $home), array_merge($data, ['uniform_home_shirt' => 'invalid']))
        ->assertSessionHasErrors('uniform_home_shirt');
});

test('practice selections cannot use teams in another saved game', function () {
    $team = Team::create(['city' => 'Memphis', 'name' => 'Tigers']);
    $other = World::create(['name' => 'Other']);
    LocalSetting::find(1)->update(['current_world_id' => $other->id]);
    $this->get(route('practice', ['home' => $team->id]))->assertNotFound();
});
