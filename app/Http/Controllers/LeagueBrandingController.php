<?php

namespace App\Http\Controllers;

use App\Models\League;
use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LeagueBrandingController extends Controller
{
    public function update(Request $request, League $league)
    {
        $data = $request->validate([
            'season_id' => ['required', 'integer'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'clear_logo' => ['sometimes', 'boolean'],
        ]);
        $season = Season::findOrFail($data['season_id']);
        abort_unless((int) $season->league_id === (int) $league->id, 404);
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $path = $file->storeAs("leagues/{$league->id}", Str::uuid().'.'.$file->extension(), 'team_art');
            $league->update(['logo_path' => $path]);
        } elseif ($request->boolean('clear_logo')) {
            $league->update(['logo_path' => null]);
        }
        return redirect()->route('seasons.show', ['season' => $season, 'tab' => 'settings'])->with('status', 'League branding updated.');
    }

    public function logo(League $league)
    {
        $disk = Storage::disk('team_art');
        abort_unless($league->logo_path && $disk->exists($league->logo_path), 404);
        return $disk->response($league->logo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
