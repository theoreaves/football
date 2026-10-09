<x-layouts.app>
<main class="season-page">
<header class="season-header"><div><p class="season-eyebrow">{{ $season->year }} · Week {{ $season->current_week }}</p><h1>{{ $season->name }}</h1><p>{{ count($members) }} teams · {{ $season->settings['games'] }} games per team</p></div><a href="{{ route('seasons.index') }}">All seasons</a></header>
<nav class="season-tabs" aria-label="League sections">@foreach(['overview'=>'Overview','standings'=>'Standings','schedule'=>'Schedule / Results','leaders'=>'League Leaders','stats'=>'Team Stats','injuries'=>'Injuries','playoffs'=>'Playoffs','settings'=>'Settings'] as $key => $label)<a @if($tab === $key) aria-current="page" @endif href="{{ route('seasons.show', ['season' => $season, 'tab' => $key]) }}">{{ $label }}</a>@endforeach</nav>
@if(session('status'))<p class="season-notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p class="season-notice text-red-300" role="alert">{{ $errors->first() }}</p>@endif
@if($tab === 'overview')
    <p class="season-notice">Your schedule is saved. Playing/simming season games and advancing weeks will arrive in the next milestone. Exhibition games remain available separately.</p>
    <section class="season-panel"><h2>Week {{ $season->current_week }} matchups</h2>@foreach($fixtures->where('week', $season->current_week) as $game)<p>{{ $members[$game->away_team_id]['name'] }} at {{ $members[$game->home_team_id]['name'] }}</p>@endforeach</section>
    <div class="season-grid">@foreach(collect($members)->sortBy(fn($member) => $member['control'] === 'human' ? 0 : 1) as $id => $member)<a class="season-panel {{ $member['control'] === 'human' ? 'season-human-team' : '' }}" href="{{ route('seasons.team', [$season, $id]) }}">@if($member['control'] === 'human')<span class="season-human-label">Human controlled</span>@endif<div class="season-team-heading">@if(isset($logos[$id]))<img class="season-card-logo" src="{{ $logos[$id] }}" alt="{{ $member['name'] }} logo" loading="lazy">@endif<h2>{{ $member['name'] }}</h2></div><p>{{ $member['conference_name'] }} · {{ $member['division'] }}</p><span class="text-blue-300">{{ strtoupper($member['control']) }} · Open team hub →</span></a>@endforeach</div>
@elseif($tab === 'schedule')
    @include('seasons.fixtures')
    @if($season->settings['bye'])<section class="season-panel"><h2>Bye weeks</h2>@foreach($members as $id => $member)
        @php
            $weeks = $fixtures->filter(fn($game) => $game->home_team_id == $id || $game->away_team_id == $id)->pluck('week')->all();
            $bye = array_diff(range(1, $season->settings['games'] + 1), $weeks);
        @endphp
        <p>{{ $member['name'] }} · Week {{ implode(', ', $bye) }}</p>
    @endforeach</section>@endif
@elseif($tab === 'standings')
    <p class="season-notice">Opening standings. Records and tiebreakers will update when season game processing is connected.</p>
    @foreach(collect($members)->groupBy('conference_name', true) as $conference => $conferenceTeams)
    <section class="season-panel"><h2>{{ $conference }}</h2><div class="season-table-scroll"><table class="season-table"><thead><tr><th>Team</th><th>Division</th><th>W</th><th>L</th><th>T</th><th>Control</th></tr></thead><tbody>
    @foreach($conferenceTeams as $id => $member)
        <tr><td><a href="{{ route('seasons.team', [$season, $id]) }}">{{ $member['name'] }}</a></td><td>{{ $member['division'] }}</td><td>0</td><td>0</td><td>0</td><td>{{ strtoupper($member['control']) }}</td></tr>
    @endforeach</tbody></table></div></section>@endforeach
@elseif($tab === 'settings')
    <section class="season-panel"><h2>Season rules</h2><p>{{ $season->settings['games'] }} games · {{ $season->settings['bye'] ? 'One bye' : 'No byes' }} · {{ \App\Services\Seasons\SeasonOptions::PLAYOFFS[$season->settings['playoffs']] }}</p><p>The saved layout and schedule are fixed. Team control and quarter length can change at any time.</p><form method="POST" action="{{ route('seasons.rules', $season) }}" class="season-fields">@csrf @method('PUT')<label>Quarter length<select name="quarter_length">@foreach(\App\Services\Seasons\SeasonOptions::QUARTER_LENGTHS as $seconds => $minutes)<option value="{{ $seconds }}" @selected(old('quarter_length', $season->settings['quarter_length'] ?? 900) == $seconds)>{{ $minutes }} minutes</option>@endforeach</select></label><button class="landing-button">Save quarter length</button></form><p>Applies to season games that have not started. Existing seasons default to 15 minutes.</p></section>
    <section class="season-panel"><h2>Human / CPU control</h2><p>Check as many teams as you want. Unchecked teams use CPU control. Future game integration will apply changes to games that have not started.</p><form method="POST" action="{{ route('seasons.controls', $season) }}" class="space-y-3">@csrf @method('PUT')
    @foreach($members as $id => $member)<label class="block"><input type="checkbox" name="human[]" value="{{ $id }}" @checked($member['control'] === 'human')> {{ $member['name'] }} · Human</label>@endforeach
    <button class="landing-button">Save team control</button></form></section>
@elseif($tab === 'playoffs')
    <section class="season-panel"><h2>Playoff format</h2><p>{{ \App\Services\Seasons\SeasonOptions::PLAYOFFS[$season->settings['playoffs']] }}</p><p>Qualification, seeding, and the bracket will be connected after regular-season game processing.</p></section>
@else
    <section class="season-panel"><h2>{{ ['leaders'=>'League Leaders','stats'=>'Team Stats','injuries'=>'League Injuries'][$tab] }}</h2><p>{{ $tab === 'injuries' ? 'Persistent season injuries and weekly recovery are planned for the roster milestone.' : 'Season game statistics and sortable leaders will be connected in the stats milestone.' }}</p></section>
@endif
</main>
</x-layouts.app>
