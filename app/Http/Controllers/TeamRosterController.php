<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\Request;

class TeamRosterController extends Controller
{
    public function index(Team $team, Request $request)
    {
        $year = (string)($request->get('year') ?? '2025');
        $q = trim((string) $request->get('q', ''));
        $position = trim((string) $request->get('position', ''));

        // Get distinct positions for dropdown
        $positions = $team->players()
            ->wherePivot('team_year', $year)
            ->select('team_players.position')
            ->distinct()
            ->orderBy('team_players.position')
            ->pluck('position')
            ->filter()
            ->values();

        $positionCounts = $team->players()
            ->wherePivot('team_year', $year)
            ->selectRaw('team_players.position as position, COUNT(*) as cnt')
            ->groupBy('team_players.position')
            ->orderBy('team_players.position')
            ->get();

        $totalCount = (int) $positionCounts->sum('cnt');


        $players = $team->players()
            ->wherePivot('team_year', $year)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('firstname', 'like', "%{$q}%")
                        ->orWhere('lastname', 'like', "%{$q}%")
                        ->orWhere('players.position', 'like', "%{$q}%")
                        ->orWhere('team_players.depth_chart_position', 'like', "%{$q}%");
                });
            })
            ->when($position !== '', function ($query) use ($position) {
                $query->where('team_players.position', $position);
            })
            ->orderBy('team_players.depth_chart_position')
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->paginate(25)
            ->withQueryString();

        return view('teams.players.index', compact(
            'team',
            'players',
            'year',
            'q',
            'position',
            'positions',
            'positionCounts',
            'totalCount'
        ));

    }


    public function create(Team $team, Request $request)
    {
        $year = (string)($request->get('year') ?? '2025');
        $player = new Player();

        // sensible defaults for pivot ranges, adjust if you want
        $pivot = [
            'team_year' => $year,
            'position' => old('tp_position', old('position', 'QB')),
            'depth_chart_position' => old('depth_chart_position', 'QB1'),
            'kick_return_depth_chart_position' => old('kick_return_depth_chart_position', 'KR1'),
            'punt_return_depth_chart_position' => old('punt_return_depth_chart_position', 'PR1'),
            'jersey_number' => old('jersey_number', null),

            'catch_from' => old('catch_from', 0),
            'catch_to' => old('catch_to', 0),
            'catch_plus_from' => old('catch_plus_from', 0),
            'catch_plus_to' => old('catch_plus_to', 0),

            'rush_from' => old('rush_from', 0),
            'rush_to' => old('rush_to', 0),

            'sack_from' => old('sack_from', 0),
            'sack_to' => old('sack_to', 0),

            'interception_from' => old('interception_from', 0),
            'interception_to' => old('interception_to', 0),

            'tackle_from' => old('tackle_from', 0),
            'tackle_to' => old('tackle_to', 0),

            'kick_from' => old('kick_from', 0),
            'kick_to' => old('kick_to', 0),

            'punt_from' => old('punt_from', 0),
            'punt_to' => old('punt_to', 0),
        ];

        return view('teams.players.form', [
            'team' => $team,
            'player' => $player,
            'year' => $year,
            'mode' => 'create',
            'pivot' => $pivot,
        ]);
    }

    public function store(Team $team, Request $request)
    {
        $year = (string)($request->get('year') ?? '2025');

        [$playerData, $pivotData] = $this->validated($request, $year);

        $player = Player::create($playerData);

        // attach pivot for this team/year
        $team->players()->attach($player->id, $pivotData);

        return redirect()
            ->route('teams.editor.teams.players.edit', [$team, $player, 'year' => $year])
            ->with('status', 'Player added to roster.');
    }

    public function edit(Team $team, Player $player, Request $request)
    {
        $year = (string)($request->get('year') ?? '2025');

        $attached = $team->players()
            ->where('players.id', $player->id)
            ->wherePivot('team_year', $year)
            ->firstOrFail();

        $pivotRow = $attached->pivot;

        // Build a pivot array for the form
        $pivot = [
            'team_year' => $year,
            'position' => old('tp_position', $pivotRow->position),
            'depth_chart_position' => old('depth_chart_position', $pivotRow->depth_chart_position),
            'kick_return_depth_chart_position' => old('kick_return_depth_chart_position', $pivotRow->kick_return_depth_chart_position),
            'punt_return_depth_chart_position' => old('punt_return_depth_chart_position', $pivotRow->punt_return_depth_chart_position),
            'jersey_number' => old('jersey_number', $pivotRow->jersey_number),

            'catch_from' => old('catch_from', $pivotRow->catch_from),
            'catch_to' => old('catch_to', $pivotRow->catch_to),
            'catch_plus_from' => old('catch_plus_from', $pivotRow->catch_plus_from),
            'catch_plus_to' => old('catch_plus_to', $pivotRow->catch_plus_to),

            'rush_from' => old('rush_from', $pivotRow->rush_from),
            'rush_to' => old('rush_to', $pivotRow->rush_to),

            'sack_from' => old('sack_from', $pivotRow->sack_from),
            'sack_to' => old('sack_to', $pivotRow->sack_to),

            'interception_from' => old('interception_from', $pivotRow->interception_from),
            'interception_to' => old('interception_to', $pivotRow->interception_to),

            'tackle_from' => old('tackle_from', $pivotRow->tackle_from),
            'tackle_to' => old('tackle_to', $pivotRow->tackle_to),

            'kick_from' => old('kick_from', $pivotRow->kick_from),
            'kick_to' => old('kick_to', $pivotRow->kick_to),

            'punt_from' => old('punt_from', $pivotRow->punt_from),
            'punt_to' => old('punt_to', $pivotRow->punt_to),
        ];

        return view('teams.players.form', [
            'team' => $team,
            'player' => $player,
            'year' => $year,
            'mode' => 'edit',
            'pivot' => $pivot,
        ]);
    }

    public function update(Team $team, Player $player, Request $request)
    {
        $year = (string)($request->get('year') ?? '2025');

        [$playerData, $pivotData] = $this->validated($request, $year);

        $player->update($playerData);

        // Ensure this player is attached for the given team_year
        $exists = $team->players()
            ->where('players.id', $player->id)
            ->wherePivot('team_year', $year)
            ->exists();

        abort_unless($exists, 404);

        // update pivot
        $team->players()->updateExistingPivot($player->id, $pivotData);

        return redirect()
            ->route('teams.editor.teams.players.edit', [$team, $player, 'year' => $year])
            ->with('status', 'Player updated.');
    }

    /**
     * Returns: [playerData, pivotData]
     */
    private function validated(Request $request, string $year): array
    {
        $data = $request->validate([
            // --- players table ---
            'firstname' => ['required', 'string', 'max:255'],
            'lastname'  => ['required', 'string', 'max:255'],
            'age'       => ['required', 'integer', 'min:0', 'max:99'],
            'position'  => ['required', 'string', 'max:10'],
            'college'  => ['nullable', 'string'],
            'high_school'  => ['nullable', 'string'],
            'sleeper_id'  => ['nullable', 'string'],
            'espn_id'  => ['nullable', 'string'],

            'pass_evade' => ['required','integer','min:0'],
            'pass_accuracy' => ['required','integer','min:0'],
            'pass_deep' => ['required','integer','min:0'],
            'pass_control' => ['required','integer','min:0'],
            'rush' => ['required','integer','min:0'],
            'rush_power' => ['required','integer','min:0'],
            'receive' => ['required','integer','min:0'],
            'receive_deep' => ['required','integer','min:0'],
            'fumble' => ['required','integer','min:0'],
            'speed' => ['required','integer','min:0'],
            'tackle' => ['required','integer','min:0'],
            'sack' => ['required','integer','min:0'],
            'cover' => ['required','integer','min:0'],
            'interception' => ['required','integer','min:0'],
            'strip' => ['required','integer','min:0'],
            'kick30' => ['required','integer','min:0'],
            'kick39' => ['required','integer','min:0'],
            'kick49' => ['required','integer','min:0'],
            'kick50' => ['required','integer','min:0'],
            'punt_distance' => ['required','integer','min:0'],
            'punt_pooch_yard' => ['required','integer','min:0'],
            'punt_pooch' => ['required','integer','min:0'],
            'punt_block' => ['required','integer','min:0'],
            'return_yards' => ['required','integer','min:0'],
            'return_speed' => ['required','integer','min:0'],
            'return_fumble' => ['required','integer','min:0'],

            // --- team_players pivot ---
            'tp_position' => ['required', 'string', 'max:10'],
            'depth_chart_position' => ['required', 'string', 'max:10'],
            'kick_return_depth_chart_position' => ['required', 'string', 'max:10'],
            'punt_return_depth_chart_position' => ['required', 'string', 'max:10'],

            'catch_from' => ['required','integer'],
            'catch_to' => ['required','integer'],
            'catch_plus_from' => ['required','integer'],
            'catch_plus_to' => ['required','integer'],
            'rush_from' => ['required','integer'],
            'rush_to' => ['required','integer'],
            'sack_from' => ['required','integer'],
            'sack_to' => ['required','integer'],
            'interception_from' => ['required','integer'],
            'interception_to' => ['required','integer'],
            'tackle_from' => ['required','integer'],
            'tackle_to' => ['required','integer'],
            'kick_from' => ['required','integer'],
            'kick_to' => ['required','integer'],
            'punt_from' => ['required','integer'],
            'punt_to' => ['required','integer'],

            'jersey_number' => ['nullable','integer','min:0','max:99'],
        ]);

        $playerData = collect($data)->only([
            'firstname','lastname','age','position',
            'college','high_school','sleeper_id','espn_id',
            'pass_evade','pass_accuracy','pass_deep','pass_control',
            'rush','rush_power',
            'receive','receive_deep',
            'fumble','speed',
            'tackle','sack','cover','interception','strip',
            'kick30','kick39','kick49','kick50',
            'punt_distance','punt_pooch_yard','punt_pooch','punt_block',
            'return_yards','return_speed','return_fumble',
        ])->all();

        $pivotData = collect($data)->only([
            'depth_chart_position',
            'kick_return_depth_chart_position',
            'punt_return_depth_chart_position',
            'catch_from','catch_to','catch_plus_from','catch_plus_to',
            'rush_from','rush_to',
            'sack_from','sack_to',
            'interception_from','interception_to',
            'tackle_from','tackle_to',
            'kick_from','kick_to',
            'punt_from','punt_to',
            'jersey_number',
        ])->all();

        // pivot uses its own position column; avoid clobbering players.position
        $pivotData['position'] = $data['tp_position'];
        $pivotData['team_year'] = $year;

        return [$playerData, $pivotData];
    }
}
