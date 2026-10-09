<h2 class="text-xl font-semibold mt-4">Player statistics</h2>
<p class="my-3">Completed season games only. Exhibition games are excluded.</p>
@foreach(['Current season', 'Previous seasons'] as $section)
@php
    $rows = collect($statHistory)->filter(fn($row) => $section === 'Current season' ? ($season ? $row['season']->id === $season->id : (string) $row['season']->year === $year) : ($season ? $row['season']->year < $season->year : $row['season']->year < (int) $year));
@endphp
<h3 class="text-lg font-semibold mt-5">{{ $section }}</h3>
@forelse($rows as $row)
<h4 class="font-semibold mt-4">{{ $row['season']->year }} · {{ $row['season']->name }} · {{ $row['team'] }}</h4>
@include('seasons.stat-tables', ['statPlayers' => [$row['stats']]])
@empty<p class="my-3">No completed-game statistics for {{ strtolower($section) }}.</p>@endforelse
@endforeach
