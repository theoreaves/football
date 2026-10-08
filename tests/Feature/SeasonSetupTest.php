<?php

use App\Models\League;
use App\Models\Season;
use App\Models\SeasonFixture;
use App\Models\Team;
use App\Models\World;

beforeEach(function () {
    $this->withoutVite();
    $this->world = World::create(['name' => 'Seasons']);
    openFootballSave($this->world);
    $this->league = League::create(['name' => 'Test league']);
    $this->teams = collect(range(1, 4))->map(fn ($n) => Team::create(['city' => 'City '.$n, 'name' => 'Team '.$n]));
    $this->setup = ['name' => 'Opening season', 'year' => 2026, 'league_id' => $this->league->id, 'team_count' => 4, 'games' => 6,
        'bye' => 1, 'layout' => 'conferences', 'playoffs' => '4', 'teams' => $this->teams->pluck('id')->all(), 'human' => [$this->teams[0]->id, $this->teams[1]->id]];
});

test('season preview does not write and finalizing reuses the world starting year', function () {
    $placeholder = Season::create(['league_id' => $this->league->id, 'year' => 2026]);
    $this->get(route('seasons.create'))->assertOk()->assertSee('Build your season');
    $this->post(route('seasons.preview'), $this->setup)->assertOk()->assertSee('Week 7')->assertSee('Create season with this schedule');
    $this->assertDatabaseCount('season_fixtures', 0);
    $this->post(route('seasons.store'), ['setup' => json_encode($this->setup)])->assertRedirect(route('seasons.show', $placeholder));
    $this->assertDatabaseCount('seasons', 1);
    $this->assertDatabaseCount('season_fixtures', 12);
    $season = Season::withoutGlobalScopes()->first();
    expect($season->settings['members'][$this->teams[0]->id]['control'])->toBe('human');
    foreach (['overview', 'standings', 'schedule', 'leaders', 'stats', 'injuries', 'playoffs', 'settings'] as $tab) {
        $this->get(route('seasons.show', ['season' => $season, 'tab' => $tab]))->assertOk();
    }
    foreach (['overview', 'roster', 'schedule', 'stats', 'injuries', 'settings'] as $tab) {
        $this->get(route('seasons.team', ['season' => $season, 'team' => $this->teams[0], 'tab' => $tab]))->assertOk();
    }
    $this->post(route('seasons.store'), ['setup' => json_encode($this->setup)])->assertSessionHasErrors('year');
    $this->assertDatabaseCount('season_fixtures', 12);
});

test('invalid season combinations and unbalanced assignments are rejected', function () {
    foreach ([['team_count' => 8], ['games' => 17], ['playoffs' => '8'], ['playoffs' => 'nfl14'], ['human' => [999999]], ['groups' => array_fill_keys($this->setup['teams'], 'A1')]] as $bad) {
        $this->post(route('seasons.preview'), array_replace($this->setup, $bad))->assertSessionHasErrors();
    }
    $this->assertDatabaseCount('season_fixtures', 0);
});

test('human control can change without altering fixtures and seasons remain world scoped', function () {
    $this->post(route('seasons.store'), ['setup' => json_encode($this->setup)])->assertRedirect();
    $season = Season::withoutGlobalScopes()->first();
    $fixtures = SeasonFixture::withoutGlobalScopes()->get()->toArray();
    $this->put(route('seasons.controls', $season), [])->assertRedirect();
    expect(array_column($season->fresh()->settings['members'], 'control'))->toBe(['cpu', 'cpu', 'cpu', 'cpu']);
    $this->put(route('seasons.controls', $season), ['human' => $this->setup['teams']])->assertRedirect();
    expect(array_unique(array_column($season->fresh()->settings['members'], 'control')))->toBe(['human']);
    expect(SeasonFixture::withoutGlobalScopes()->get()->toArray())->toBe($fixtures);
    $this->put(route('seasons.controls', $season), ['human' => [999999]])->assertSessionHasErrors('human');
    $other = World::create(['name' => 'Other']);
    openFootballSave($other);
    $this->get(route('seasons.show', $season))->assertNotFound();
    $this->put(route('seasons.controls', $season), [])->assertNotFound();
    $this->post(route('seasons.preview'), $this->setup)->assertNotFound();
});
