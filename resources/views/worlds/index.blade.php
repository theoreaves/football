<x-layouts.app>
<div class="max-w-4xl mx-auto p-8 text-white space-y-8">
    <div><p class="text-sm text-blue-400 uppercase tracking-widest">Your football universe</p><h1 class="text-3xl font-semibold mt-2">Saved games</h1><p class="text-gray-400 mt-3">Create a league and play through its history. Your leagues and saves belong to your account and can be opened from any browser.</p></div>
    @if(session('status'))<p class="text-blue-200">{{ session('status') }}</p>@endif
    <div class="grid gap-4 sm:grid-cols-2">
    @forelse ($worlds as $world)
        <form method="POST" action="{{ route('worlds.select', $world->id) }}" class="rounded-xl border border-gray-700 bg-gray-800 p-5 space-y-3">@csrf
            <h2 class="text-xl font-semibold">{{ $world->name }}</h2>
            <p class="text-gray-400 text-sm">Save #{{ $world->id }} {{ app(\App\Support\CurrentWorld::class)->id == $world->id ? ' · Currently open' : '' }}</p>
            <button class="rounded bg-blue-700 px-4 py-2">Open saved game</button>
        </form>
    @empty
        <p class="text-gray-400">No saved games yet. Start your first league below.</p>
    @endforelse
    </div>
    <section class="rounded-xl border border-gray-700 p-6 bg-gray-800/50">
    <h2 class="text-xl mb-4">New saved game</h2>
    @foreach ($errors->all() as $error)<p class="text-red-400">{{ $error }}</p>@endforeach
    <form method="POST" action="{{ route('worlds.store') }}" class="space-y-4">@csrf
        <label class="block">Save name<input class="block w-full mt-1 rounded border border-gray-600 bg-gray-800 px-3 py-2 text-white focus:ring-2 focus:ring-blue-400" name="name" value="{{ old('name') }}" required maxlength="255" placeholder="Memphis franchise"></label>
        <label class="block">League name<input class="block w-full mt-1 rounded border border-gray-600 bg-gray-800 px-3 py-2 text-white" name="league_name" value="{{ old('league_name', 'Football League') }}" required maxlength="255"></label>
        <label class="block">Starting year<input class="block w-full mt-1 rounded border border-gray-600 bg-gray-800 px-3 py-2 text-white" type="number" name="year" value="{{ old('year', 2026) }}" required min="1900" max="2200"></label>
        <label class="block">Starting league<select name="preset" class="block w-full mt-1 rounded border border-gray-600 bg-gray-800 px-3 py-2 text-white"><option value="demo" @selected(old('preset', 'demo') === 'demo')>Demo · four fictional teams</option><option value="middle-earth" @selected(old('preset') === 'middle-earth')>Middle Earth · original 16 teams</option><option value="empty" @selected(old('preset') === 'empty')>Empty · create your own teams</option></select></label>
        <button class="px-5 py-2 bg-blue-700 rounded">Create saved game</button>
    </form>
    </section>
    <a class="inline-block text-blue-300 underline" href="{{ route('practice') }}">Explore the practice field</a>
</div>
</x-layouts.app>
