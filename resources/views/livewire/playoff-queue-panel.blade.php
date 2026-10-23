<section class="season-panel" @if($active) wire:poll.3s @endif>
    <h2>Postseason games</h2>
    @if($message)<p class="season-notice" role="status">{{ $message }}</p>@endif
    @if(in_array($season->phase, ['playoffs_pending', 'playoffs'], true))
        <form wire:submit="queueGames" class="season-actions">
            <button class="landing-button" wire:loading.attr="disabled" wire:target="queueGames" @disabled(!$eligible)>
                <span wire:loading.remove wire:target="queueGames">Sim all CPU vs CPU Playoff Games ({{ $eligible }})</span>
                <span wire:loading wire:target="queueGames">Queueing playoff games…</span>
            </button>
            <span>{{ $queued }} queued · {{ $running }} running · {{ $failed }} failed</span>
            @if($active)<span role="status"><span class="button-feedback-spinner" aria-hidden="true"></span> Simulations are processing. Results refresh below.</span>@endif
            @if($failed)<p role="alert">Failed games can be retried using the button above.</p>@endif
        </form>
    @endif
    @if(($playoffBracket['status'] ?? '') === 'completed')
        <p class="season-notice">League champion: <strong>{{ $members[$playoffBracket['champion_team_id']]['name'] ?? 'Unknown' }}</strong></p>
    @else
        @php($round = (int) ($playoffBracket['current_round'] ?? 1))
        <h3>{{ $round === 1 ? 'Opening round' : (($playoffBracket['rounds'][$round]['championship'] ?? null) ? 'League championship' : 'Round '.$round) }}</h3>
        @php($groups = $round === 1 ? $playoffBracket['conferences'] : ($playoffBracket['rounds'][$round] ?? []))
        @foreach($groups as $conference => $division)
            @php($games = $round === 1 ? $division['games'] : $division)
            <h3>{{ $conference === 'championship' ? 'Championship' : ($conference === 'league' ? 'League' : ($members[$games[0]['home_team_id']]['conference_name'] ?? $conference)) }}</h3>
            @foreach($games as $index => $match)
                @php($saved = !empty($match['exhibition_id']) ? \App\Models\Exhibition::find($match['exhibition_id']) : null)
                @php($final = $saved && ($saved->state['status'] ?? null) === 'final' && !($saved->state['penalty_pending'] ?? false))
                <div class="season-actions">
                    <strong>#{{ $match['away_seed'] }} {{ $members[$match['away_team_id']]['name'] ?? $match['away_team_id'] }} at #{{ $match['home_seed'] }} {{ $members[$match['home_team_id']]['name'] ?? $match['home_team_id'] }}</strong>
                    @if(in_array($match['simulation_status'] ?? null, ['queued', 'running', 'failed'], true))
                        <span>{{ ($match['simulation_status'] ?? '') === 'failed' ? 'Simulation failed · Retry above' : ucfirst($match['simulation_status']).'…' }}</span>
                    @elseif($final)
                        <span>Final: {{ $saved->state['away_score'] }}–{{ $saved->state['home_score'] }}</span>
                        <a href="{{ route('exhibitions.show', ['exhibition' => $saved->id, 'highlights' => 1]) }}">Highlights / Replays</a>
                    @elseif($saved)
                        <a href="{{ route('exhibitions.show', ['exhibition' => $saved->id]) }}">Resume game</a>
                    @else
                        <form method="POST" action="{{ route('seasons.playoffs.game', [$season, $conference, $index]) }}" class="season-game-actions">
                            @csrf
                            <button name="quick_sim" value="0" class="landing-button">Play game</button>
                            <button name="quick_sim" value="1" class="landing-button">Quick Sim</button>
                        </form>
                    @endif
                </div>
            @endforeach
        @endforeach
        <form method="POST" action="{{ route('seasons.playoffs.advance', $season) }}" class="season-actions">
            @csrf<button class="landing-button">Advance playoff round</button>
            <span>All games must be final. NFL conferences reseed after each round.</span>
        </form>
        <p class="season-notice">Playoff CPU games can run in the background. Postseason statistical leaderboards are still pending.</p>
    @endif

</section>
