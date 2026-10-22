<section class="season-panel">
    <h2>Playoff qualification</h2>
    <p>{{ \App\Services\Seasons\SeasonOptions::PLAYOFFS[$season->settings['playoffs']] }}</p>
    @if($season->settings['playoffs'] === 'none')
        <p>This season has no postseason.</p>
    @else
        <p>{{ $playoffQualification['ready'] ? 'Regular-season results locked. Provisional seeds are shown below.' : 'Projected qualification based on completed regular-season games.' }}</p>
        <p class="season-notice">{{ $playoffQualification['tiebreaker_notice'] }}</p>
        @foreach($playoffQualification['conferences'] as $conference => $seeds)
            <h3>{{ $conference === 'league' ? 'League' : ($members[$seeds[0]['team_id']]['conference_name'] ?? $conference) }}</h3>
            <div class="season-table-scroll"><table class="season-table"><thead><tr><th>Seed</th><th>Team</th><th>W</th><th>L</th><th>T</th><th>Pct</th><th>Diff</th><th>Entry</th></tr></thead><tbody>
                @foreach($seeds as $team)
                    <tr><td>{{ $team['seed'] }}</td><td><a href="{{ route('seasons.team', [$season, $team['team_id']]) }}">{{ $team['name'] }}</a></td><td>{{ $team['wins'] }}</td><td>{{ $team['losses'] }}</td><td>{{ $team['ties'] }}</td><td>{{ number_format($team['pct'], 3) }}</td><td>{{ $team['pf'] - $team['pa'] }}</td><td>{{ $team['division_champion'] ? 'Division winner' : 'At large' }}</td></tr>
                @endforeach
            </tbody></table></div>
        @endforeach
        <p>Bracket creation, finalized tiebreakers, game play, and advancement are not enabled in this milestone.</p>
    @endif
</section>
