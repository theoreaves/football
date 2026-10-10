<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Services\Simulation\PlayerRatings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamRosterController extends Controller
{
    public function index(Team $team, Request $request)
    {
        $year = (string) ($request->get('year') ?? $team->players()->max('team_players.team_year') ?? date('Y'));
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
        $year = (string) ($request->get('year') ?? $team->players()->max('team_players.team_year') ?? date('Y'));
        $player = new Player(['age' => 22, 'position' => 'QB']);
        $pivot = ['position' => 'QB', 'depth_chart_position' => 'QB1', 'jersey_number' => null];
        $ratings = app(PlayerRatings::class)->forPlayer($player);

        return view('teams.players.form', compact('team', 'year', 'player', 'pivot', 'ratings') + ['mode' => 'create']);
    }

    public function store(Team $team, Request $request)
    {
        $year = (string) ($request->get('year') ?? $team->players()->max('team_players.team_year') ?? date('Y'));
        [$data, $pivot] = $this->validated($request, $year);
        $player = DB::transaction(function () use ($data, $pivot, $team) {
            $player = Player::create($data);
            TeamPlayer::create($pivot + ['team_id' => $team->id, 'player_id' => $player->id]);

            return $player;
        });

        return redirect()->route('teams.editor.teams.players.edit', [$team, $player, 'year' => $year])->with('status', 'Player added to roster.');
    }

    public function edit(Team $team, Player $player, Request $request)
    {
        $season = $this->editorSeason($request, $team);
        $year = (string) ($request->get('year') ?? $team->players()->max('team_players.team_year') ?? date('Y'));
        $attached = $team->players()->where('players.id', $player->id)->wherePivot('team_year', $year)->firstOrFail();
        $pivot = $attached->pivot->toArray();
        $ratings = app(PlayerRatings::class)->forPlayer($player);

        return view('teams.players.form', compact('team', 'year', 'player', 'pivot', 'ratings', 'season') + ['mode' => 'edit']);
    }

    public function historyTab(Team $team, Player $player, Request $request, string $tab)
    {
        abort_unless(in_array($tab, ['statistics', 'injuries'], true), 404);
        $season = $this->editorSeason($request, $team);
        $year = (string) ($request->get('year') ?? $team->players()->max('team_players.team_year') ?? date('Y'));
        $team->players()->where('players.id', $player->id)->wherePivot('team_year', $year)->firstOrFail();

        if ($tab === 'statistics') {
            $statHistory = app(\App\Services\Seasons\SeasonStats::class)->playerHistory($player->id, $season);

            return view('teams.players.stats', compact('statHistory', 'season', 'year'));
        }

        $injuryHistory = app(\App\Services\Seasons\SeasonInjuries::class)->forPlayer($player->id, $season);

        return view('teams.players.injuries', compact('injuryHistory'));
    }

    public function update(Team $team, Player $player, Request $request)
    {
        $season = $this->editorSeason($request, $team);
        $year = (string) ($request->get('year') ?? $team->players()->max('team_players.team_year') ?? date('Y'));
        $row = TeamPlayer::where('team_id', $team->id)->where('player_id', $player->id)->where('team_year', $year)->firstOrFail();
        [$data, $pivot] = $this->validated($request, $year);
        DB::transaction(function () use ($data, $pivot, $player, $row) {
            $player->update($data);
            $row->update($pivot);
        });

        return redirect()->route('teams.editor.teams.players.edit', [$team, $player, 'year' => $year,
            'season' => $season?->id, 'embedded' => $request->boolean('embedded') ? 1 : null])->with('status', 'Player updated. New games use the updated player.')
            ->with('player_editor_saved', $request->boolean('embedded'));
    }

    private function editorSeason(Request $request, Team $team): ?\App\Models\Season
    {
        if (! $request->has('season')) {
            return null;
        }
        $request->validate(['season' => ['required', 'integer']]);
        $season = \App\Models\Season::findOrFail($request->integer('season'));
        abort_unless(isset($season->settings['members'][$team->id]), 404);

        return $season;
    }

    private function validated(Request $request, string $year): array
    {
        $rules = [
            'firstname' => ['required', 'string', 'max:255'], 'lastname' => ['required', 'string', 'max:255'],
            'age' => ['required', 'integer', 'between:18,99'], 'position' => ['required', 'string', 'max:10'],
            'height_inches' => ['nullable', 'integer', 'between:48,96'], 'weight_pounds' => ['nullable', 'integer', 'between:90,450'],
            'skin_tone' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'depth_chart_position' => ['required', 'string', 'max:10'], 'jersey_number' => ['nullable', 'integer', 'between:0,99'],
            'appearance' => ['sometimes', 'array:head_shape,eye_color,hair_color,hair,brow,nose,mouth,beard,throwing_hand'],
            'appearance.eye_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'appearance.hair_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'appearance.head_shape' => ['sometimes', \Illuminate\Validation\Rule::in(['round', 'oval', 'square', 'wide', 'long'])],
            'appearance.hair' => ['sometimes', \Illuminate\Validation\Rule::in(['bald', 'buzz', 'short', 'curly', 'long'])],
            'appearance.brow' => ['sometimes', \Illuminate\Validation\Rule::in(['straight', 'angled', 'thick', 'arched'])],
            'appearance.nose' => ['sometimes', \Illuminate\Validation\Rule::in(['standard', 'small', 'wide', 'long'])],
            'appearance.mouth' => ['sometimes', \Illuminate\Validation\Rule::in(['neutral', 'wide', 'thin', 'smile'])],
            'appearance.beard' => ['sometimes', \Illuminate\Validation\Rule::in(['none', 'stubble', 'moustache', 'goatee', 'full'])],
            'appearance.throwing_hand' => ['sometimes', \Illuminate\Validation\Rule::in(['right', 'left'])],
            'ratings' => ['required', 'array'],
        ];
        foreach (PlayerRatings::FIELDS as $field) {
            $rules['ratings.'.$field] = ['required', 'integer', 'between:1,99'];
        }
        $data = $request->validate($rules);
        $player = collect($data)->only(['firstname', 'lastname', 'age', 'position', 'height_inches', 'weight_pounds', 'skin_tone', 'appearance'])->all();
        $player['simulation_ratings'] = array_intersect_key($data['ratings'], array_flip(PlayerRatings::FIELDS));
        $pivot = ['team_year' => $year, 'position' => $data['position'], 'depth_chart_position' => $data['depth_chart_position'], 'jersey_number' => $data['jersey_number'] ?? null];

        return [$player, $pivot];
    }
}
