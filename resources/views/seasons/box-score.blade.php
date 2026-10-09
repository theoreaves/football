<x-layouts.app :immersive="true">
<main class="season-page p-5">
    <div class="season-actions mb-5"><a class="landing-button" target="_top" href="{{ route('exhibitions.show', ['exhibition' => $exhibition, 'highlights' => 1]) }}">Highlights / Replays · Open game</a><a target="_top" href="{{ route('seasons.show', ['season' => $season, 'tab' => 'schedule']) }}">Back to schedule</a></div>
    @include('exhibitions.box-score')
</main>
</x-layouts.app>
