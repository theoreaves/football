<x-layouts.app>
    @php
        // Update these file names to match whatever your converter outputs

    @endphp

    <div class="min-h-screen bg-gray-950 text-gray-100 p-4 md:p-6"
         x-data="{
            tab: '{{ $cards[0]['key'] }}',
            setTab(k){ this.tab = k; }
         }"
    >
        <div class="max-w-6xl mx-auto space-y-4">
            {{-- Tabs --}}
            <div class="w-56">
                <label class="block text-xs text-white/70 mb-1">
                    {{ $cardSheet }}
                </label>

                <select
                    x-model="tab"
                    class="w-full bg-gray-900 border border-white/10 rounded px-2 py-2 text-sm text-gray-200
               focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    @foreach($cards as $c)
                        <option value="{{ $c['key'] }}">
                            {{ $c['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>


            {{-- Content --}}
            <div class="rounded-xl border border-white/10 bg-black/20 p-3 md:p-4">
                @foreach($cards as $c)
                    <div x-show="tab === '{{ $c['key'] }}'" x-cloak>
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm text-gray-400">{{ $c['label'] }}</div>
                            <div class="text-xs text-gray-500">Tip: click tabs to switch</div>
                        </div>

                        <div class="overflow-auto rounded-lg border border-white/10 bg-gray-50">
                            <img
                                src="{{ $c['src'] }}"
                                alt="{{ $c['label'] }}"
                                class="block max-w-none w-full"
                            >
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
    </div>

    <style>[x-cloak]{ display:none !important; }</style>
</x-layouts.app>
