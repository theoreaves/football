<x-layouts.app>
<div class="max-w-2xl mx-auto p-8 text-white">
    <h1 class="text-2xl mb-6">Your football worlds</h1>
    @foreach ($worlds as $world)
        <form method="POST" action="{{ route('worlds.select', $world->id) }}" class="mb-4">@csrf
            <button class="underline">{{ $world->name }}{{ auth()->user()->current_world_id == $world->id ? ' (current)' : '' }}</button>
        </form>
    @endforeach
    <h2 class="text-xl mt-8 mb-4">Create a world</h2>
    @foreach ($errors->all() as $error)<p class="text-red-400">{{ $error }}</p>@endforeach
    <form method="POST" action="{{ route('worlds.store') }}" class="space-y-4">@csrf
        <label class="block">World name<input class="block w-full text-black" name="name" value="{{ old('name') }}" required maxlength="255"></label>
        <label class="block">League name<input class="block w-full text-black" name="league_name" value="{{ old('league_name', 'Football League') }}" required maxlength="255"></label>
        <label class="block">Starting year<input class="block w-full text-black" type="number" name="year" value="{{ old('year', 2026) }}" required min="1900" max="2200"></label>
        <button class="px-4 py-2 bg-blue-700 rounded">Create world</button>
    </form>
    <p class="mt-4">New worlds start empty. Add teams through the team editor. Existing data can be assigned by the administrator.</p>
</div>
</x-layouts.app>
