@foreach($fixtures->groupBy('week')->sortKeys() as $week => $games)
<section class="season-panel"><h2>Week {{ $week }}</h2><div class="season-table-scroll"><table class="season-table"><thead><tr><th>Away</th><th>Home</th><th>Status</th></tr></thead><tbody>
@foreach($games as $game)<tr><td><a href="{{ route('seasons.team', [$season, $game->away_team_id]) }}">{{ $season->settings['members'][$game->away_team_id]['name'] }}</a></td><td><a href="{{ route('seasons.team', [$season, $game->home_team_id]) }}">{{ $season->settings['members'][$game->home_team_id]['name'] }}</a></td><td>Scheduled</td></tr>@endforeach
</tbody></table></div></section>
@endforeach
