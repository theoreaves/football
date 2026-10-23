<div @if($active) wire:poll.3s @endif>
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
</div>
