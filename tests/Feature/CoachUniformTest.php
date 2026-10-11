<?php

use App\Models\Team;
use App\Models\World;
use App\Support\CurrentWorld;

test('coach cap choices and colors save independently and reject invalid values', function () {
    $this->withoutVite();
    $world = World::create(['name' => 'Coach caps']);
    openFootballSave($world);
    app(CurrentWorld::class)->id = $world->id;
    $team = Team::create(['city' => 'Test', 'name' => 'Caps']);
    $settings = ['staff' => ['head' => ['hat' => 1, 'hat_color' => '#123456'], 'offense' => ['hat' => 0, 'hat_color' => '#abcdef']]];
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => $settings])->assertRedirect()->assertSessionHasNoErrors();
    expect((bool) $team->fresh()->team_settings['staff']['head']['hat'])->toBeTrue()
        ->and((bool) $team->fresh()->team_settings['staff']['offense']['hat'])->toBeFalse()
        ->and($team->fresh()->team_settings['staff']['head']['hat_color'])->toBe('#123456');
    $settings['staff']['head'] = ['hat' => 'yes', 'hat_color' => 'blue'];
    $this->put(route('teams.editor.update', $team), ['city' => $team->city, 'name' => $team->name, 'team_settings' => $settings])->assertSessionHasErrors(['team_settings.staff.head.hat', 'team_settings.staff.head.hat_color']);
});
