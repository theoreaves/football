<?php

namespace App\Http\Controllers;

use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use App\Services\Seasons\ScheduleGenerator;
use App\Services\Seasons\SeasonOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SeasonController extends Controller
{
    public function index()
    {
        return view('seasons.index', ['seasons' => Season::whereNotNull('settings')->with('league')->orderByDesc('year')->get()]);
    }

    public function create()
    {
        return view('seasons.create', ['teams' => Team::orderBy('conference')->orderBy('division')->orderBy('city')->get(),
            'leagues' => League::all(), 'sizes' => SeasonOptions::SIZES, 'playoffs' => SeasonOptions::PLAYOFFS]);
    }

    private function setup(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'year' => ['required', 'integer', 'between:1900,2200'],
            'league_id' => ['required', 'integer'], 'team_count' => ['required', 'integer', Rule::in(SeasonOptions::SIZES)],
            'quarter_length' => ['sometimes', 'integer', Rule::in(array_keys(SeasonOptions::QUARTER_LENGTHS))],
            'games' => ['required', 'integer'], 'bye' => ['required', 'boolean'],
            'layout' => ['required', Rule::in(['flat', 'conferences', 'divisions'])],
            'playoffs' => ['required', Rule::in(array_keys(SeasonOptions::PLAYOFFS))],
            'teams' => ['required', 'array'], 'teams.*' => ['integer', 'distinct'],
            'human' => ['sometimes', 'array'], 'human.*' => ['integer', 'distinct'],
            'groups' => ['sometimes', 'array'], 'groups.*' => ['string', 'max:20'],
            'conference_names' => ['sometimes', 'array'], 'conference_names.*' => ['string', 'max:40'],
            'division_names' => ['sometimes', 'array'], 'division_names.*' => ['string', 'max:40'],
        ]);
        League::findOrFail($data['league_id']);
        $fail = fn ($field, $message) => throw ValidationException::withMessages([$field => $message]);
        if (! in_array((int) $data['games'], SeasonOptions::lengths((int) $data['team_count']), true)) {
            $fail('games', 'Choose a season length supported by this team count.');
        }
        if (count($data['teams']) !== (int) $data['team_count']) {
            $fail('teams', 'Select exactly '.$data['team_count'].' teams.');
        }
        $teams = Team::whereIn('id', $data['teams'])->get()->keyBy('id');
        if ($teams->count() !== count($data['teams'])) {
            $fail('teams', 'Every team must belong to this world.');
        }
        if (array_diff($data['human'] ?? [], $data['teams'])) {
            $fail('human', 'Human-controlled teams must participate in this season.');
        }
        if (! SeasonOptions::compatible((int) $data['team_count'], $data['layout'], $data['playoffs'])) {
            $fail('playoffs', 'That playoff format does not fit this league layout.');
        }
        $definitions = SeasonOptions::groups((int) $data['team_count'], $data['layout']);
        $defaults = [];
        foreach ($definitions as $key => $definition) {
            $defaults = array_merge($defaults, array_fill(0, $definition['size'], $key));
        }
        $members = [];
        foreach (array_values($data['teams']) as $i => $id) {
            $key = $data['groups'][$id] ?? $defaults[$i];
            if (! isset($definitions[$key])) {
                $fail('groups', 'Choose a valid conference/division for every team.');
            }
            $definition = $definitions[$key];
            $conferenceKey = $data['layout'] === 'flat' ? 'league' : substr($key, 0, 1);
            $members[(int) $id] = ['name' => $teams[$id]->city.' '.$teams[$id]->name, 'group' => $key,
                'conference' => $conferenceKey, 'conference_name' => $data['conference_names'][$conferenceKey] ?? $definition['conference'],
                'division' => $data['division_names'][$key] ?? $definition['division'],
                'control' => in_array($id, $data['human'] ?? []) ? 'human' : 'cpu'];
        }
        foreach ($definitions as $key => $definition) {
            if (count(array_filter($members, fn ($member) => $member['group'] === $key)) !== $definition['size']) {
                $fail('groups', 'Each conference/division must have the number of teams shown in setup.');
            }
        }
        if (Season::where('league_id', $data['league_id'])->where('year', $data['year'])->whereNotNull('settings')->exists()) {
            $fail('year', 'This league already has a configured season for that year.');
        }

        return [$data, $members];
    }

    public function preview(Request $request, ScheduleGenerator $generator)
    {
        [$data, $members] = $this->setup($request);

        return view('seasons.preview', ['data' => $data, 'members' => $members,
            'fixtures' => $generator->generate($members, (int) $data['games'], (bool) $data['bye'])]);
    }

    public function store(Request $request, ScheduleGenerator $generator)
    {
        $request->validate(['setup' => ['required', 'json', 'max:30000']]);
        $payload = json_decode($request->input('setup'), true);
        abort_unless(is_array($payload), 422);
        $request->merge($payload);
        [$data, $members] = $this->setup($request);
        $season = DB::transaction(function () use ($data, $members, $generator) {
            League::whereKey($data['league_id'])->lockForUpdate()->firstOrFail();
            $season = Season::firstOrNew(['league_id' => $data['league_id'], 'year' => $data['year']]);
            if ($season->settings !== null) {
                throw ValidationException::withMessages(['year' => 'This season was already created.']);
            }
            $season->fill(['name' => $data['name'], 'phase' => 'regular_season', 'current_week' => 1,
                'settings' => ['quarter_length' => (int) ($data['quarter_length'] ?? 900), 'games' => (int) $data['games'], 'bye' => (bool) $data['bye'], 'layout' => $data['layout'], 'playoffs' => $data['playoffs'], 'members' => $members]])->save();
            foreach ($generator->generate($members, (int) $data['games'], (bool) $data['bye']) as $fixture) {
                $season->fixtures()->create($fixture);
            }

            return $season;
        });

        return redirect()->route('seasons.show', $season);
    }

    public function show(Request $request, Season $season)
    {
        abort_unless($season->settings, 404);
        $tab = $request->query('tab', 'overview');
        abort_unless(in_array($tab, ['overview', 'standings', 'schedule', 'leaders', 'stats', 'injuries', 'playoffs', 'settings'], true), 404);

        return view('seasons.show', ['standings' => app(\App\Services\Seasons\SeasonGames::class)->standings($season), 'season' => $season, 'tab' => $tab, 'members' => $season->settings['members'],
            'logos' => Team::whereIn('id', array_keys($season->settings['members']))->whereNotNull('team_logo')->get(['id', 'team_logo'])
                ->filter(fn ($team) => (bool) $team->team_logo)->mapWithKeys(fn ($team) => [$team->id => route('teams.art', ['team' => $team, 'asset' => 'team_logo'])]),
            'fixtures' => $season->fixtures()->orderBy('week')->orderBy('id')->get()]);
    }

    public function game(Request $request, Season $season, \App\Models\SeasonFixture $fixture)
    {
        abort_unless((int) $fixture->season_id === (int) $season->id, 404);
        $data = $request->validate(['quick_sim' => ['sometimes', 'boolean']]);
        $game = app(\App\Services\Seasons\SeasonGames::class)->start($season, $fixture, (bool) ($data['quick_sim'] ?? false));

        return redirect()->route('exhibitions.show', ['exhibition' => $game, 'summary' => $game->state['status'] === 'final' ? 1 : 0]);
    }

    public function advance(Request $request, Season $season)
    {
        $data = $request->validate(['week' => ['required', 'integer', 'min:1']]);
        app(\App\Services\Seasons\SeasonGames::class)->advance($season, (int) $data['week']);

        return redirect()->route('seasons.show', $season)->with('status', 'Week completed.');
    }

    public function rules(Request $request, Season $season)
    {
        $data = $request->validate(['quarter_length' => ['required', 'integer', Rule::in(array_keys(SeasonOptions::QUARTER_LENGTHS))]]);
        DB::transaction(function () use ($season, $data) {
            $locked = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->settings, 404);
            $settings = $locked->settings;
            $settings['quarter_length'] = (int) $data['quarter_length'];
            $locked->update(['settings' => $settings]);
        });

        return redirect()->route('seasons.show', ['season' => $season, 'tab' => 'settings'])->with('status', 'Quarter length updated. New season games use this setting.');
    }

    public function controls(Request $request, Season $season)
    {
        $data = $request->validate(['human' => ['sometimes', 'array'], 'human.*' => ['integer', 'distinct']]);
        DB::transaction(function () use ($season, $data) {
            $locked = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->settings, 404);
            $settings = $locked->settings;
            if (array_diff($data['human'] ?? [], array_keys($settings['members']))) {
                throw ValidationException::withMessages(['human' => 'Only season teams can be selected.']);
            }
            foreach ($settings['members'] as $id => &$member) {
                $member['control'] = in_array($id, $data['human'] ?? []) ? 'human' : 'cpu';
            }
            unset($member);
            $locked->update(['settings' => $settings]);
        });

        return redirect()->route('seasons.show', ['season' => $season, 'tab' => 'settings'])->with('status', 'Team control updated.');
    }

    public function team(Request $request, Season $season, Team $team)
    {
        abort_unless(isset($season->settings['members'][$team->id]), 404);
        $tab = $request->query('tab', 'overview');
        abort_unless(in_array($tab, ['overview', 'roster', 'depth', 'schedule', 'stats', 'injuries', 'settings'], true), 404);
        $players = $team->players()->wherePivot('team_year', (string) $season->year)->orderBy('team_players.depth_chart_position')->get();
        $positions = $players->pluck('pivot.position')->unique()->sort()->values();
        $request->validate(['position' => ['sometimes', 'string', 'max:10']]);
        $position = $request->query('position', $positions->first());
        if ($position !== null) {
            abort_unless($positions->contains($position), 422, 'Choose a position on this roster.');
        }
        $savedDepth = $season->settings['depth_charts'][$team->id][$position] ?? [];
        $depthPlayers = $players->filter(fn ($player) => $player->pivot->position === $position)->sortBy(function ($player) use ($savedDepth) {
            $index = array_search($player->id, $savedDepth);

            return [$index === false ? 1 : 0, $index === false ? (int) preg_replace('/\D/', '', $player->pivot->depth_chart_position) : $index, $player->id];
        });

        return view('seasons.team', ['season' => $season, 'team' => $team, 'tab' => $tab,
            'member' => $season->settings['members'][$team->id],
            'players' => $players, 'positions' => $positions, 'position' => $position, 'depthPlayers' => $depthPlayers,
            'fixtures' => $season->fixtures()->where(fn ($q) => $q->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))->orderBy('week')->get()]);
    }

    public function depth(Request $request, Season $season, Team $team)
    {
        abort_unless(isset($season->settings['members'][$team->id]), 404);
        $data = $request->validate(['position' => ['required', 'string', 'max:10'], 'players' => ['required', 'array', 'min:1'], 'players.*' => ['required', 'integer', 'distinct']]);
        DB::transaction(function () use ($season, $team, $data) {
            $locked = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            $eligible = $team->players()->wherePivot('team_year', (string) $locked->year)->wherePivot('position', $data['position'])->pluck('players.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $ordered = array_map('intval', $data['players']);
            $submitted = $ordered;
            sort($submitted);
            if ($eligible !== $submitted) {
                throw ValidationException::withMessages(['players' => 'The roster changed or contains invalid players. Reload and try again.']);
            }
            $settings = $locked->settings;
            $settings['depth_charts'][$team->id][$data['position']] = $ordered;
            $locked->update(['settings' => $settings]);
        });

        return redirect()->route('seasons.team', ['season' => $season, 'team' => $team, 'tab' => 'depth', 'position' => $data['position']])->with('status', 'Season depth chart saved.');
    }
}
