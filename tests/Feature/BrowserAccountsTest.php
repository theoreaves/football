<?php

use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Team;
use App\Models\User;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->withoutVite();
    Notification::fake();
});

test('email registration verifies before granting access and stores a hashed password', function () {
    $this->withSession(['current_world_id' => 99])->post('/register', ['name' => 'Theo', 'email' => ' THEO@example.com ', 'password' => 'football-password', 'password_confirmation' => 'football-password'])->assertRedirect(route('verification.notice'))->assertSessionMissing('current_world_id');
    $user = User::firstOrFail();
    expect($user->email)->toBe('theo@example.com')->and(Hash::check('football-password', $user->password))->toBeTrue();
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get('/worlds')->assertRedirect(route('verification.notice'));
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($url)->assertRedirect(route('worlds.index'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get('/worlds')->assertOk();
});

test('login failures are throttled and logout clears the selected save', function () {
    $user = User::factory()->create(['password' => 'football-password']);
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
    $this->actingAs($user)->withSession(['current_world_id' => 123])->post('/logout')->assertRedirect(route('login'))->assertSessionMissing('current_world_id');
    $this->assertGuest();
});

test('password broker sends and consumes reset tokens', function () {
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
    Notification::assertSentTo($user, ResetPassword::class);
    $token = Password::createToken($user);
    $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-football-password', 'password_confirmation' => 'new-football-password'])->assertRedirect(route('login'));
    expect(Hash::check('new-football-password', $user->fresh()->password))->toBeTrue();
    $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-football-password', 'password_confirmation' => 'new-football-password'])->assertSessionHasErrors('email');
});

test('users cannot list select or bind another users saves teams games or artwork', function () {
    Storage::fake('team_art');
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $first = World::create(['name' => 'Private Alice', 'owner_user_id' => $alice->id]);
    $second = World::create(['name' => 'Private Bob', 'owner_user_id' => $bob->id]);
    app(CurrentWorld::class)->id = $second->id;
    $team = Team::create(['city' => 'Secret', 'name' => 'Team', 'team_logo' => 'teams/secret.png']);
    Storage::disk('team_art')->put('teams/secret.png', 'secret bytes');
    $game = Exhibition::create(['home_team_id' => $team->id, 'away_team_id' => $team->id, 'state' => [], 'rosters' => [], 'history' => []]);
    $this->actingAs($alice)->withSession(['current_world_id' => $first->id]);
    $this->get('/worlds')->assertOk()->assertSee('Private Alice')->assertDontSee('Private Bob');
    $this->post('/worlds/'.$second->id.'/select')->assertNotFound();
    $this->get(route('teams.editor.edit', $team))->assertNotFound();
    $this->get(route('teams.art', [$team, 'team_logo']))->assertNotFound();
    $this->get(route('exhibitions.show', $game))->assertNotFound();
    $this->put(route('teams.editor.update', $team), ['name' => 'Stolen', 'city' => 'Stolen'])->assertNotFound();
    $this->withSession(['current_world_id' => $second->id])->get('/')->assertRedirect(route('worlds.index'))->assertSessionMissing('current_world_id');
    $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Team']);
});

test('local selected save never supplies a browser session and closing does not change it', function () {
    $user = User::factory()->create();
    $world = World::create(['name' => 'Owned', 'owner_user_id' => $user->id]);
    LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);
    $this->actingAs($user)->get('/')->assertRedirect(route('worlds.index'));
    $this->post('/worlds/'.$world->id.'/select')->assertSessionHas('current_world_id', $world->id);
    $this->post('/worlds/close')->assertSessionMissing('current_world_id');
    expect(LocalSetting::find(1)->current_world_id)->toBe($world->id);
});

test('local saves can only be claimed by an explicit console action', function () {
    Storage::fake('public');
    Storage::fake('team_art');
    $user = User::factory()->create();
    $unowned = World::create(['name' => 'Local']);
    app(CurrentWorld::class)->id = $unowned->id;
    Team::create(['city' => 'Local', 'name' => 'Team', 'team_logo' => 'teams/local.png']);
    Storage::disk('public')->put('teams/local.png', 'logo');
    $this->actingAs($user)->get('/worlds')->assertDontSee('Local');
    $this->post('/worlds/'.$unowned->id.'/select')->assertNotFound();
    $this->artisan('football:claim-saves', ['email' => $user->email, 'world' => $unowned->id])->assertSuccessful();
    expect($unowned->fresh()->owner_user_id)->toBe($user->id);
    Storage::disk('team_art')->assertExists('teams/local.png');
    Storage::disk('public')->assertMissing('teams/local.png');
    $other = User::factory()->create();
    $this->artisan('football:claim-saves', ['email' => $other->email, 'world' => $unowned->id])->assertFailed();
});

test('the startup picker no longer offers or accepts the Middle Earth template', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/worlds')->assertOk()->assertDontSee('value="middle-earth"', false);
    $this->post('/worlds', ['name' => 'Middle Earth', 'league_name' => 'League', 'year' => 2026, 'preset' => 'middle-earth'])->assertSessionHasErrors('preset');
    $this->assertDatabaseCount('worlds', 0);
});

test('password login uses a fresh session and rejects weak or duplicate registration', function () {
    $user = User::factory()->create(['email' => 'theo@example.com', 'password' => 'football-password']);
    $this->withSession(['current_world_id' => 999])->post('/login', ['email' => 'THEO@example.com', 'password' => 'football-password'])->assertRedirect(route('worlds.index'))->assertSessionMissing('current_world_id');
    $this->assertAuthenticatedAs($user);
    $this->post('/logout');
    $this->post('/register', ['name' => 'Theo', 'email' => 'THEO@example.com', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors(['email', 'password']);
    $this->assertDatabaseCount('users', 1);
});

test('owned artwork is served privately and account switching cannot select a previous owners save', function () {
    Storage::fake('team_art');
    $alice = User::factory()->create();
    $bob = User::factory()->create(['password' => 'football-password']);
    $world = World::create(['name' => 'Alice', 'owner_user_id' => $alice->id]);
    app(CurrentWorld::class)->id = $world->id;
    $team = Team::create(['city' => 'Home', 'name' => 'Club', 'team_logo' => 'teams/home.png']);
    Storage::disk('team_art')->put('teams/home.png', 'logo');
    $this->actingAs($alice)->withSession(['current_world_id' => $world->id])->get(route('teams.art', [$team, 'team_logo']))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->post('/logout');
    $this->post('/login', ['email' => $bob->email, 'password' => 'football-password'])->assertRedirect(route('worlds.index'));
    $this->get('/worlds')->assertDontSee('Alice');
    $this->post('/worlds/'.$world->id.'/select')->assertNotFound();
});
