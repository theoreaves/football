<x-layouts.app>
<main class="season-page">
<header class="season-header"><div><p class="season-eyebrow">Schedule preview · {{ $data['year'] }}</p><h1>{{ $data['name'] }}</h1><p>{{ count($members) }} teams · {{ $data['games'] }} games · {{ $data['bye'] ? 'One bye per team' : 'No byes' }}</p></div></header>
<p class="season-notice">Schedules use rotating opponents, prefer division/conference matchups, and balance home/away games within one. This is a flexible schedule generator, rather than a recreation of the NFL's opponent formula. With byes, one round is split across two weeks.</p>
<section class="season-panel"><h2>Team balance</h2><div class="season-table-scroll"><table class="season-table"><thead><tr><th>Team</th><th>Group</th><th>Control</th><th>Home / Away</th></tr></thead><tbody>
@foreach($members as $id => $member)<tr><td>{{ $member['name'] }}</td><td>{{ $member['conference_name'] }} · {{ $member['division'] }}</td><td>{{ strtoupper($member['control']) }}</td><td>{{ collect($fixtures)->where('home_team_id', $id)->count() }} / {{ collect($fixtures)->where('away_team_id', $id)->count() }}</td></tr>@endforeach
</tbody></table></div></section>
@foreach(collect($fixtures)->groupBy('week')->sortKeys() as $week => $games)<section class="season-panel"><h2>Week {{ $week }}</h2>@foreach($games as $game)<p>{{ $members[$game['away_team_id']]['name'] }} at {{ $members[$game['home_team_id']]['name'] }}</p>@endforeach
@php
    $playing = $games->pluck('home_team_id')->merge($games->pluck('away_team_id'))->all();
    $byes = array_diff(array_keys($members), $playing);
@endphp
@if($byes)<p class="text-blue-300">Bye: {{ implode(', ', array_map(fn($id) => $members[$id]['name'], $byes)) }}</p>@endif
</section>@endforeach
<form method="POST" action="{{ route('seasons.store') }}" class="season-actions">@csrf<input type="hidden" name="setup" value="{{ json_encode($data) }}"><button class="landing-button">Create season with this schedule</button><button type="button" onclick="history.back()" class="border rounded px-5 py-3">Back to setup</button></form>
</main>
</x-layouts.app>
