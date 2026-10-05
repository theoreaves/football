<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;

class PracticeController extends Controller
{
    public function __invoke(Request $request)
    {
        $teams = Team::orderBy('city')->orderBy('name')->get();
        $home = $request->filled('home') ? $teams->firstWhere('id', $request->integer('home')) : $teams->first();
        $away = $request->filled('away') ? $teams->firstWhere('id', $request->integer('away')) : $teams->first(fn ($team) => $team->id !== $home?->id);
        abort_if(($request->filled('home') && ! $home) || ($request->filled('away') && ! $away), 404);
        $appearance = ['home' => $this->appearance($home, 'home'), 'away' => $this->appearance($away, 'away')];

        return view('practice.index', compact('teams', 'home', 'away', 'appearance'));
    }

    public function art(Team $team, string $asset)
    {
        abort_unless(in_array($asset, ['team_logo', 'helmet_logo_left', 'helmet_logo_right', 'midfield_logo', 'endzone_logo_left', 'endzone_logo_right'], true), 404);
        $path = $team->{$asset};
        $disk = \Illuminate\Support\Facades\Storage::disk('team_art');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function appearance(?Team $team, string $venue): array
    {
        if (! $team) {
            return [];
        }
        $art = fn ($asset) => $team->{$asset} ? route('teams.art', ['team' => $team, 'asset' => $asset]) : null;
        $uniform = [];
        foreach (['helmet', 'facemask', 'shirt', 'pants', 'socks', 'number', 'number_outline'] as $part) {
            $fallback = $part === 'shirt' ? ($venue === 'home' ? ($team->team_color1 ?: '#3997ff') : '#ffffff') : '#e2e8f0';
            if ($part === 'number' || $part === 'number_outline') {
                $fallback = $part === 'number' ? '#ffffff' : '#111111';
            }
            if ($part === 'facemask') {
                $fallback = '#17202b';
            }
            $uniform[$part] = $team->{"uniform_{$venue}_{$part}"} ?: $fallback;
        }

        foreach (['helmet', 'shoulder', 'pants'] as $part) {
            $uniform[$part.'_stripe'] = $team->{"uniform_{$venue}_{$part}_stripe"} ?: '#ffffff';
            $uniform[$part.'_stripe_enabled'] = (bool) $team->{"uniform_{$venue}_{$part}_stripe_enabled"};
        }

        return [
            'helmet_logo_left' => $art('helmet_logo_left'),
            'helmet_logo_right' => $art('helmet_logo_right'),
            'midfield_logo' => $art('midfield_logo') ?: $art('team_logo'),
            'endzone_logo_left' => $art('endzone_logo_left'),
            'endzone_logo_right' => $art('endzone_logo_right'),
            'endzone_transparent' => (bool) $team->endzone_transparent,
            'name' => $team->name,
            'uniform' => $uniform,
            'endzone_text' => $team->endzone_text ?: $team->name,
            'endzone_background' => $team->endzone_background ?: ($team->team_color1 ?: '#174880'),
            'endzone_text_color' => $team->endzone_text_color ?: '#ffffff',
        ];
    }
}
