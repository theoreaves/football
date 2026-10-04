<x-layouts.app>
<div data-practice data-appearance="{{ json_encode($appearance) }}" class="max-w-7xl mx-auto p-6 text-white space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-blue-400 uppercase text-xs tracking-widest">Practice field</p><h1 class="text-3xl font-semibold mt-2">Watch the play unfold</h1></div>
        <p class="text-gray-400 text-sm max-w-md">Scripted practice plays. These previews do not change your games or statistics.</p>
    </div>
    @if($teams->isNotEmpty())
    <form action="{{ route('practice') }}" method="GET" class="flex flex-wrap items-end gap-4">
        @foreach(['home' => 'Home / offense', 'away' => 'Away / defense'] as $venue => $label)
            <label class="text-sm">{{ $label }}<select name="{{ $venue }}" class="block bg-gray-900 text-white border border-gray-600 rounded px-3 py-2 mt-1">
                @foreach($teams as $team)<option value="{{ $team->id }}" @selected(${$venue}?->id === $team->id)>{{ $team->city }} {{ $team->name }}</option>@endforeach
            </select></label>
        @endforeach
        <button class="border border-gray-600 rounded px-4 py-2">Preview teams</button>
        @if($home)<a class="text-blue-300 text-sm" href="{{ route('teams.editor.edit', $home) }}">Edit home uniforms and field</a>@endif
    </form>
    @endif
    <div class="flex flex-wrap gap-4 items-end bg-gray-800 border border-gray-700 rounded-xl p-4">
        <label class="text-sm">Play<select data-play-type class="block bg-gray-900 text-white border border-gray-600 rounded px-3 py-2 mt-1"><option value="pass">Slant pass</option><option value="run">Inside run</option></select></label>
        <label class="text-sm">Camera<select data-camera class="block bg-gray-900 text-white border border-gray-600 rounded px-3 py-2 mt-1"><option value="broadcast">Broadcast</option><option value="overhead">Overhead</option></select></label>
        <label class="text-sm">Speed<select data-speed class="block bg-gray-900 text-white border border-gray-600 rounded px-3 py-2 mt-1"><option value="0.5">Half speed</option><option value="1" selected>Normal</option><option value="2">Double speed</option></select></label>
        <button data-play class="bg-blue-700 rounded px-6 py-2">Play</button><button data-reset class="border border-gray-600 rounded px-4 py-2">Reset</button>
        <button data-reset-camera class="border border-gray-600 rounded px-4 py-2">Reset camera</button><p class="ml-auto text-xs text-gray-400">Drag to orbit · Scroll to zoom</p>
    </div>
    <div data-field class="h-[560px] min-h-[400px] w-full rounded-xl overflow-hidden border border-gray-700 bg-gray-950"></div>
    <div class="flex flex-wrap items-center gap-4"><label class="sr-only" for="play-timeline">Play timeline</label><input id="play-timeline" data-timeline type="range" min="0" max="6" step="0.01" value="0" class="flex-1 min-w-40 accent-blue-400"><span data-time class="text-gray-400 text-sm tabular-nums">0.0 / 6.0s</span></div>
    <div class="flex flex-wrap gap-5 text-sm"><p data-status class="text-blue-300" aria-live="polite">Loading practice field…</p><span class="ml-auto text-blue-400">Home: offense</span><span class="text-red-400">Away: defense</span><span class="text-yellow-300">Yellow line: first down</span></div>
</div>
</x-layouts.app>
