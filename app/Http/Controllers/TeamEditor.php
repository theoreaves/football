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
            'stadiumCopies' => $this->stadiumCopies(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        unset($data['uniform_logo_uploads'], $data['uniform_logo_clear'], $data['field_25_logo_upload'], $data['field_25_logo_clear']);
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
            'specialistPlayers' => $team->players()->wherePivot('team_year', $team->players()->max('team_players.team_year'))->orderBy('lastname')->get(),
            'stadiumCopies' => $this->stadiumCopies($team),
        ]);
    }

    public function update(Request $request, Team $team)
    {
        $data = $this->validated($request, $team);

        unset($data['uniform_logo_uploads'], $data['uniform_logo_clear'], $data['field_25_logo_upload'], $data['field_25_logo_clear']);
        if (isset($data['team_settings'])) {
            $data['team_settings'] = array_replace_recursive($team->team_settings ?? [], $data['team_settings']);
        }
        $team->update($data);

        $this->handleUploads($request, $team);

        return redirect()
            ->route('teams.editor.edit', $team)
            ->with('status', 'Team updated.');
    }

    private function stadiumCopies(?Team $current = null)
    {
        // Team's world scope limits this catalog to the active save.
        return Team::orderBy('city')->orderBy('name')->get()->reject(fn ($team) => $current && $team->id === $current->id)
            ->map(fn ($team) => [
                'id' => $team->id, 'label' => trim($team->city.' '.$team->name),
                'stadium_style' => $team->stadium_style ?: 'classic_oval',
                'stadium_seat_color' => $team->stadium_seat_color ?: ($team->team_color1 ?: '#315b85'),
                'stadium_wall_color' => $team->stadium_wall_color ?: '#657587',
                'stadium_roof_color' => $team->stadium_roof_color ?: '#cbd5e1',
                'stadium' => $team->team_settings['stadium'] ?? [],
            ])->values();
    }

    private function validated(Request $request, ?Team $team = null): array
    {
        $hex = ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        $appearance = [
            'stadium_style' => ['sometimes', 'required', \Illuminate\Validation\Rule::in(array_keys(config('stadiums')))],
            'stadium_seat_color' => $hex,
            'stadium_wall_color' => $hex,
            'stadium_roof_color' => $hex,
            'endzone_transparent' => ['sometimes', 'boolean'],
            'endzone_text' => ['nullable', 'string', 'max:40'],
            'endzone_background' => $hex,
            'endzone_text_color' => $hex,
        ];
        foreach (['home', 'away'] as $venue) {
            $appearance["uniform_{$venue}_name_enabled"] = ['sometimes', 'boolean'];
            $appearance["uniform_{$venue}_name_color"] = $hex;
            foreach (['helmet', 'shoulder', 'pants'] as $part) {
                $appearance["uniform_{$venue}_{$part}_stripe"] = $hex;
                $appearance["uniform_{$venue}_{$part}_stripe_enabled"] = ['sometimes', 'boolean'];
            }
            foreach (['helmet', 'facemask', 'shirt', 'pants', 'socks', 'number', 'number_outline'] as $part) {
                $appearance["uniform_{$venue}_{$part}"] = $hex;
            }
        }

        $settings = [
            'team_settings' => ['sometimes', 'array:white_at_home,coaching,staff,cheerleaders,mascot,stadium,helmets,specialists,championship_endzone,uniform_patterns,uniform_stripe_colors,uniform_logos,field_art'],
            'team_settings.championship_endzone' => ['sometimes', 'array:enabled,text,background,text_color,transparent'],
            'team_settings.championship_endzone.enabled' => ['sometimes', 'boolean'],
            'team_settings.championship_endzone.transparent' => ['sometimes', 'boolean'],
            'team_settings.championship_endzone.text' => ['nullable', 'string', 'max:40'],
            'team_settings.championship_endzone.background' => $hex,
            'team_settings.championship_endzone.text_color' => $hex,
            'team_settings.stadium' => ['sometimes', 'array:name,tiers,rows,row_height,one_color,tier_colors,wall_one_color,tier_wall_colors,tunnels,scoreboards,scoreboard_size,lighting'],
            'team_settings.stadium.tunnels' => ['sometimes', 'boolean'],
            'team_settings.stadium.scoreboards' => ['sometimes', \Illuminate\Validation\Rule::in(['both', 'left', 'right'])],
            'team_settings.stadium.scoreboard_size' => ['sometimes', \Illuminate\Validation\Rule::in(['small', 'medium', 'large'])],
            'team_settings.stadium.lighting' => ['sometimes', \Illuminate\Validation\Rule::in(['corners', 'rim'])],
            'team_settings.stadium.name' => ['nullable', 'string', 'max:80'],
            'team_settings.stadium.tiers' => ['nullable', 'integer', 'between:1,3'],
            'team_settings.stadium.rows' => ['nullable', 'integer', 'between:4,12'],
            'team_settings.stadium.row_height' => ['nullable', 'numeric', 'between:0.75,1.75'],
            'team_settings.stadium.wall_one_color' => ['sometimes', 'boolean'],
            'team_settings.stadium.tier_wall_colors' => ['sometimes', 'array:lower,middle,upper'],
            'team_settings.stadium.tier_wall_colors.lower' => $hex,
            'team_settings.stadium.tier_wall_colors.middle' => $hex,
            'team_settings.stadium.tier_wall_colors.upper' => $hex,
            'team_settings.stadium.one_color' => ['sometimes', 'boolean'],
            'team_settings.stadium.tier_colors' => ['sometimes', 'array:lower,middle,upper'],
            'team_settings.stadium.tier_colors.lower' => $hex,
            'team_settings.stadium.tier_colors.middle' => $hex,
            'team_settings.stadium.tier_colors.upper' => $hex,
            'team_settings.white_at_home' => ['sometimes', 'boolean'],
            'team_settings.coaching' => ['sometimes', 'array:'.implode(',', array_keys(config('team-settings.coaching')))],
            'team_settings.staff' => ['sometimes', 'array:head,offense,defense'],
            'team_settings.cheerleaders' => ['sometimes', 'array:enabled,primary,secondary,accent'],
            'team_settings.cheerleaders.enabled' => ['sometimes', 'boolean'],
            'team_settings.mascot' => ['sometimes', 'array:enabled,name,character,fur,shirt'],
            'team_settings.mascot.enabled' => ['sometimes', 'boolean'],
            'team_settings.mascot.name' => ['nullable', 'string', 'max:80'],
            'team_settings.mascot.character' => ['sometimes', \Illuminate\Validation\Rule::in(array_keys(config('team-settings.mascot_characters')))],
            'team_settings.mascot.fur' => $hex,
            'team_settings.mascot.shirt' => $hex,
        ];
        $settings['team_settings.specialists'] = ['sometimes', 'array:third_down_back,fourth_down_back,kick_returner,punt_returner'];
        foreach (['third_down_back', 'fourth_down_back', 'kick_returner', 'punt_returner'] as $role) {
            $settings['team_settings.specialists.'.$role] = ['nullable', 'integer', function ($attribute, $value, $fail) use ($team, $role) {
                $positions = $role === 'fourth_down_back' ? array_unique(array_merge(...array_values(\App\Services\Simulation\RosterBuilder::GROUPS))) : ($role === 'third_down_back' ? ['RB', 'FB'] : ['RB', 'FB', 'WR', 'TE', 'CB', 'DB', 'S', 'FS', 'SS']);
                $player = $team?->players()->wherePivot('team_year', $team->players()->max('team_players.team_year'))->where('players.id', $value)->first();
                if (! $player || ! in_array(strtoupper($player->pivot->position ?: $player->position), $positions, true)) {
                    $fail('Choose an eligible player from this team’s current roster.');
                }
            }];
        }
        $settings['team_settings.uniform_stripe_colors'] = ['sometimes', 'array:home,away'];
        foreach (['home', 'away'] as $venue) {
            $settings['team_settings.uniform_stripe_colors.'.$venue] = ['sometimes', 'array:helmet,shoulder,pants'];
            foreach (['helmet', 'shoulder', 'pants'] as $part) {
                $settings['team_settings.uniform_stripe_colors.'.$venue.'.'.$part] = ['sometimes', 'array:left,center,right'];
                foreach (['left','center','right'] as $side) {
                    $settings['team_settings.uniform_stripe_colors.'.$venue.'.'.$part.'.'.$side] = $hex;
                }
            }
        }
        $settings['team_settings.uniform_patterns'] = ['sometimes', 'array:home,away'];
        foreach (['home', 'away'] as $venue) {
            $settings['team_settings.uniform_patterns.'.$venue] = ['sometimes', 'array:helmet,shoulder,pants'];
            foreach (['helmet', 'shoulder', 'pants'] as $part) {
                $settings['team_settings.uniform_patterns.'.$venue.'.'.$part] = ['sometimes',
                    \Illuminate\Validation\Rule::in(['single','double','triple','accent'])];
            }
        }
        // U2B images are stored in team_settings after upload, not accepted as arbitrary paths.
        // Validate the upload request prior to saving team data.
        foreach (['home', 'away'] as $venue) {
            foreach (['chest', 'sleeve'] as $part) {
                $settings["uniform_logo_uploads.{$venue}.{$part}"] = ['nullable', 'image', 'max:5120'];
                $settings["uniform_logo_clear.{$venue}.{$part}"] = ['sometimes', 'boolean'];
            }
        }
        $settings['uniform_logo_uploads'] = ['sometimes', 'array:home,away'];
        $settings['uniform_logo_uploads.home'] = ['sometimes', 'array:chest,sleeve'];
        $settings['uniform_logo_uploads.away'] = ['sometimes', 'array:chest,sleeve'];
        $settings['uniform_logo_clear'] = ['sometimes', 'array:home,away'];
        $settings['uniform_logo_clear.home'] = ['sometimes', 'array:chest,sleeve'];
        $settings['uniform_logo_clear.away'] = ['sometimes', 'array:chest,sleeve'];
        $settings['team_settings.field_art'] = ['sometimes', 'array:yard25_position'];
        $settings['team_settings.field_art.yard25_position'] = ['sometimes', \Illuminate\Validation\Rule::in(['both', 'left', 'right', 'none'])];
        $settings['field_25_logo_upload'] = ['nullable', 'image', 'max:5120'];
        $settings['field_25_logo_clear'] = ['sometimes', 'boolean'];
        $settings['team_settings.helmets'] = ['sometimes', 'array:home,away,flip_left,flip_right,logo_color'];
        $settings['team_settings.helmets.logo_color'] = $hex;
        foreach (['flip_left', 'flip_right'] as $side) {
            $settings['team_settings.helmets.'.$side] = ['sometimes', 'boolean'];
        }
        foreach (['home', 'away'] as $venue) {
            $settings['team_settings.helmets.'.$venue] = ['sometimes', 'array:design'];
            $settings['team_settings.helmets.'.$venue.'.design'] = ['sometimes', \Illuminate\Validation\Rule::in(['standard', 'cat_stripes', 'ram_horns'])];
        }
        foreach (array_keys(config('team-settings.coaching')) as $key) {
            $settings["team_settings.coaching.{$key}"] = ['sometimes', 'integer', 'between:0,100'];
        }
        foreach (array_keys(config('team-settings.staff')) as $role) {
            $settings["team_settings.staff.{$role}"] = ['sometimes', 'array:name,skin,hair,hair_color,build,shirt,pants,hat,hat_color'];
            $settings["team_settings.staff.{$role}.name"] = ['nullable', 'string', 'max:80'];
            foreach (['skin', 'hair_color', 'shirt', 'pants', 'hat_color'] as $part) {
                $settings["team_settings.staff.{$role}.{$part}"] = $hex;
            }
            $settings["team_settings.staff.{$role}.hat"] = ['sometimes', 'boolean'];
            $settings["team_settings.staff.{$role}.hair"] = ['sometimes', \Illuminate\Validation\Rule::in(['bald', 'buzz', 'short', 'curly', 'long'])];
            $settings["team_settings.staff.{$role}.build"] = ['sometimes', \Illuminate\Validation\Rule::in(['slim', 'average', 'stocky'])];
        }
        foreach (['primary', 'secondary', 'accent'] as $part) {
            $settings["team_settings.cheerleaders.{$part}"] = $hex;
        }

        // Validate every upload before changing the team, including other tabs.
        $audioRules = ['audio_uploads' => ['sometimes', 'array:'.implode(',', array_keys(config('team-audio')))],
            'audio_clear' => ['sometimes', 'array:'.implode(',', array_keys(config('team-audio')))]];
        foreach (array_keys(config('team-audio')) as $event) {
            $audioRules["audio_uploads.{$event}"] = ['nullable', 'file', 'mimes:mp3,wav,ogg', 'extensions:mp3,wav,ogg', 'max:15360'];
            $audioRules["audio_clear.{$event}"] = ['sometimes', 'boolean'];
        }
        $request->validate($audioRules + [
            'championship_endzone_left' => ['nullable', 'image', 'max:5120'],
            'championship_endzone_right' => ['nullable', 'image', 'max:5120'],
            'championship_endzone_clear' => ['sometimes', 'array:left,right'],
            'championship_endzone_clear.*' => ['boolean'],
        ]);

        return $request->validate(array_merge($appearance, $settings, [
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
        $profile = $team->team_settings ?? [];
        foreach (array_keys(config('team-audio')) as $event) {
            if ($request->boolean("audio_clear.{$event}")) {
                unset($profile['audio'][$event]);
            }
            if ($request->hasFile("audio_uploads.{$event}")) {
                $file = $request->file("audio_uploads.{$event}");
                // Immutable filenames retain the audio used by existing game snapshots.
                $profile['audio'][$event] = $file->storeAs("teams/{$team->id}/audio",
                    (string) \Illuminate\Support\Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'team_art');
            }
        }
        foreach (['left', 'right'] as $side) {
            if ($request->boolean('championship_endzone_clear.'.$side)) {
                unset($profile['championship_endzone']['art_'.$side]);
            }
            if ($request->hasFile('championship_endzone_'.$side)) {
                // Immutable paths keep artwork usable in already-started game snapshots.
                $profile['championship_endzone']['art_'.$side] = $request->file('championship_endzone_'.$side)
                    ->storeAs("teams/{$team->id}/championship", (string) \Illuminate\Support\Str::uuid().'.'.$request->file('championship_endzone_'.$side)->extension(), 'team_art');
            }
        }
        $team->update(['team_settings' => $profile]);

        // U2B: two optional transparent uniform graphics per venue.
        // Immutable filenames keep old exhibition snapshots intact.
        foreach (['home', 'away'] as $venue) {
            foreach (['chest', 'sleeve'] as $part) {
                $old = $profile['uniform_logos'][$venue][$part] ?? null;
                if ($request->boolean("uniform_logo_clear.{$venue}.{$part}")) {
                    unset($profile['uniform_logos'][$venue][$part]);
                    // Don't delete old artwork: saved games may still refer to it.
                }
                if ($request->hasFile("uniform_logo_uploads.{$venue}.{$part}")) {
                    $file = $request->file("uniform_logo_uploads.{$venue}.{$part}");
                    $profile['uniform_logos'][$venue][$part] = $file->storeAs(
                        "teams/{$team->id}/uniforms",
                        (string) \Illuminate\Support\Str::uuid().'.'.$file->extension(),
                        'team_art'
                    );
                }
            }
        }
        $team->update(['team_settings' => $profile]);

        if ($request->boolean('field_25_logo_clear')) {
            unset($profile['field_art']['yard25_logo']);
        }
        if ($request->hasFile('field_25_logo_upload')) {
            $file = $request->file('field_25_logo_upload');
            $profile['field_art']['yard25_logo'] = $file->storeAs(
                "teams/{$team->id}/field", (string) \Illuminate\Support\Str::uuid().'.'.$file->extension(), 'team_art'
            );
        }
        $team->update(['team_settings' => $profile]);

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
                    Storage::disk('team_art')->delete($team->{$field});
                }
                $team->update([$field => null]);
            }
            if (! $request->hasFile($field)) {
                continue;
            }

            // delete old
            if ($team->{$field}) {
                Storage::disk('team_art')->delete($team->{$field});
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
                    Storage::disk('team_art')->put($storedPath, $pngBytes);
                    $team->update([$field => $storedPath]);

                    continue;
                }
                // fall through to store original if wand fails
            }

            $storedPath = $file->storeAs(
                "teams/{$team->id}",
                "{$field}.".$file->extension(),
                'team_art'
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
