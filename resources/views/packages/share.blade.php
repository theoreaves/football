<x-layouts.app>
<div class="max-w-3xl mx-auto p-6 text-white space-y-5">
    <h1 class="text-3xl font-semibold">Share {{ $kind }}</h1><p>Publish an independent snapshot to the community library. Others can download, edit and import it into their own worlds.</p>
    @foreach($errors->all() as $error)<p class="text-red-300">{{ $error }}</p>@endforeach
    <form method="POST" action="{{ route('packages.share') }}" class="space-y-4">@csrf
        <input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="{{ $kind === 'world' ? 'world_id' : 'team_id' }}" value="{{ $source->id }}">
        <label class="block">Title<input class="block w-full mt-1 bg-gray-800 rounded border border-gray-600 p-2" name="title" maxlength="120" value="{{ old('title', trim(($source->city ?? '').' '.$source->name)) }}" required></label>
        <label class="block">Description<textarea class="block w-full mt-1 bg-gray-800 rounded border border-gray-600 p-2" name="description" maxlength="2000" rows="5">{{ old('description') }}</textarea></label>
        @if($kind !== 'world')<label class="block">Roster year (blank uses latest)<input class="block mt-1 bg-gray-800 rounded border border-gray-600 p-2" type="number" name="year" min="1900" max="2200"></label>@endif
        <button class="bg-blue-700 rounded px-5 py-2">Publish to library</button>
    </form>
</div>
</x-layouts.app>
