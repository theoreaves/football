<section class="season-panel">
    <h2>Opening playoff bracket</h2>
    <p>This opening-round bracket is saved to the season and will not change if team settings are edited. Game play and later-round advancement are not enabled yet.</p>
    @foreach($playoffBracket['conferences'] as $conference => $bracket)
        <h3>{{ $conference === 'league' ? 'League' : ($members[$bracket['seeds'][0]['team_id']]['conference_name'] ?? $conference) }}</h3>
        @if($bracket['byes'])
            <p><strong>First-round byes:</strong>
                @foreach($bracket['byes'] as $teamId)
                    {{ $members[$teamId]['name'] ?? ('Team '.$teamId) }}@unless($loop->last), @endunless
                @endforeach
            </p>
        @endif
        <div class="season-table-scroll"><table class="season-table"><thead><tr><th>Away</th><th>Home</th><th>Status</th></tr></thead><tbody>
            @foreach($bracket['games'] as $game)
                <tr><td>#{{ $game['away_seed'] }} {{ $members[$game['away_team_id']]['name'] ?? $game['away_team_id'] }}</td>
                    <td>#{{ $game['home_seed'] }} {{ $members[$game['home_team_id']]['name'] ?? $game['home_team_id'] }}</td><td>Scheduled · Not playable yet</td></tr>
            @endforeach
        </tbody></table></div>
    @endforeach
</section>
