<section class="season-panel"><h2>Team Injuries</h2><p>Injuries recorded during this season’s games. Active season injuries include their recovery timeline and estimated return week.</p>
@if(empty($injuryEvents))<p class="mt-4">No injuries recorded for this team this season.</p>@else
<div class="season-table-scroll"><table class="season-table"><thead><tr><th>Week</th><th>Player</th><th>Position</th><th>Injury</th><th>Severity</th><th>Return</th><th>Status</th></tr></thead><tbody>
@foreach($injuryEvents as $injury)<tr><td>{{ $injury['week'] }}</td><td>#{{ $injury['number'] ?? '—' }} {{ $injury['name'] }}</td><td>{{ $injury['position'] }}</td><td>{{ $injury['type'] }}</td><td>{{ ucfirst($injury['severity'] ?? 'minor') }}</td><td>{{ isset($injury['return_week']) ? 'Week '.$injury['return_week'] : (isset($injury['severity']) ? 'Season-ending' : '—') }}</td><td>{{ $injury['status'] }}</td></tr>@endforeach
</tbody></table></div>@endif</section>
