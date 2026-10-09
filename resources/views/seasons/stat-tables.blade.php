@foreach(\App\Services\Seasons\SeasonStats::CATEGORIES as $category => $columns)
@php
    $rows = collect($statPlayers)->filter(fn($person) => collect($columns)->contains(fn($key) => ($person[$key] ?? 0) != 0));
@endphp
@if($rows->isNotEmpty())
<section class="season-panel"><h3>{{ $category }}</h3><div class="season-table-scroll"><table class="season-table"><thead><tr><th>Player</th>@foreach($columns as $column)<th>{{ ucwords(str_replace('_', ' ', $column)) }}</th>@endforeach @if($category === 'Passing')<th>QB rating</th>@endif</tr></thead><tbody>
@foreach($rows as $person)<tr><th>#{{ $person['number'] }} {{ $person['name'] }}</th>@foreach($columns as $column)<td>{{ $person[$column] ?? 0 }}</td>@endforeach @if($category === 'Passing')<td>{{ $person['passer_rating'] ?? '—' }}</td>@endif</tr>@endforeach
</tbody></table></div></section>
@endif
@endforeach
@if(empty($statPlayers))<p class="season-notice">No player statistics recorded yet.</p>@endif
