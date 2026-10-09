<section class="season-panel"><h2>Season statistics · {{ $season->year }}</h2><p>{{ $teamStats['totals']['games'] }} completed games · Record {{ $record['wins'] }}–{{ $record['losses'] }}–{{ $record['ties'] }}</p><p>Points scored: {{ $teamStats['totals']['points_for'] }} · Points allowed: {{ $teamStats['totals']['points_against'] }}</p>
<div class="season-table-scroll"><table class="season-table"><thead><tr><th>Statistic</th><th>{{ $member['name'] }}</th><th>Opponents</th><th>Team per game</th></tr></thead><tbody>
@foreach(['plays'=>'Offensive plays', 'yards'=>'Total offense', 'rushing_yards'=>'Rushing yards', 'passing_yards'=>'Net passing yards', 'first_downs'=>'First downs', 'turnovers'=>'Turnovers', 'penalties'=>'Penalties', 'penalty_yards'=>'Penalty yards', 'possession_seconds'=>'Time of possession'] as $key => $label)
@php
    $value = $teamStats['totals'][$key] ?? 0;
    $against = $teamStats['opponents'][$key] ?? 0;
    $average = $teamStats['totals']['games'] ? $value / $teamStats['totals']['games'] : 0;
@endphp
<tr><th>{{ $label }}</th><td>{{ $key === 'possession_seconds' ? sprintf('%d:%02d', intdiv($value, 60), $value % 60) : $value }}</td><td>{{ $key === 'possession_seconds' ? sprintf('%d:%02d', intdiv($against, 60), $against % 60) : $against }}</td><td>{{ $key === 'possession_seconds' ? sprintf('%d:%02d', intdiv((int) $average, 60), (int) $average % 60) : number_format($average, 1) }}</td></tr>
@endforeach
</tbody></table></div></section>
@include('seasons.stat-tables', ['statPlayers' => $teamStats['players']])
<p class="season-notice">Totals include completed season games only. Pressures and forced fumbles are not yet tracked by the engine.</p>
