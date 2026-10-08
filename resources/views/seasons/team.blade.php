<x-layouts.app>
<main class="season-page">
<header class="season-header"><div><p class="season-eyebrow">{{ $season->name }} · {{ $season->year }}</p><h1>{{ $member['name'] }}</h1><p>{{ $member['conference_name'] }} · {{ $member['division'] }} · {{ strtoupper($member['control']) }}</p></div><a href="{{ route('seasons.show', $season) }}">League hub</a></header>
<nav class="season-tabs" aria-label="Team sections">@foreach(['overview'=>'Overview','roster'=>'Roster / Depth chart','schedule'=>'Schedule','stats'=>'Stats','injuries'=>'Injuries','settings'=>'Settings'] as $key => $label)<a @if($tab === $key) aria-current="page" @endif href="{{ route('seasons.team', ['season' => $season, 'team' => $team, 'tab' => $key]) }}">{{ $label }}</a>@endforeach</nav>
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
@elseif($tab === 'roster')
    <p class="season-notice">Current world roster for {{ $season->year }}. Season-specific depth charts, 53-player rosters, and practice squads will be added in the roster milestone.</p>
    <section class="season-panel"><div class="season-table-scroll"><table class="season-table"><thead><tr><th>Number</th><th>Player</th><th>Position</th><th>Depth</th></tr></thead><tbody>
    @forelse($players as $player)<tr><td>{{ $player->pivot->jersey_number }}</td><td>{{ $player->firstname }} {{ $player->lastname }}</td><td>{{ $player->pivot->position }}</td><td>{{ $player->pivot->depth_chart_position }}</td></tr>@empty<tr><td colspan="4">No roster recorded for this year. Your existing players are available in the team editor.</td></tr>@endforelse
    </tbody></table></div><a class="text-blue-300" href="{{ route('teams.editor.teams.players.index', $team) }}">Open world roster editor →</a></section>
@elseif($tab === 'settings')
    <section class="season-panel"><h2>Team settings</h2><p>Season control: {{ strtoupper($member['control']) }}</p><a class="landing-button" href="{{ route('seasons.show', ['season' => $season, 'tab' => 'settings']) }}">Edit team control</a><p class="mt-4"><a class="text-blue-300" href="{{ route('teams.editor.edit', $team) }}">Team colors, logos, and stadium →</a></p></section>
@else
    <section class="season-panel"><h2>{{ $tab === 'injuries' ? 'Team Injuries' : 'Team Stats' }}</h2><p>{{ $tab === 'injuries' ? 'Multi-week injuries and recovery will be connected in the roster milestone.' : 'Player and team season statistics will be connected after season game processing.' }}</p></section>
@endif
</main>
</x-layouts.app>
