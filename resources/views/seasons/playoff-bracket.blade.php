<section class="season-panel">
    <h2>Playoff bracket and round history</h2>
    <p>Opening seeds and byes remain fixed. Completed rounds remain visible as the tournament advances.</p>
    @php($activeRound = (int) ($playoffBracket['current_round'] ?? 1))
    @for($round = 1; $round <= $activeRound; $round++)
        @php($groups = $round === 1 ? $playoffBracket['conferences'] : ($playoffBracket['rounds'][$round] ?? []))
        @if(count($groups))
            <h3>{{ $round === 1 ? 'Opening round' : (isset($groups['championship']) ? 'League championship' : 'Round '.$round) }} @if($round === $activeRound && ($playoffBracket['status'] ?? '') !== 'completed') · Current @endif</h3>
            @foreach($groups as $conference => $group)
                @php($games = $round === 1 ? ($group['games'] ?? []) : $group)
                <h4>{{ $conference === 'championship' ? 'League championship' : ($conference === 'league' ? 'League' : ($members[$games[0]['home_team_id']]['conference_name'] ?? $conference)) }}</h4>
                @if($round === 1 && !empty($group['byes']))
                    <p><strong>Byes:</strong> @foreach($group['byes'] as $teamId) {{ $members[$teamId]['name'] ?? ('Team '.$teamId) }}@unless($loop->last), @endunless @endforeach</p>
                @endif
                <div class="season-table-scroll"><table class="season-table"><thead><tr><th>Away</th><th>Home</th><th>Result</th></tr></thead><tbody>
                    @foreach($games as $game)
                        @php($saved = !empty($game['exhibition_id']) ? \App\Models\Exhibition::find($game['exhibition_id']) : null)
                        @php($state = $saved?->state ?? [])
                        @php($final = ($state['status'] ?? null) === 'final' && !($state['penalty_pending'] ?? false))
                        <tr>
                            <td>#{{ $game['away_seed'] }} {{ $members[$game['away_team_id']]['name'] ?? $game['away_team_id'] }}</td>
                            <td>#{{ $game['home_seed'] }} {{ $members[$game['home_team_id']]['name'] ?? $game['home_team_id'] }}</td>
                            <td>@if($final)Final: {{ $state['away_score'] }}–{{ $state['home_score'] }}@elseif($saved)In progress@else{{ $round === $activeRound ? 'Scheduled' : 'Pending' }}@endif</td>
                        </tr>
                    @endforeach
                </tbody></table></div>
            @endforeach
        @endif
    @endfor
</section>

@include('seasons.playoff-games')
