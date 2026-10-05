<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(function () {
    $this->withoutVite();
    config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
});

function fakeFootballGoogle(string $id, string $email, bool $verified = true): void
{
    $profile = (new GoogleUser)->setRaw(['email_verified' => $verified])->map(['id' => $id, 'name' => 'Google Coach', 'email' => $email]);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($profile);
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
}

test('Google redirects with session state and buttons only appear when configured', function () {
    $this->get('/login')->assertSee('Continue with Google');
    $this->get('/auth/google')->assertRedirect()->assertSessionHas('state');
    config(['services.google.client_id' => null]);
    $this->get('/login')->assertDontSee('Continue with Google');
    $this->get('/auth/google')->assertStatus(503);
});

test('verified Google identity creates one account and subsequent sign in uses its stable id', function () {
    fakeFootballGoogle('google-123', 'google@example.com');
    $this->withSession(['current_world_id' => 42])->get('/auth/google/callback')->assertRedirect(route('worlds.index'))->assertSessionMissing('current_world_id');
    $user = User::firstOrFail();
    expect($user->google_id)->toBe('google-123')->and($user->hasVerifiedEmail())->toBeTrue();
    $this->assertAuthenticatedAs($user);
    Auth::logout();
    fakeFootballGoogle('google-123', 'changed@example.com');
    $this->get('/auth/google/callback')->assertRedirect(route('worlds.index'));
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseCount('users', 1);
    expect($user->fresh()->email)->toBe('google@example.com');
});

test('unverified Google email and invalid state cannot sign in', function () {
    fakeFootballGoogle('unverified', 'google@example.com', false);
    $this->get('/auth/google/callback')->assertStatus(422);
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->once()->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
    $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('status');
    $this->assertGuest();
});

test('Google does not silently link a matching email but an authenticated owner can link it', function () {
    $user = User::factory()->create(['email' => 'same@example.com']);
    fakeFootballGoogle('same-google', $user->email);
    $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('status');
    $this->assertGuest();
    expect($user->fresh()->google_id)->toBeNull();
    fakeFootballGoogle('same-google', $user->email);
    $this->actingAs($user)->withSession(['google_link_user_id' => $user->id])->get('/auth/google/callback')->assertRedirect(route('worlds.index'));
    expect($user->fresh()->google_id)->toBe('same-google');
});

test('a Google identity belonging to another account cannot be linked', function () {
    $owner = User::factory()->create();
    $owner->forceFill(['google_id' => 'taken'])->save();
    $other = User::factory()->create();
    fakeFootballGoogle('taken', $owner->email);
    $this->actingAs($other)->withSession(['google_link_user_id' => $other->id])->get('/auth/google/callback')->assertStatus(422);
    expect($other->fresh()->google_id)->toBeNull();
});

test('Google cancellation returns to sign in without creating an account', function () {
    $this->withSession(['state' => 'old-state'])->get('/auth/google/callback?error=access_denied')->assertRedirect(route('login'))->assertSessionMissing('state');
    $this->assertDatabaseCount('users', 0);
});

test('the actual Google provider rejects mismatched OAuth state before fetching a profile', function () {
    $this->withSession(['state' => 'expected-state'])->get('/auth/google/callback?state=wrong-state&code=bogus')->assertRedirect(route('login'));
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});
