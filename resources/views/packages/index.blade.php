<x-layouts.app>
<div class="max-w-6xl mx-auto p-6 text-white space-y-8">
    <div><a class="text-blue-300 underline" href="{{ route('worlds.index') }}">Your worlds</a><h1 class="text-3xl font-semibold mt-3">Teams, rosters &amp; community library</h1><p class="text-gray-300 mt-2">Download editable packages, move teams between your worlds, or start with something the community created.</p></div>
    @if(session('status'))<p class="text-green-300">{{ session('status') }}</p>@endif
    @foreach($errors->all() as $error)<p class="text-red-300">{{ $error }}</p>@endforeach
    <section class="rounded-xl border border-gray-700 bg-gray-800 p-5 space-y-4">
        <h2 class="text-xl font-semibold">Upload a team, world or roster</h2>
        <p>Open the destination world before importing a team or CSV roster. World ZIPs create a new world.</p>
        <form method="POST" enctype="multipart/form-data" action="{{ route('packages.preview') }}" class="flex flex-wrap gap-4 items-center">@csrf
            <label>ZIP or CSV file <input class="block mt-2" type="file" name="package" accept=".zip,.csv" required></label><button class="rounded bg-blue-700 px-4 py-2">Preview upload</button>
        </form>
        <a class="text-blue-300 underline" href="{{ route('packages.guide') }}">How to create a ZIP or edit a roster · starter downloads</a>
    </section>
    <section class="space-y-4"><h2 class="text-xl font-semibold">Your teams · download, copy or share</h2>
        <p class="text-gray-300">Downloads use the latest roster year by default. Enter a source year to choose another roster. Copy opens a preview for your currently open world.</p>
        @foreach($sources as $team)
        <div class="rounded border border-gray-700 bg-gray-800 p-4 space-y-3">
            <h3 class="font-semibold">{{ $team->city }} {{ $team->name }} <span class="text-gray-400">· {{ $worlds->firstWhere('id', $team->world_id)?->name }}</span></h3>
            <form method="GET" action="{{ route('packages.team', $team->id) }}" class="flex flex-wrap gap-3 items-center">
                <label>Source year <input class="bg-gray-900 border border-gray-600 rounded p-2 w-28" type="number" min="1900" max="2200" name="year" placeholder="Latest"></label>
                <button class="text-blue-300 underline">Download team ZIP</button>
                <button class="text-blue-300 underline" formaction="{{ route('packages.roster', $team->id) }}">Download roster CSV</button>
            </form>
            <div class="flex flex-wrap gap-4"><form method="POST" action="{{ route('packages.copy', $team->id) }}">@csrf<button class="text-blue-300 underline">Copy to current world</button></form>
                <a class="text-blue-300 underline" href="{{ route('packages.share.form', ['team' => $team->id]) }}">Share team</a>
                <a class="text-blue-300 underline" href="{{ route('packages.share.form', ['team' => $team->id, 'kind' => 'roster']) }}">Share roster only</a></div>
        </div>
        @endforeach
    </section>
    <section class="space-y-4"><h2 class="text-2xl font-semibold">Community library</h2><p class="text-gray-300">Each listing is a snapshot. Imported copies belong to you and can be edited independently.</p>
        <div class="grid gap-4 md:grid-cols-2">
        @forelse($library as $package)
            <article class="rounded-xl border border-gray-700 bg-gray-800 p-5 space-y-3">
                <p class="text-blue-300 uppercase text-sm">{{ $package->kind }}</p><h3 class="text-xl font-semibold">{{ $package->title }}</h3>
                <p class="whitespace-pre-line">{{ $package->description }}</p><p class="text-gray-400">By {{ $package->creator?->name ?? 'Community creator' }} · {{ $package->team_count }} teams · {{ $package->roster_count }} players · {{ $package->created_at->format('M j, Y') }}</p>
                <div class="flex flex-wrap gap-4"><a class="text-blue-300 underline" href="{{ route('packages.library.download', $package) }}">Download {{ $package->kind === 'roster' ? 'CSV' : 'ZIP' }}</a>
                    <form method="POST" action="{{ route('packages.library.copy', $package) }}">@csrf<button class="text-blue-300 underline">{{ $package->kind === 'world' ? 'Start new world' : 'Copy to my world' }}</button></form>
                    @if($package->user_id === auth()->id())<form method="POST" action="{{ route('packages.unshare', $package) }}">@csrf @method('DELETE')<button class="text-red-300 underline">Unshare</button></form>@endif
                </div>
            </article>
        @empty<p class="text-gray-400">No shared packages yet. Share a team, world or roster to begin.</p>@endforelse
        </div>{{ $library->links() }}
    </section>
</div>
</x-layouts.app>
