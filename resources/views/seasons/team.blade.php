<x-layouts.app>
<main class="season-page">
<header class="season-header"><div class="season-team-heading">@if($team->team_logo)<img class="season-hub-logo" src="{{ route('teams.art', ['team' => $team, 'asset' => 'team_logo']) }}" alt="{{ $member['name'] }} logo">@endif<div><p class="season-eyebrow">{{ $season->name }} · {{ $season->year }}</p><h1>{{ $member['name'] }}</h1><p>{{ $member['conference_name'] }} · {{ $member['division'] }} · {{ strtoupper($member['control']) }}</p></div></div><a href="{{ route('seasons.show', $season) }}">League hub</a></header>
<nav class="season-tabs" aria-label="Team sections">@foreach(['overview'=>'Overview','roster'=>'Roster','depth'=>'Depth Chart','schedule'=>'Schedule','stats'=>'Stats','injuries'=>'Injuries','settings'=>'Settings'] as $key => $label)<a @if($tab === $key) aria-current="page" @endif href="{{ route('seasons.team', ['season' => $season, 'team' => $team, 'tab' => $key]) }}">{{ $label }}</a>@endforeach</nav>
@if($tab === 'overview')
    <section class="season-panel"><h2>Season outlook</h2><p>Record: 0–0–0 · No season games played yet.</p><p>{{ $fixtures->count() }} scheduled games · {{ $member['control'] === 'human' ? 'You control this team' : 'CPU controlled' }}</p></section>
    <section class="season-panel"><h2>Next matchup</h2>@if($next = $fixtures->first())<p>Week {{ $next->week }} · {{ $season->settings['members'][$next->away_team_id]['name'] }} at {{ $season->settings['members'][$next->home_team_id]['name'] }}</p>@endif</section>
@elseif($tab === 'schedule')
    @include('seasons.fixtures')
    @if($season->settings['bye'])
        @php
            $byes = array_diff(range(1, $season->settings['games'] + 1), $fixtures->pluck('week')->all());
        @endphp
        <p class="season-notice">Bye: Week {{ implode(', ', $byes) }}</p>
    @endif
@elseif($tab === 'depth')
    @if(session('status'))<p class="season-notice" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="season-notice text-red-300" role="alert">{{ $errors->first() }}</p>@endif
    <section class="season-panel" data-season-roster><h2>Season depth chart</h2><p>Pick a position, then drag players into order. First is the starter. Use the up/down buttons on any device. Save when finished.</p><p>This order belongs to this season. It will be used when season games are connected; exhibition depth charts are unchanged.</p>
    @if($positions->isEmpty())<p>No roster recorded for {{ $season->year }}. Add players in the roster editor first.</p>@else
    <form method="GET" action="{{ route('seasons.team', [$season, $team]) }}" class="season-fields mt-4"><input type="hidden" name="tab" value="depth"><label>Position<select name="position" onchange="this.form.requestSubmit()">@foreach($positions as $value)<option value="{{ $value }}" @selected($position === $value)>{{ $value }}</option>@endforeach</select></label><button class="border rounded px-3 py-2">Show position</button></form>
    <form data-depth-chart method="POST" action="{{ route('seasons.depth', [$season, $team]) }}" class="mt-6">@csrf @method('PUT')<input type="hidden" name="position" value="{{ $position }}">
    <ol data-depth-list>@foreach($depthPlayers as $player)<li data-depth-player class="depth-player"><input type="hidden" name="players[]" value="{{ $player->id }}"><button type="button" class="depth-handle" data-depth-handle aria-label="Drag {{ $player->firstname }} {{ $player->lastname }}">⠿</button><span data-depth-rank>{{ $loop->iteration }}</span><div class="depth-player-name"><a data-roster-editor href="{{ route('teams.editor.teams.players.edit', [$team, $player, 'year' => $season->year, 'season' => $season->id, 'embedded' => 1]) }}">#{{ $player->pivot->jersey_number }} {{ $player->firstname }} {{ $player->lastname }}</a><small>{{ $position }}</small></div><button type="button" data-depth-up aria-label="Move {{ $player->lastname }} up">↑</button><button type="button" data-depth-down aria-label="Move {{ $player->lastname }} down">↓</button></li>@endforeach</ol>
    <p data-depth-status role="status" class="mt-3 text-blue-300"></p><button class="landing-button mt-4">Save depth chart</button></form>
    @endif<dialog data-roster-dialog class="roster-editor-dialog"><div class="roster-editor-bar"><h2>Player editor</h2><button type="button" data-roster-close>Close</button></div><iframe title="Player editor" data-roster-frame></iframe></dialog></section>
@elseif($tab === 'roster')
    <p class="season-notice">Current world roster for {{ $season->year }}. Season-specific depth charts, 53-player rosters, and practice squads will be added in the roster milestone.</p>
    <section class="season-panel" data-season-roster><div class="season-fields"><label>Search player name<input type="search" data-roster-search placeholder="First or last name"></label><label>Position<select data-roster-position><option value="">All positions</option>@foreach($players->pluck('pivot.position')->unique()->sort() as $position)<option value="{{ $position }}">{{ $position }}</option>@endforeach</select></label></div><p data-roster-count class="mt-3"></p><div class="season-table-scroll"><table class="season-table"><thead><tr><th>Number</th><th>Player</th><th>Position</th><th>Depth</th></tr></thead><tbody>
    @forelse($players as $player)<tr data-roster-player data-name="{{ $player->firstname }} {{ $player->lastname }}" data-position="{{ $player->pivot->position }}"><td>{{ $player->pivot->jersey_number }}</td><td><a data-roster-editor class="underline" href="{{ route('teams.editor.teams.players.edit', [$team, $player, 'year' => $season->year, 'season' => $season->id, 'embedded' => 1]) }}">{{ $player->firstname }} {{ $player->lastname }}</a></td><td>{{ $player->pivot->position }}</td><td>{{ $player->pivot->depth_chart_position }}</td></tr>@empty<tr><td colspan="4">No roster recorded for this year. Your existing players are available in the team editor.</td></tr>@endforelse
    </tbody></table></div><p data-roster-empty hidden>No players match your filters.</p><dialog data-roster-dialog class="roster-editor-dialog"><div class="roster-editor-bar"><h2>Player editor</h2><button type="button" data-roster-close>Close</button></div><iframe title="Player editor" data-roster-frame></iframe></dialog><a class="text-blue-300" href="{{ route('teams.editor.teams.players.index', $team) }}">Open world roster editor →</a></section>
@elseif($tab === 'settings')
    <section class="season-panel"><h2>Team settings</h2><p>Season control: {{ strtoupper($member['control']) }}</p><a class="landing-button" href="{{ route('seasons.show', ['season' => $season, 'tab' => 'settings']) }}">Edit team control</a><p class="mt-4"><a class="text-blue-300" href="{{ route('teams.editor.edit', $team) }}">Team colors, logos, and stadium →</a></p></section>
@else
    <section class="season-panel"><h2>{{ $tab === 'injuries' ? 'Team Injuries' : 'Team Stats' }}</h2><p>{{ $tab === 'injuries' ? 'Multi-week injuries and recovery will be connected in the roster milestone.' : 'Player and team season statistics will be connected after season game processing.' }}</p></section>
@endif
</main>
</x-layouts.app>
