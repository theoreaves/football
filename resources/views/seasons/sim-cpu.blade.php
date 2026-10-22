@if($season->phase === 'regular_season')
<form wire:submit="queueGames" method="POST" action="{{ route('seasons.sim-cpu', $season) }}" class="season-actions">
    @csrf
    <input type="hidden" name="week" value="{{ $season->current_week }}">
    <input type="hidden" name="return_tab" value="{{ $tab === 'schedule' ? 'schedule' : 'overview' }}">
    <button class="landing-button" wire:loading.attr="disabled" wire:target="queueGames" @disabled(!$cpuGames)>
        <span wire:loading.remove wire:target="queueGames">Sim all CPU vs CPU Games ({{ $cpuGames }})</span>
        <span wire:loading wire:target="queueGames">Queueing games…</span>
    </button>
    <span>Week {{ $season->current_week }} · Unstarted games and failed simulations</span>
    @if($active)<p class="w-full text-blue-200" role="status"><span class="button-feedback-spinner" aria-hidden="true"></span> Games are processing in the background. Results update automatically.</p>@endif
</form>
@endif
