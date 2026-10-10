<div class="space-y-5">
<h2 class="text-2xl font-semibold">Box score{{ $state['status'] === 'final' ? ' · Final' : '' }}</h2>
<table class="w-full text-left"><thead><tr><th>Team</th>@foreach(array_keys($boxScore['quarters']['home']) as $q)<th>{{ $q >= 5 ? 'OT'.($q > 5 ? $q - 4 : '') : 'Q'.$q }}</th>@endforeach<th>Total</th></tr></thead><tbody>@foreach(['away','home'] as $side)<tr><th>{{ $teamNames[$side] }}</th>@foreach($boxScore['quarters'][$side] as $points)<td>{{ $points }}</td>@endforeach<td class="font-bold">{{ $state[$side.'_score'] }}</td></tr>@endforeach</tbody></table>
<h3 class="text-lg font-semibold">Team statistics</h3>
<table class="w-full text-left"><thead><tr><th>Statistic</th><th>{{ $teamNames['away'] }}</th><th>{{ $teamNames['home'] }}</th></tr></thead><tbody>
@foreach(['plays'=>'Offensive plays','yards'=>'Total offense','rushing_yards'=>'Rushing yards','passing_yards'=>'Net passing yards','first_downs'=>'First downs','turnovers'=>'Turnovers','penalties'=>'Penalties','penalty_yards'=>'Penalty yards','possession_seconds'=>'Time of possession'] as $key=>$label)
<tr><th>{{ $label }}</th>@foreach(['away','home'] as $side)<td>{{ $key === 'possession_seconds' ? gmdate('i:s', $boxScore['teams'][$side][$key]) : $boxScore['teams'][$side][$key] }}</td>@endforeach</tr>
@endforeach</tbody></table>
@foreach(['away','home'] as $side)
<h3 class="text-lg font-semibold">{{ $teamNames[$side] }} players</h3>
@foreach(['Passing'=>['pass_attempts','completions','passing_yards','passing_td','interceptions','sacks','fumbles_lost'], 'Rushing'=>['rushes','rushing_yards','rushing_td','fumbles_lost'], 'Receiving'=>['targets','receptions','receiving_yards','receiving_td','fumbles_lost'], 'Kicking'=>['fg_made','fg_attempts','xp_made','xp_attempts'], 'Punting'=>['punts','punt_yards'], 'Returns'=>['returns','return_yards','return_td'], 'Defense'=>['tackles','defensive_sacks','defensive_interceptions','fumble_recoveries']] as $category=>$columns)
@php($rows = array_filter($boxScore['players'][$side], fn($p) => count(array_filter(array_intersect_key($p, array_flip($columns)))) > 0))
@if($rows)<h4 class="font-semibold">{{ $category }}</h4><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Player</th>@foreach($columns as $col)<th>{{ ucwords(str_replace('_',' ',$col)) }}</th>@endforeach</tr></thead><tbody>@foreach($rows as $p)<tr><th>#{{ $p['number'] }} {{ $p['name'] }}</th>@foreach($columns as $col)<td>{{ $p[$col] }}</td>@endforeach</tr>@endforeach</tbody></table></div>@endif
@endforeach
@endforeach
<h3 class="text-lg font-semibold">Scoring summary</h3>
@forelse($boxScore['scoring'] as $score)<p class="text-sm">{{ $score['quarter'] >= 5 ? 'OT'.($score['quarter'] > 5 ? $score['quarter'] - 4 : '') : 'Q'.$score['quarter'] }} {{ gmdate('i:s',$score['clock']) }} · {{ $score['summary'] }} · {{ $score['away_score'] }}–{{ $score['home_score'] }}</p>@empty<p>No scoring plays.</p>@endforelse
</div>
