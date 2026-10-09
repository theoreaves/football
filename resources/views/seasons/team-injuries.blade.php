<section class="season-panel"><h2>Team Injuries</h2><p>Injuries recorded during this season’s games. Recovery is currently tracked within individual games, not across weeks.</p>
@if(empty($injuryEvents))<p class="mt-4">No injuries recorded for this team this season.</p>@else
<div class="season-table-scroll"><table class="season-table"><thead><tr><th>Week</th><th>Player</th><th>Position</th><th>Injury</th><th>Game status</th></tr></thead><tbody>
@foreach($injuryEvents as $injury)<tr><td>{{ $injury['week'] }}</td><td>#{{ $injury['number'] ?? '—' }} {{ $injury['name'] }}</td><td>{{ $injury['position'] }}</td><td>{{ $injury['type'] }}</td><td>{{ $injury['status'] }}</td></tr>@endforeach
</tbody></table></div>@endif</section>
