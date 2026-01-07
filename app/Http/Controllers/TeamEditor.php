<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TeamEditor extends Controller
{
    public function index()
    {
        $teams = Team::orderBy('city')->orderBy('name')->paginate(25);

        return view('teams.editor.index', compact('teams'));
    }

    public function create()
    {
        $team = new Team();

        return view('teams.editor.form', [
            'team' => $team,
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $team = Team::create($data);

        $this->handleUploads($request, $team);

        return redirect()
            ->route('teams.edit', $team)
            ->with('status', 'Team created.');
    }

    public function edit(Team $team)
    {
        return view('teams.editor.form', [
            'team' => $team,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, Team $team)
    {
        $data = $this->validated($request);

        $team->update($data);

        $this->handleUploads($request, $team);

        return redirect()
            ->route('teams.editor.edit', $team)
            ->with('status', 'Team updated.');
    }

    public function destroy(Team $team)
    {
        // optional: delete team images folder
//        Storage::disk('public')->deleteDirectory("teams/{$team->id}");

//        $team->delete();

        return redirect()
            ->route('teams.editor.index')
            ->with('status', 'Team deleted.');
    }

    private function validated(Request $request): array
    {
        $hex = ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return $request->validate([
            'city' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],

            'playcalling_behind' => ['required', 'integer', 'min:-10'],
            'playcalling_tied' => ['required', 'integer', 'min:-10'],
            'playcalling_ahead' => ['required', 'integer', 'min:-10'],

            'ol_rush' => ['required', 'integer', 'min:0'],
            'ol_power' => ['required', 'integer', 'min:0'],
            'ol_pass' => ['required', 'integer', 'min:0'],
            'ol_protect' => ['required', 'integer', 'min:0'],

            'team_color1' => $hex,
            'team_color2' => $hex,

            'jersey_dark_primary' => $hex,
            'jersey_dark_outline' => $hex,
            'jersey_dark_font' => $hex,
            'jersey_white_primary' => $hex,
            'jersey_white_outline' => $hex,
            'jersey_white_font' => $hex,
            'wear_white_at_home' => ['required', 'boolean'],

            // files validated in handleUploads() so update doesn’t require re-upload
        ]);
    }

    private function handleUploads(Request $request, Team $team): void
    {
        $fields = [
            'team_logo',
            'helmet_logo_right',
            'helmet_logo_left',
            'midfield_logo',
            'endzone_logo_right',
            'endzone_logo_left',
            'game_field_image',
        ];

        // validate files if present
        $request->validate(array_fill_keys($fields, ['nullable', 'image', 'max:5120'])); // 5MB

        foreach ($fields as $field) {
            if (! $request->hasFile($field)) {
                continue;
            }

            // delete old
            if ($team->{$field}) {
                Storage::disk('public')->delete($team->{$field});
            }

            $file = $request->file($field);
            $storedPath = $file->storeAs(
                "teams/{$team->id}",
                "{$field}." . $file->getClientOriginalExtension(),
                'public'
            );

            $team->update([$field => $storedPath]);
        }
    }
}
