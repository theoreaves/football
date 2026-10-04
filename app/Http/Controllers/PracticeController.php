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

    public function appearance(?Team $team, string $venue): array
    {
        if (! $team) {
            return [];
        }
        $uniform = [];
        foreach (['helmet', 'shirt', 'pants', 'socks', 'number', 'number_outline'] as $part) {
            $fallback = $part === 'shirt' ? ($venue === 'home' ? ($team->team_color1 ?: '#3997ff') : '#ffffff') : '#e2e8f0';
            if ($part === 'number' || $part === 'number_outline') {
                $fallback = $part === 'number' ? '#ffffff' : '#111111';
            }
            $uniform[$part] = $team->{"uniform_{$venue}_{$part}"} ?: $fallback;
        }

        return [
            'name' => $team->name,
            'uniform' => $uniform,
            'endzone_text' => $team->endzone_text ?: $team->name,
            'endzone_background' => $team->endzone_background ?: ($team->team_color1 ?: '#174880'),
            'endzone_text_color' => $team->endzone_text_color ?: '#ffffff',
        ];
    }
}
