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

test('season player editor stays embedded after saving and returns to the roster', function () {
    $player = \App\Models\Player::create(['firstname' => 'Theo', 'lastname' => 'Reaves', 'position' => 'QB', 'age' => 25]);
    \App\Models\TeamPlayer::create(['team_id' => $this->teams[0]->id, 'player_id' => $player->id, 'team_year' => '2026', 'position' => 'QB', 'depth_chart_position' => 'QB1', 'jersey_number' => 12]);
    $this->post(route('seasons.store'), ['setup' => json_encode($this->setup)])->assertRedirect();
    $season = Season::withoutGlobalScopes()->first();
    $parameters = [$this->teams[0], $player, 'year' => 2026, 'season' => $season->id, 'embedded' => 1];
    $this->get(route('seasons.team', ['season' => $season, 'team' => $this->teams[0], 'tab' => 'roster']))->assertOk()->assertSee('data-roster-search', false)->assertSee('data-roster-editor', false);
    $this->get(route('teams.editor.teams.players.edit', $parameters))->assertOk()->assertSee('Back to roster')->assertSee('data-player-editor-back', false)->assertDontSee('Close world');
    $this->put(route('teams.editor.teams.players.update', $parameters), ['firstname' => 'Theo', 'lastname' => 'Updated', 'position' => 'QB', 'age' => 25, 'depth_chart_position' => 'QB1', 'jersey_number' => 12, 'ratings' => array_fill_keys(\App\Services\Simulation\PlayerRatings::FIELDS, 70)])
        ->assertRedirect(route('teams.editor.teams.players.edit', $parameters));
    $this->assertDatabaseHas('players', ['id' => $player->id, 'lastname' => 'Updated']);
});

test('season depth charts save ordered eligible players without changing world roster depth', function () {
    $ids = [];
    foreach (['Starter', 'Backup'] as $i => $name) {
        $player = \App\Models\Player::create(['firstname' => $name, 'lastname' => 'Quarterback', 'position' => 'QB', 'age' => 25]);
        \App\Models\TeamPlayer::create(['team_id' => $this->teams[0]->id, 'player_id' => $player->id, 'team_year' => '2026', 'position' => 'QB', 'depth_chart_position' => 'QB'.($i + 1)]);
        $ids[] = $player->id;
    }
    $this->post(route('seasons.store'), ['setup' => json_encode($this->setup)])->assertRedirect();
    $season = Season::withoutGlobalScopes()->first();
    $url = route('seasons.depth', [$season, $this->teams[0]]);
    $this->put($url, ['position' => 'QB', 'players' => array_reverse($ids)])->assertRedirect()->assertSessionHas('status');
    expect($season->fresh()->settings['depth_charts'][$this->teams[0]->id]['QB'])->toBe(array_reverse($ids));
    $this->get(route('seasons.team', ['season' => $season, 'team' => $this->teams[0], 'tab' => 'depth', 'position' => 'QB']))->assertOk()->assertSeeInOrder(['Backup Quarterback', 'Starter Quarterback']);
    $this->assertDatabaseHas('team_players', ['player_id' => $ids[0], 'depth_chart_position' => 'QB1']);
    $this->put($url, ['position' => 'QB', 'players' => [$ids[0]]])->assertSessionHasErrors('players');
    $this->put($url, ['position' => 'RB', 'players' => $ids])->assertSessionHasErrors('players');
    $this->put($url, ['position' => 'QB', 'players' => [$ids[0], $ids[0]]])->assertSessionHasErrors();
    expect($season->fresh()->settings['depth_charts'][$this->teams[0]->id]['QB'])->toBe(array_reverse($ids));
});
