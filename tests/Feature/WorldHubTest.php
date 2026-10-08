<?php

use App\Models\Exhibition;
use App\Models\Team;
use App\Models\User;
use App\Models\World;
use App\Services\Simulation\ExhibitionEngine;
use App\Support\CurrentWorld;

beforeEach(function () {
    $this->withoutVite();
});

test('the public introduction links to accounts and keeps games private', function () {
    $this->get('/')->assertOk()->assertSee('Call the play.')->assertSee(route('login'))->assertSee(route('register'));
    $this->get('/exhibitions')->assertRedirect(route('login'));
    $this->get('/worlds')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get('/')->assertRedirect(route('verification.notice'));
});

test('world creation is collapsed by default and opens with validation errors', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/worlds')->assertOk()->assertSee('Create new world')->assertSee('<details class="creation-panel" >', false)->assertDontSee('Saved games');
    $this->from('/worlds')->post('/worlds', [])->assertRedirect('/worlds')->assertSessionHasErrors('name');
    $this->get('/worlds')->assertOk()->assertSee('<details class="creation-panel"  open >', false);
});

test('a world hub shows its modes and deletes only its own exhibitions', function () {
    $world = World::create(['name' => 'Theo World']);
    openFootballSave($world);
    $home = Team::create(['city' => 'Home', 'name' => 'Warriors']);
    $away = Team::create(['city' => 'Away', 'name' => 'Planes']);
    $game = Exhibition::create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'state' => app(ExhibitionEngine::class)->initial(300), 'rosters' => [], 'history' => []]);
    $this->get('/')->assertOk()->assertSee('Theo World')->assertSee('Seasons')->assertSee('Franchise')->assertSee('Create new exhibition')->assertSee('Delete exhibition');
    $other = World::create(['name' => 'Other']);
    openFootballSave($other);
    $this->delete(route('exhibitions.destroy', $game))->assertNotFound();
    $this->assertDatabaseHas('exhibitions', ['id' => $game->id]);
    openFootballSave($world);
    $this->delete(route('exhibitions.destroy', $game))->assertRedirect(route('exhibitions.index'))->assertSessionHas('status', 'Exhibition deleted.');
    $this->assertDatabaseMissing('exhibitions', ['id' => $game->id]);
    $this->assertDatabaseHas('teams', ['id' => $home->id]);
    expect(app(CurrentWorld::class)->id)->toBeNull();
});
