<div @if($active) wire:poll.3s @endif>
    @if($message)<p class="season-notice" role="status">{{ $message }}</p>@endif
    @include('seasons.sim-cpu')
    @include('seasons.fixtures', ['fixtures' => $tab === 'schedule' ? $fixtures : $fixtures->where('week', $season->current_week)])
    @if($tab === 'overview' && $season->phase === 'regular_season')
        <form method="POST" action="{{ route('seasons.advance', $season) }}" class="season-actions">@csrf<input type="hidden" name="week" value="{{ $season->current_week }}"><button class="landing-button" @disabled($fixtures->where('week', $season->current_week)->isEmpty() || $fixtures->where('week', $season->current_week)->contains(fn($game) => $game->status !== 'final'))>{{ $season->current_week < $fixtures->max('week') ? 'Advance to next week' : 'Finish regular season' }}</button></form>
    @endif
</div>
