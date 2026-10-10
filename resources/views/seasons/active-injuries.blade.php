<section class="season-panel">
    <h2>{{ $showTeam ? 'League Active Injuries' : 'Team Active Injuries' }}</h2>
    <p>Players currently unavailable for season games. Recovered players are not shown.</p>
    @if(empty($activeInjuries))
        <p class="mt-4">No active injuries.</p>
    @else
        <div class="season-table-scroll"><table class="season-table"><thead><tr>
            @if($showTeam)<th>Team</th>@endif
            <th>Player</th><th>Position</th><th>Injured</th><th>Injury</th><th>Severity</th><th>Expected return</th>
        </tr></thead><tbody>
            @foreach($activeInjuries as $injury)
                <tr>
                    @if($showTeam)<td><a href="{{ route('seasons.team', ['season' => $season, 'team' => $injury['team_id'], 'tab' => 'injuries']) }}">{{ $injury['team'] }}</a></td>@endif
                    <td>{{ $injury['number'] !== null ? '#'.$injury['number'].' ' : '' }}{{ $injury['player'] }}</td>
                    <td>{{ $injury['position'] }}</td>
                    <td>Week {{ $injury['injured_week'] }}</td>
                    <td>{{ $injury['type'] }}</td>
                    <td>{{ ucfirst($injury['severity']) }}</td>
                    <td>{{ $injury['return_week'] === null ? 'Season-ending' : 'Week '.$injury['return_week'] }}</td>
                </tr>
            @endforeach
        </tbody></table></div>
    @endif
</section>
