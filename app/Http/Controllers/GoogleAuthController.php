<?php

namespace App\Http\Controllers;

use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as OAuthRedirect;

class GoogleAuthController extends Controller
{
    private function configured(): void
    {
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 503, 'Google sign-in is not configured.');
    }

    public function redirect(Request $request): OAuthRedirect
    {
        $this->configured();
        $request->session()->put('google_link_user_id', $request->user()?->id);

        return Socialite::driver('google')->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->configured();
        $linkId = $request->session()->pull('google_link_user_id');
        if ($request->has('error')) {
            $request->session()->forget('state');

            return redirect()->route('login')->with('status', 'Google sign-in was cancelled. Please try again.');
        }
        try {
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException|GuzzleException $exception) {
            return redirect()->route('login')->with('status', 'Google sign-in could not be completed. Please try again.');
        }
        $email = strtolower((string) $google->getEmail());
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 255 && $google->getId() && strlen((string) $google->getId()) <= 255 && ($google->user['verified_email'] ?? $google->user['email_verified'] ?? false) === true, 422, 'Google must provide a verified email address.');
        $user = DB::transaction(function () use ($request, $linkId, $google, $email) {
            $linked = User::where('google_id', $google->getId())->lockForUpdate()->first();
            if ($linkId) {
                abort_unless($request->user() && $request->user()->id === (int) $linkId, 403);
                abort_if($linked && $linked->id !== (int) $linkId, 422, 'This Google account is already linked to another account.');
                $user = User::whereKey($linkId)->lockForUpdate()->firstOrFail();
                $user->forceFill(['google_id' => $google->getId()]);
                if ($user->email === $email && ! $user->hasVerifiedEmail()) {
                    $user->email_verified_at = now();
                }
                $user->save();

                return $user;
            }
            if ($linked) {
                return $linked;
            }
            if (User::where('email', $email)->exists()) {
                return null;
            }
            $user = new User(['name' => Str::limit($google->getName() ?: 'Football coach', 255, ''), 'email' => $email, 'password' => Str::random(64)]);
            $user->forceFill(['google_id' => $google->getId(), 'email_verified_at' => now()])->save();

            return $user;
        });
        if (! $user) {
            return redirect()->route('login')->with('status', 'An account already uses that email. Sign in with your password, then choose Link Google.');
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('current_world_id');

        return redirect()->route('worlds.index')->with('status', $linkId ? 'Google account linked.' : 'Welcome to Football.');
    }
}
