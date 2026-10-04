<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TeamEditor extends Controller
{
    public function index()
    {
        $teams = Team::orderBy('city')->orderBy('name')->paginate(25);

        return view('teams.editor.index', compact('teams'));
    }

    public function create()
    {
        $team = new Team;

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
            ->route('teams.editor.edit', $team)
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

    private function validated(Request $request): array
    {
        $hex = ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        $appearance = [
            'endzone_transparent' => ['sometimes', 'boolean'],
            'endzone_text' => ['nullable', 'string', 'max:40'],
            'endzone_background' => $hex,
            'endzone_text_color' => $hex,
        ];
        foreach (['home', 'away'] as $venue) {
            foreach (['helmet', 'shoulder', 'pants'] as $part) {
                $appearance["uniform_{$venue}_{$part}_stripe"] = $hex;
                $appearance["uniform_{$venue}_{$part}_stripe_enabled"] = ['sometimes', 'boolean'];
            }
            foreach (['helmet', 'facemask', 'shirt', 'pants', 'socks', 'number', 'number_outline'] as $part) {
                $appearance["uniform_{$venue}_{$part}"] = $hex;
            }
        }

        return $request->validate(array_merge($appearance, [
            'city' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],

            'abbr' => ['nullable', 'string', 'max:8'],
            'conference' => ['nullable', 'string', 'max:80'],
            'division' => ['nullable', 'string', 'max:80'],
            'team_color1' => $hex,
            'team_color2' => $hex,

        ]));
    }

    private function handleUploads(Request $request, Team $team): void
    {
        $fields = [
            'team_logo',
            'helmet_logo_right',
            'helmet_logo_left',
            'midfield_logo',
            'endzone_logo_left',
            'endzone_logo_right',
        ];

        $request->validate(array_fill_keys($fields, ['nullable', 'image', 'max:5120']));

        foreach ($fields as $field) {
            if ($request->boolean('clear_'.$field)) {
                if ($team->{$field}) {
                    Storage::disk('public')->delete($team->{$field});
                }
                $team->update([$field => null]);
            }
            if (! $request->hasFile($field)) {
                continue;
            }

            // delete old
            if ($team->{$field}) {
                Storage::disk('public')->delete($team->{$field});
            }

            $file = $request->file($field);

            // Only run wand-removal on “art” assets; typically skip full field images
            $shouldWand = in_array($field, [
                'team_logo',
                'helmet_logo_right',
                'helmet_logo_left',
                'midfield_logo',
                'endzone_logo_left',
                'endzone_logo_right',
            ], true);

            if ($shouldWand && config('services.bg_remove.url')) {
                // You can tune these tolerances per asset type
                $tol = 25;

                $pngBytes = $this->removeBackgroundToPngBytesWand($file, $tol);

                if ($pngBytes) {
                    $storedPath = "teams/{$team->id}/{$field}.png";
                    Storage::disk('public')->put($storedPath, $pngBytes);
                    $team->update([$field => $storedPath]);

                    continue;
                }
                // fall through to store original if wand fails
            }

            $storedPath = $file->storeAs(
                "teams/{$team->id}",
                "{$field}.".$file->getClientOriginalExtension(),
                'public'
            );

            $team->update([$field => $storedPath]);
        }
    }

    private function removeBackgroundToPngBytesWand(UploadedFile $file, int $tol = 25): ?string
    {
        $base = rtrim(config('services.bg_remove.url'), '/');
        $url = $base.'/remove-wand?tol='.$tol;

        $token = config('services.bg_remove.token');

        try {
            $req = Http::timeout(60);

            if ($token) {
                $req = $req->withHeaders(['X-BG-Token' => $token]);
            }

            $resp = $req->attach(
                'image',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            )->post($url);

            if (! $resp->successful()) {
                return null;
            }

            return $resp->body(); // PNG bytes
        } catch (ConnectionException $e) {
            return null;
        }
    }
}
