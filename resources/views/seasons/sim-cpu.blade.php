@if($season->phase === 'regular_season')
@php
    $cpuGames = $fixtures->filter(fn($game) => (int) $game->week === (int) $season->current_week && !$game->exhibition_id && $game->status === 'scheduled' && ($season->settings['members'][$game->home_team_id]['control'] ?? 'human') === 'cpu' && ($season->settings['members'][$game->away_team_id]['control'] ?? 'human') === 'cpu')->count();
@endphp
<form data-sim-cpu method="POST" action="{{ route('seasons.sim-cpu', $season) }}" class="season-actions">
    @csrf
    <input type="hidden" name="week" value="{{ $season->current_week }}">
    <input type="hidden" name="return_tab" value="{{ request('tab') === 'schedule' ? 'schedule' : 'overview' }}">
    <button class="landing-button" @disabled(!$cpuGames)>Sim all CPU vs CPU Games ({{ $cpuGames }})</button>
    <span>Week {{ $season->current_week }} · Unstarted games only</span>
</form>
@endif
