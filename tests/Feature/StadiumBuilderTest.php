<?php

use App\Models\Team;
use App\Models\World;
use App\Support\CurrentWorld;
use App\Http\Controllers\PracticeController;

beforeEach(function () {
    $this->withoutVite();
    $world = World::create(['name' => 'Stadium builder']);
    openFootballSave($world);
    app(CurrentWorld::class)->id = $world->id;
});

test('stadium profiles save validated structure and separate tier colors without changing team art', function () {
    $team = Team::create(['city' => 'Test', 'name' => 'Home', 'endzone_text' => 'HOME', 'team_settings' => ['mascot' => ['name' => 'Chief']]]);
    $stadium = ['tunnels' => 1, 'scoreboards' => 'left', 'scoreboard_size' => 'large', 'lighting' => 'rim', 'name' => 'Theo Field', 'tiers' => 2, 'rows' => 6, 'row_height' => 1.25, 'one_color' => 0, 'wall_one_color' => 0, 'tier_wall_colors' => ['lower' => '#654321', 'middle' => '#fedcba', 'upper' => '#abcdef'], 'tier_colors' => ['lower' => '#123456', 'middle' => '#abcdef', 'upper' => '#fedcba']];
    $this->put(route('teams.editor.update', $team), ['city' => 'Test', 'name' => 'Home', 'stadium_style' => 'indoor_dome', 'team_settings' => ['stadium' => $stadium]])->assertRedirect()->assertSessionHasNoErrors();
    expect($team->fresh()->team_settings['stadium'])->toMatchArray($stadium)
        ->and($team->fresh()->team_settings['mascot']['name'])->toBe('Chief')
        ->and($team->fresh()->endzone_text)->toBe('HOME');
    foreach (['scoreboards' => 'none', 'scoreboard_size' => 'huge', 'lighting' => 'laser', 'tunnels' => 9] as $key => $invalid) {
        $this->put(route('teams.editor.update', $team), ['city' => 'Test', 'name' => 'Home', 'team_settings' => ['stadium' => [$key => $invalid]]])->assertSessionHasErrors('team_settings.stadium.'.$key);
    }
    expect(app(PracticeController::class)->appearance($team->fresh(), 'home')['stadium']['name'])->toBe('Theo Field');
    $this->put(route('teams.editor.update', $team), ['city' => 'Test', 'name' => 'Home', 'team_settings' => ['stadium' => ['tier_wall_colors' => ['upper' => 'red']]]])->assertSessionHasErrors('team_settings.stadium.tier_wall_colors.upper');
    foreach (['tiers' => 4, 'rows' => 100, 'row_height' => 2, 'name' => str_repeat('x', 81), 'one_color' => 'yes'] as $key => $invalid) {
        $this->put(route('teams.editor.update', $team), ['city' => 'Test', 'name' => 'Home', 'team_settings' => ['stadium' => [$key => $invalid]]])->assertSessionHasErrors('team_settings.stadium.'.$key);
    }
    $this->put(route('teams.editor.update', $team), ['city' => 'Test', 'name' => 'Home', 'team_settings' => ['stadium' => ['tier_colors' => ['upper' => 'red']]]])->assertSessionHasErrors('team_settings.stadium.tier_colors.upper');
});

test('stadium copy catalog contains only other teams from the active save and omits artwork', function () {
    $current = Team::create(['city' => 'Test', 'name' => 'Home']);
    $source = Team::create(['city' => 'Test', 'name' => 'Source', 'stadium_style' => 'horseshoe', 'team_settings' => ['stadium' => ['tiers' => 2]]]);
    $active = $current->world_id;
    $other = World::create(['name' => 'Other save']);
    app(CurrentWorld::class)->id = $other->id;
    $foreign = Team::create(['city' => 'Other', 'name' => 'Private']);
    app(CurrentWorld::class)->id = $active;
    $this->get(route('teams.editor.edit', $current))->assertOk()->assertViewHas('stadiumCopies', function ($choices) use ($current, $source, $foreign) {
        $ids = $choices->pluck('id')->all();
        expect($ids)->toContain($source->id)->not->toContain($current->id)->not->toContain($foreign->id);
        $copy = $choices->firstWhere('id', $source->id);
        expect($copy['stadium_style'])->toBe('horseshoe')->and($copy)->not->toHaveKey('team_logo')->not->toHaveKey('endzone_text');
        return true;
    });
});
