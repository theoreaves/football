@php($publishedWeek = app(\App\Services\Seasons\LeagueLeaders::class)->publishedThroughWeek($season))
@if($publishedWeek !== null)
    <p class="season-notice">Stats through Week {{ $publishedWeek }}.</p>
@else
    <p class="season-notice">Statistics snapshot not yet dated. Week labels will appear after the next statistics rebuild.</p>
@endif
<p class="season-notice">Top 10 players in each category from completed regular-season games. Exhibitions and games still in progress are excluded.</p>
@unless(app(\App\Services\Seasons\LeagueLeaders::class)->isReady($season))
    <p class="season-notice">Season leaders are being prepared. Rankings are published when a completed week advances.</p>
@endunless
@foreach($leaders as $title => $leaderboard)
<section class="season-panel">
    <h2>{{ $title }}</h2>
    @if(empty($leaderboard['players']))
        <p>No qualifying statistics recorded yet.</p>
    @else
        <div class="season-table-scroll">
            <table class="season-table">
                <thead><tr><th>Rank</th><th>Player</th><th>Team</th><th>GP</th>
                    @foreach($leaderboard['columns'] as $column)
                        <th>{{ ucwords(str_replace('_', ' ', $column)) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @foreach($leaderboard['players'] as $person)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <th>#{{ $person['number'] }} {{ $person['name'] }}</th>
                        <td><a href="{{ route('seasons.team', ['season' => $season, 'team' => $person['team_id'], 'tab' => 'stats']) }}">{{ $person['team_name'] }}</a></td>
                        <td>{{ $person['games'] ?? 0 }}</td>
                        @foreach($leaderboard['columns'] as $column)
                            <td>{{ number_format($person[$column] ?? 0) }}</td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endforeach
