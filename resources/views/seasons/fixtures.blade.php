@foreach($fixtures->groupBy('week')->sortKeys() as $week => $games)
<section class="season-panel"><h2>Week {{ $week }}</h2><div class="season-table-scroll"><table class="season-table"><thead><tr><th>Away</th><th>Home</th><th>Status / Score</th><th>Game</th></tr></thead><tbody>
@foreach($games as $game)
<tr><td><a href="{{ route('seasons.team', [$season, $game->away_team_id]) }}">{{ $season->settings['members'][$game->away_team_id]['name'] }}</a></td><td><a href="{{ route('seasons.team', [$season, $game->home_team_id]) }}">{{ $season->settings['members'][$game->home_team_id]['name'] }}</a></td>
<td>{{ ucfirst($game->status) }}@if($game->status === 'final') · {{ $game->away_score }}–{{ $game->home_score }}@endif</td>
<td>@if($game->exhibition_id)<a href="{{ route('exhibitions.show', ['exhibition' => $game->exhibition_id, 'highlights' => $game->status === 'final' ? 1 : 0]) }}"> {{ $game->status === 'final' ? 'Highlights / Replays' : 'Resume game' }}</a> <a data-season-box href="{{ route('seasons.box-score', [$season, $game]) }}">Box score</a>
@elseif($season->phase === 'regular_season' && (int) $week === (int) $season->current_week)<form method="POST" action="{{ route('seasons.game', [$season, $game]) }}" class="season-game-actions">@csrf<input type="hidden" name="return_tab" value="{{ request('tab') === 'schedule' ? 'schedule' : 'overview' }}"><button class="landing-button" name="quick_sim" value="0">Play game</button><button class="landing-button" name="quick_sim" value="1">Quick Sim</button></form>
@else<span>Upcoming</span>@endif</td></tr>
@endforeach
</tbody></table></div></section>
@endforeach
