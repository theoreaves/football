        <div class="flex flex-wrap gap-2 mb-5" role="tablist" aria-label="Lineup group">
            <button type="button" data-lineup-tab="offense" aria-selected="true" class="rounded px-4 py-2 bg-blue-700">Offense</button>
            <button type="button" data-lineup-tab="defense" aria-selected="false" class="rounded px-4 py-2 bg-gray-700">Defense</button>
            <button type="button" data-lineup-tab="special" aria-selected="false" class="rounded px-4 py-2 bg-gray-700">Special Teams</button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            @foreach(['away', 'home'] as $lineupSide)
                <section class="rounded-lg p-3" style="background:#1a2840;">
                    <div class="flex gap-3 items-center mb-4">
                        @if($appearance[$lineupSide]['team_logo'] ?? null)<img src="{{ $appearance[$lineupSide]['team_logo'] }}" alt="" loading="lazy" class="w-12 h-12 object-contain">@endif
                        <div><p class="text-xs uppercase tracking-wider text-blue-300">{{ ucfirst($lineupSide) }} team</p><h3 class="font-bold text-lg">{{ $teamNames[$lineupSide] }}</h3></div>
                    </div>
                    @foreach(['offense' => ['QB','RB','WR1','WR2','WR3','TE','C','LG','RG','LT','RT'], 'defense' => ['DE1','DT1','DT2','DE2','LB1','LB2','LB3','CB1','CB2','S1','S2'], 'special' => ['K','P']] as $lineupGroup => $lineupRoles)
                        <div data-lineup-group="{{ $lineupGroup }}" style="{{ $lineupGroup === 'offense' ? 'display:grid' : 'display:none' }}" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach($lineupRoles as $lineupRole)
                            @php
                                $starter = $exhibition->rosters[$lineupSide]['players'][$lineupRole] ?? null;
                            @endphp
                            @if($starter)
                                @php
                                    $skin = $starter['skin_tone'] ?? '#bd906f';
                                @endphp
                                @php
                                    $hair = $starter['appearance']['hair_color'] ?? '#29241f';
                                    $hairStyle = $starter['appearance']['hair'] ?? 'short';
                                    $headShape = $starter['appearance']['head_shape'] ?? 'round';
                                    $beardStyle = $starter['appearance']['beard'] ?? 'none';
                                    $browStyle = $starter['appearance']['brow'] ?? 'straight';
                                    $eyeColor = $starter['appearance']['eye_color'] ?? '#17202b';
                                @endphp
                                <div class="flex gap-2 items-center rounded p-2" style="background:#223552;min-width:0;">
                                    @include('exhibitions.partials.player-portrait', ['portraitPlayer' => $starter, 'portraitSide' => $lineupSide])
                                    <div class="min-w-0"><p class="text-xs text-blue-300">{{ $lineupRole }} · #{{ $starter['number'] ?? '—' }}</p><p class="text-sm font-semibold truncate" title="{{ $starter['name'] }}">{{ $starter['name'] }}</p></div>
                                </div>
                            @endif
                        @endforeach
                        </div>
                    @endforeach
                </section>
            @endforeach
        </div>
