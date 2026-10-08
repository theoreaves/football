<x-layouts.app>
<div class="max-w-4xl mx-auto p-8 text-white space-y-8">
    <div><x-brand-logo sport="general" class="w-48 mb-5" /><p class="text-sm text-blue-400 uppercase tracking-widest">Your football universe</p><h1 class="text-3xl font-semibold mt-2">Worlds</h1><p class="text-gray-400 mt-3">Keep each league, its teams, and its games in a separate world. Open your worlds from any browser.</p></div>
    @if(session('status'))<p class="text-blue-200">{{ session('status') }}</p>@endif
    <div class="grid gap-4 sm:grid-cols-2">
    @forelse ($worlds as $world)
        <form method="POST" action="{{ route('worlds.select', $world->id) }}" class="rounded-xl border border-gray-700 bg-gray-800 p-5 space-y-3">@csrf
            <h2 class="text-xl font-semibold">{{ $world->name }}</h2>
            <p class="text-gray-400 text-sm">World #{{ $world->id }} {{ app(\App\Support\CurrentWorld::class)->id == $world->id ? ' · Currently open' : '' }}</p>
            <button class="rounded bg-blue-700 px-4 py-2">Open world</button>
        </form>
    @empty
        <p class="text-gray-400">No worlds yet. Create your first football world.</p>
    @endforelse
    </div>
    <details class="creation-panel" @if($errors->any()) open @endif>
    <summary class="creation-toggle">Create new world</summary>
    <section class="creation-body">
    <h2 class="text-xl mb-4">Set up your world</h2>
    @foreach ($errors->all() as $error)<p class="text-red-400">{{ $error }}</p>@endforeach
    <form method="POST" action="{{ route('worlds.store') }}" class="space-y-4">@csrf
        <label class="block">World name<input class="block w-full mt-1 rounded border border-gray-600 bg-gray-800 px-3 py-2 text-white focus:ring-2 focus:ring-blue-400" name="name" value="{{ old('name') }}" required maxlength="255" placeholder="My football world"></label>
        <label class="block">League name<input class="block w-full mt-1 rounded border border-gray-600 bg-gray-800 px-3 py-2 text-white" name="league_name" value="{{ old('league_name', 'Football League') }}" required maxlength="255"></label>
        <label class="block">Starting year<input class="block w-full mt-1 rounded border border-gray-600 bg-gray-800 px-3 py-2 text-white" type="number" name="year" value="{{ old('year', 2026) }}" required min="1900" max="2200"></label>
        <label class="block">Starting league<select name="preset" class="block w-full mt-1 rounded border border-gray-600 bg-gray-800 px-3 py-2 text-white"><option value="demo" @selected(old('preset', 'demo') === 'demo')>Demo · four fictional teams</option><option value="pro" @selected(old('preset') === 'pro')>Pro Football · 32 NFL-inspired fictional teams</option><option value="empty" @selected(old('preset') === 'empty')>Empty · create your own teams</option></select></label>
        <button class="px-5 py-2 bg-blue-700 rounded">Create world</button>
    </form>
    </section>
    </details>
    <a class="inline-block text-blue-300 underline" href="{{ route('practice') }}">Explore the practice field</a>
</div>
</x-layouts.app>
