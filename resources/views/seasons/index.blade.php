<x-layouts.app>
<main class="season-page">
    <header class="season-header"><div><p class="season-eyebrow">Your world</p><h1>Seasons</h1><p>Set up your league and its schedule.</p></div><a class="landing-button" href="{{ route('seasons.create') }}">Start new season</a></header>
    <p class="season-notice">Season v1 · Setup, schedules, and team control are ready. Playing season games, statistics, injuries, and playoff progression are the next milestone.</p>
    <div class="season-grid">
    @forelse($seasons as $season)
        <a class="season-panel" href="{{ route('seasons.show', $season) }}"><p class="season-eyebrow">{{ $season->league->name }} · {{ $season->year }}</p><h2>{{ $season->name }}</h2><p>{{ count($season->settings['members']) }} teams · {{ $season->settings['games'] }} games per team · Week {{ $season->current_week }}</p><span class="text-blue-300">Continue season →</span></a>
    @empty
        <section class="season-panel"><h2>Your first season starts here</h2><p>Pick teams, assign conferences and divisions, choose Human/CPU control, and preview the schedule before creating it.</p></section>
    @endforelse
    </div>
</main>
</x-layouts.app>
