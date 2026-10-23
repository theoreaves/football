<div @if($active) wire:poll.3s @endif>
    @if($message)<p class="season-notice" role="status">{{ $message }}</p>@endif
    @include('seasons.sim-cpu')
    @include('seasons.fixtures', ['fixtures' => $tab === 'schedule' ? $fixtures : $fixtures->where('week', $season->current_week)])
    @if($tab === 'overview' && $season->phase === 'regular_season')
        @php
            $scheduledTeams = $fixtures->where('week', $season->current_week)
                ->flatMap(fn ($fixture) => [(int) $fixture->home_team_id, (int) $fixture->away_team_id])
                ->unique()->all();
            $byeTeams = collect($season->settings['members'])
                ->filter(fn ($member, $teamId) => !in_array((int) $teamId, $scheduledTeams, true))
                ->sortBy('name');
        @endphp
        @if($byeTeams->isNotEmpty())
            <section class="season-panel">
                <h3>Teams on bye · Week {{ $season->current_week }}</h3>
                <p>
                    @foreach($byeTeams as $teamId => $member)
                        <a href="{{ route('seasons.team', [$season, $teamId]) }}">{{ $member['name'] }}</a>
                        <span class="text-gray-400">({{ $records[$teamId]['wins'] ?? 0 }}-{{ $records[$teamId]['losses'] ?? 0 }}-{{ $records[$teamId]['ties'] ?? 0 }})</span>@unless($loop->last), @endunless
                    @endforeach
                </p>
            </section>
        @endif
    @endif
    @if($tab === 'overview' && $season->phase === 'regular_season')
        <form method="POST" action="{{ route('seasons.advance', $season) }}" class="season-actions">@csrf<input type="hidden" name="week" value="{{ $season->current_week }}"><button class="landing-button" @disabled($fixtures->where('week', $season->current_week)->isEmpty() || $fixtures->where('week', $season->current_week)->contains(fn($game) => $game->status !== 'final'))>{{ $season->current_week < $fixtures->max('week') ? 'Advance to next week' : 'Finish regular season' }}</button></form>
    @endif
</div>
