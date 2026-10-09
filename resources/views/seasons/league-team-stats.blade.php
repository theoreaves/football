<section class="season-panel">
    <h2>League team statistics</h2>
    @php($publishedWeek = app(\App\Services\Seasons\LeagueLeaders::class)->publishedThroughWeek($season))
    @if($publishedWeek !== null)
        <p class="season-notice">Stats through Week {{ $publishedWeek }}.</p>
    @else
        <p class="season-notice">Statistics snapshot not yet dated. Week labels will appear after the next statistics rebuild.</p>
    @endif
    <p>Official season totals through the last advanced week. Rankings refresh after you advance a completed week.</p>
    @if(empty($leagueTeamStats))
        <p class="season-notice">No published team statistics yet. Finish and advance the first week to generate them.</p>
    @else
        <div class="season-fields mt-4"><label>View
            <select id="league-stat-mode"><option value="per-game">Per game</option><option value="totals">Totals</option></select>
        </label></div>
        <div class="season-table-scroll"><table class="season-table" id="league-team-stats-table">
            <thead><tr><th>Team</th><th>GP</th><th>PF</th><th>PA</th><th>DIFF</th><th>YDS</th><th>PASS</th><th>RUSH</th><th>YDS ALLOWED</th><th>1ST</th><th>TO</th><th>TO DIFF</th><th>PEN</th><th>PEN YDS</th></tr></thead>
            <tbody>
            @foreach($leagueTeamStats as $teamId => $entry)
                @php
                    $t = $entry['totals']; $o = $entry['opponents']; $g = max(1, $t['games'] ?? 0);
                    $metrics = [
                        $t['points_for'] ?? 0, $t['points_against'] ?? 0,
                        ($t['points_for'] ?? 0) - ($t['points_against'] ?? 0),
                        $t['yards'] ?? 0, $t['passing_yards'] ?? 0, $t['rushing_yards'] ?? 0,
                        $o['yards'] ?? 0, $t['first_downs'] ?? 0, $t['turnovers'] ?? 0,
                        ($o['turnovers'] ?? 0) - ($t['turnovers'] ?? 0),
                        $t['penalties'] ?? 0, $t['penalty_yards'] ?? 0
                    ];
                @endphp
                <tr><th><a href="{{ route('seasons.team', ['season' => $season, 'team' => $teamId, 'tab' => 'stats']) }}">{{ $entry['name'] }}</a></th><td>{{ $t['games'] ?? 0 }}</td>
                @foreach($metrics as $value)
                    <td data-total="{{ $value }}" data-games="{{ $g }}">{{ number_format($value / $g, 1) }}</td>
                @endforeach
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
</section>
@if(!empty($leagueTeamStats))
<script>
(() => {
    const mode = document.getElementById('league-stat-mode');
    const table = document.getElementById('league-team-stats-table');
    if (!mode || !table) return;
    mode.addEventListener('change', () => {
        table.querySelectorAll('td[data-total]').forEach((cell) => {
            const value = Number(cell.dataset.total);
            const games = Math.max(1, Number(cell.dataset.games));
            cell.textContent = (mode.value === 'totals' ? value : value / games).toLocaleString(undefined, {
                minimumFractionDigits: mode.value === 'totals' ? 0 : 1,
                maximumFractionDigits: mode.value === 'totals' ? 0 : 1,
            });
        });
    });
})();
</script>
@endif
