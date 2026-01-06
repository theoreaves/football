<div class="p-4 space-y-4 text-gray-100">

    <div class="flex items-center gap-6 text-white/90 justify-between">
        <div class="font-semibold">Q{{ $game->quarter }} — {{ $this->clockDisplay }}</div>
        <div class="flex gap-6">
            <div class="font-semibold cursor-pointer text-sm" onclick="openPlayCard('game')" >Game Charts</div>
            <div class="font-semibold cursor-pointer text-sm" onclick="openAdvanced('advanced')" >Advanced Charts</div>
            <div class="font-semibold cursor-pointer text-sm" onclick="openPdfLibrary()" >PDF Library</div>
        </div>
        <div class="font-semibold flex gap-2">
            <button
                class="text-blue-400 hover:underline"
                onclick="openTeamSheet({{ $game->away_team_id }}, '{{ $game->away_label }}')"
            >
                {{ $game->away_label }}
            </button>

            <span>AT</span>

            <button
                class="text-blue-400 hover:underline"
                onclick="openTeamSheet({{ $game->home_team_id }}, '{{ $game->home_label }}')"
            >
                {{ $game->home_label }}
            </button>
        </div>

    </div>



    <div class="flex gap-6 items-center">
    <div class="flex flex-wrap gap-4 items-center w-1/2">
        <div class="px-3 py-2 rounded bg-gray-800 border border-gray-700">
            <div class="text-sm opacity-80">Possession</div>
            <div class="font-semibold">{{ $game->labelForSide($game->possession) }}</div>
        </div>

        <div class="px-3 py-2 rounded bg-gray-800 border border-gray-700">
            <div class="text-sm opacity-80">Spot</div>
            <div class="font-semibold">{{ $game->spot_label }}</div>
        </div>

        <div class="px-3 py-2 rounded bg-gray-800 border border-gray-700">
            <div class="text-sm opacity-80">Down & Distance</div>
            <div class="font-semibold">{{ $game->down }} &amp; {{ $game->to_go }}</div>
        </div>

        <div class="px-3 py-2 rounded bg-gray-800 border border-gray-700">
            <div class="text-sm opacity-80">Score</div>
            <div class="font-semibold">{{ $game->away_score }} {{ strtoupper($game->awayTeam->name) }} - {{ strtoupper($game->homeTeam->name) }} {{ $game->home_score }}</div>
        </div>
    </div>


    <table class="w-1/2 mt-3 text-sm text-white/80">
        <thead>
        <tr class="text-left">
            <th class="py-1"></th>
            <th class="py-1">Q1</th>
            <th class="py-1">Q2</th>
            <th class="py-1">Q3</th>
            <th class="py-1">Q4</th>
            <th class="py-1">OT</th>
            <th class="py-1">Total</th>
        </tr>
        </thead>
        <tbody>
        @php
            $hq = $game->home_q ?? [0,0,0,0,0];
            $aq = $game->away_q ?? [0,0,0,0,0];
        @endphp
        <tr>
            <td class="py-1 font-semibold">{{ $game->away_label }}</td>
            <td>{{ $aq[0] }}</td><td>{{ $aq[1] }}</td><td>{{ $aq[2] }}</td> <td>{{ $aq[3] }}</td> <td>{{ $aq[4] }}</td>
            <td class="font-semibold">{{ array_sum($aq) }}</td>
        </tr>
        <tr>
            <td class="py-1 font-semibold">{{ $game->home_label }}</td>
            <td>{{ $hq[0] }}</td><td>{{ $hq[1] }}</td><td>{{ $hq[2] }}</td> <td>{{ $hq[3] }}</td> <td>{{ $hq[4] }}</td>
            <td class="font-semibold">{{ array_sum($hq) }}</td>
        </tr>
        </tbody>
    </table>

    </div>

    <div class="flex items-center gap-2 mb-2">
        @foreach([100, 75, 50, 25] as $s)
            <button
                type="button"
                wire:click="setFieldScale({{ $s }})"
                class="px-2 py-1 rounded border text-xs
                   {{ $fieldScale === $s ? 'bg-gray-800 text-white border-gray-600' : 'bg-gray-900/40 text-white/80 border-white/10 hover:bg-gray-800/60' }}">
                {{ $s }}%
            </button>
        @endforeach
    </div>

    

    {{-- Field (image-native, no stretch; markers aligned to image marks) --}}
    @php
        // Image is 1024x486 (your uploaded asset)
        // We set the container aspect ratio to match so the image is never stretched.
        $fieldBgUrl = $game->field_bg_url ?? asset('fields/default.png');

        // Your existing 120-yard positioning values (0..100 field mapped with endzones)
        // ballLeft120, lineToGainLeft120, seriesLeft120, ballSideMarkerLeft120 are assumed to be 0..100 (% of the 120y canvas)
        // If any are null, we guard below.

        // The usable field inside the image has an inner border.
        // Measured from your PNG: ~12px inset on each side of a 1024px-wide image.
        $imgInsetPct  = (12 / 1024) * 100;        // 1.171875%
        $imgUsablePct = 100 - ($imgInsetPct * 2); // 97.65625%

        // Map a 0..100% x-position into the inner drawable area of the image
        $mapToImage = fn($pct) => $imgInsetPct + ($pct * $imgUsablePct / 100);

        $posTeam = $game->possessionTeam();
    @endphp

    @php
        $fieldScale = $this->fieldScale ?? 100; // 100,75,50,25
    @endphp

    <div class="w-full mx-auto"
         style="width: {{ $fieldScale }}%; max-width: 100%;">
        <div class="relative w-full aspect-[1024/486] rounded-lg overflow-hidden border border-gray-700">




    <div class="w-full">
        <div class="relative w-full aspect-[1024/486] rounded-lg overflow-hidden border border-gray-700">

            {{-- Render the image without stretching --}}
            <img
                src="{{ $fieldBgUrl }}"
                alt="Field"
                class="absolute inset-0 w-full h-full object-contain select-none pointer-events-none"
            />

            {{-- Football marker --}}
            @if($this->ballLeft120 !== null)
                <div class="absolute top-1/2 -translate-y-1/2 transition-all duration-500 ease-in-out z-40"
                     style="left: calc({{ $mapToImage($this->ballLeft120) }}% - 10px);">
                    <div class="flex items-center gap-2">
                        <div class="w-5 h-3 rounded-full border border-white/60" style="background:#8b4513;"></div>
                        <div class="text-xs text-white/80 font-semibold">
                            @if($game->possession === 'HOME') → @else ← @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- 1st down / series / down marker --}}
            @if($this->showLineToGain && $this->lineToGainLeft120 !== null)

                {{-- Line to gain --}}
                <div class="absolute top-0 bottom-0 pointer-events-none z-30"
                     style="
                    left: {{ $mapToImage($this->lineToGainLeft120) }}%;
                    width: 3px;
                    background: rgba(255, 215, 0, 0.55);
                    box-shadow: 0 0 10px rgba(255, 215, 0, 0.35);
                 ">
                </div>

                {{-- 1st label --}}
                <div class="absolute pointer-events-none z-40 text-[10px] font-semibold px-2 py-0.5 rounded bg-yellow-400 text-white font-bolt"
                     style="
                    left: calc({{ $mapToImage($this->lineToGainLeft120) }}% + 6px);
                    top: 8px;
                    /*background: yellow;*/
                    /*color: rgba(255,255,255,0.9);*/
                 ">
                    1st
                </div>

                {{-- Series start marker --}}
                @if($this->seriesLeft120 !== null)
                    <div class="absolute pointer-events-none z-40"
                         style="left: calc({{ $mapToImage($this->seriesLeft120) }}% - 18px); bottom: 8px;">
                        <div class="text-[10px] font-semibold px-2 py-0.5 rounded border border-white/20"
                             style="background: rgba(0,0,0,0.35); color: rgba(255,255,255,0.85);">
                            <div class="mx-auto mb-1" style="width:2px; height:10px; background: rgba(255,255,255,0.45);"></div>
                            S
                        </div>
                    </div>
                @endif

                {{-- Current ball marker with down number --}}
                @if($this->ballSideMarkerLeft120 !== null)
                    <div class="absolute pointer-events-none z-40"
                         style="left: calc({{ $mapToImage($this->ballSideMarkerLeft120) }}% - 10px); top: 8px;">
                        <div class="text-[10px] font-semibold w-6 text-center py-0.5 rounded border border-white/20 bg-gray-900 text-gray-50 font-bold"
{{--                             style="background: rgba(0,0,0,0.35); color: rgba(255,255,255,0.85);"--}}
                        >
                            {{ $this->downLabel }}
{{--                            <div class="mx-auto mt-1" style="width:2px; height:10px; background: rgba(255,255,255,0.45);"></div>--}}
                        </div>
                    </div>
                @endif
            @endif

            {{-- Possession text --}}
            <div class="absolute bottom-2 left-2 z-50 text-xs text-white/80 bg-black/40 px-2 py-1 rounded">
                {{ strtoupper($posTeam->name) }} ball — {{ $game->pos_side }} {{ (int)$game->pos_yardline }}
            </div>

        </div>
    </div>

        </div>

        {{-- Optional: prevent layout from collapsing when scaled down (pick one approach below) --}}
    </div>



    {{-- Controls --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

        @if((int)$game->quarter === 5 && ($game->phase ?? '') !== 'FINAL')
            <button type="button"
                    wire:click="endOvertimeNow"
                    class="px-3 py-1 rounded bg-red-700 hover:bg-red-600">
                Game Over
            </button>
        @endif

        @if($game->phase === 'NORMAL')

        <form
            x-data="playKeys()"
            x-init="init()"
            @keydown.window.capture="onKey($event)"
            wire:submit.prevent="submitPlay"
        >
            @php
                // helper to decide which team we should look up for offense/defense roles
                $offSide = strtoupper((string)($game->possession ?? 'HOME')); // HOME/AWAY
                $defSide = $offSide === 'HOME' ? 'AWAY' : 'HOME';

                $lookupUrl = route('games.lookupJersey', $game);
            @endphp

            <script>
                async function lookupTeamPlayer({ url, side, number }) {
                    const res = await fetch(url + `?side=${encodeURIComponent(side)}&number=${encodeURIComponent(number)}`, {
                        headers: { 'Accept': 'application/json' }
                    });
                    return await res.json();
                }
            </script>

            @php
                /**
                 * Render one lookup input.
                 * Params:
                 *  - $label
                 *  - $idFieldName (ex: qb_team_player_id)
                 *  - $numFieldName (ex: qb_number) if you want to keep it
                 *  - $side (HOME/AWAY)
                 */
            @endphp

            @once
                <style>
                    [x-cloak]{ display:none !important; }
                </style>
            @endonce

            <div class="p-4 rounded bg-gray-800 border border-gray-700 space-y-3">
            <div class="font-semibold">Add Play</div>




                <div class="flex flex-wrap gap-2 items-center">
                <select  x-ref="result" wire:model="play_type" class="bg-gray-900 border border-gray-700 rounded px-2 py-1">
                    <option value="">Result</option>
                    <option value="RUN">RUN</option>
                    <option value="PASS">PASS</option>
                    <option value="INCOMPLETE">INCOMPLETE PASS</option>
                    <option value="FIELDGOAL">FIELD GOAL ATTEMPT</option>
                    <option value="INT">INTERCEPTION</option>
                    <option value="FUMBLE">FUMBLE</option>
                    <option value="SACK">SACK</option>
                    <option value="PENALTY">PENALTY</option>
                    <option value="PUNT">PUNT</option>
                    <option value="KICKOFF">KICKOFF</option>
                </select>

                <input type="number" min="-99" max="99" x-ref="yards" wire:model="play_yards"
                       class="w-24 bg-gray-900 border border-gray-700 rounded px-2 py-1"
                       placeholder="Yards" />

                <input type="text" wire:model="play_note"
                       class="flex-1 min-w-[180px] bg-gray-900 border border-gray-700 rounded px-2 py-1"
                       placeholder="Note (optional)" />


                    <div
                        x-data="{
{{--        playType: @entangle('newPlayType').defer ?? 'RUSH', // if Livewire; otherwise set manually--}}
        playType: @entangle('play_type').live,

        lookupUrl: '{{ $lookupUrl }}',

            // entangle participant IDs into Livewire
        qb_id: @entangle('qb_team_player_id').live,
        bc_id: @entangle('ballcarrier_team_player_id').live,
        wr_id: @entangle('receiver_team_player_id').live,
        tkl_id: @entangle('tackled_by_team_player_id').live,
        int_id: @entangle('intercepted_by_team_player_id').live,
        fumrec_id: @entangle('fumble_recovered_by_team_player_id').live,

        // storage for each field
        qb: { number: '', found: false, text: '', team_player_id: null },
        bc: { number: '', found: false, text: '', team_player_id: null },
        wr: { number: '', found: false, text: '', team_player_id: null },
        tkl: { number: '', found: false, text: '', team_player_id: null },
        intBy: { number: '', found: false, text: '', team_player_id: null },
        fumRec: { number: '', found: false, text: '', team_player_id: null },

        async doLookup(fieldKey, side) {
            const field = this[fieldKey];
            field.found = false;
            field.text = '';
            field.team_player_id = null;

            const num = parseInt(field.number || '0', 10);
            if (!num) {
                // clear the mapped livewire id too
                if (fieldKey === 'qb') this.qb_id = null;
                if (fieldKey === 'bc') this.bc_id = null;
                if (fieldKey === 'wr') this.wr_id = null;
                if (fieldKey === 'tkl') this.tkl_id = null;
                if (fieldKey === 'intBy') this.int_id = null;
                if (fieldKey === 'fumRec') this.fumrec_id = null;
                return;
            }

            const data = await lookupTeamPlayer({ url: this.lookupUrl, side, number: num });

            if (data?.found && data?.player) {
                field.found = true;
                field.team_player_id = data.player.team_player_id;
                field.text = `#${data.player.jersey_number} ${data.player.firstname} ${data.player.lastname} (${data.player.position})`;
                if (fieldKey === 'qb') this.qb_id = data.player.team_player_id;
                if (fieldKey === 'bc') this.bc_id = data.player.team_player_id;
                if (fieldKey === 'wr') this.wr_id = data.player.team_player_id;
                if (fieldKey === 'tkl') this.tkl_id = data.player.team_player_id;
                if (fieldKey === 'intBy') this.int_id = data.player.team_player_id;
                if (fieldKey === 'fumRec') this.fumrec_id = data.player.team_player_id;
            } else {
                field.found = false;
                field.text = 'Not found';
                if (fieldKey === 'qb') this.qb_id = null;
                if (fieldKey === 'bc') this.bc_id = null;
                if (fieldKey === 'wr') this.wr_id = null;
                if (fieldKey === 'tkl') this.tkl_id = null;
                if (fieldKey === 'intBy') this.int_id = null;
                if (fieldKey === 'fumRec') this.fumrec_id = null;
            }
        }
    }"
                        class="rounded-lg border border-white/10 p-4 bg-gray-950"
                    >
                        <div class="text-sm font-semibold text-gray-200 mb-3">Play Participants</div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <!-- QB (passing only, offense side) -->

                            <div x-show="playType === 'PASS' || playType === 'INCOMPLETE' || playType === 'INT'" x-cloak >
                                <label class="block text-xs text-gray-400 mb-1">QB Jersey # ({{ $offSide }})</label>
                                <input type="number" min="0"
                                       class="w-full bg-gray-900 border border-white/10 rounded px-3 py-2"
                                       x-model="qb.number"
                                       @keydown.enter.prevent="doLookup('qb','{{ $offSide }}')"
                                       @blur="doLookup('qb','{{ $offSide }}')"
                                       placeholder="e.g. 15">
                                <input type="hidden" name="qb_team_player_id" :value="qb.team_player_id">
                                <div class="mt-1 text-xs" :class="qb.found ? 'text-green-400' : 'text-red-400'" x-text="qb.text"></div>
                            </div>

                            <!-- Ball Carrier (rush/fumble, offense side) -->
                            <div x-show="playType === 'RUN' || playType === 'FUMBLE'" x-cloak>
                                <label class="block text-xs text-gray-400 mb-1">Ball Carrier Jersey # ({{ $offSide }})</label>
                                <input type="number" min="0"
                                       class="w-full bg-gray-900 border border-white/10 rounded px-3 py-2"
                                       x-model="bc.number"
                                       @keydown.enter.prevent="doLookup('bc','{{ $offSide }}')"
                                       @blur="doLookup('bc','{{ $offSide }}')"
                                       placeholder="e.g. 10">
                                <input type="hidden" name="ballcarrier_team_player_id" :value="bc.team_player_id">
                                <div class="mt-1 text-xs" :class="bc.found ? 'text-green-400' : 'text-red-400'" x-text="bc.text"></div>
                            </div>

                            <!-- Receiver (passing only, offense side) -->
                            <div x-show="playType === 'PASS' || playType === 'INCOMPLETE' || playType === 'INT'" x-cloak >
                                <label class="block text-xs text-gray-400 mb-1">Receiver Jersey # ({{ $offSide }})</label>
                                <input type="number" min="0"
                                       class="w-full bg-gray-900 border border-white/10 rounded px-3 py-2"
                                       x-model="wr.number"
                                       @keydown.enter.prevent="doLookup('wr','{{ $offSide }}')"
                                       @blur="doLookup('wr','{{ $offSide }}')"
                                       placeholder="e.g. 87">
                                <input type="hidden" name="receiver_team_player_id" :value="wr.team_player_id">
                                <div class="mt-1 text-xs" :class="wr.found ? 'text-green-400' : 'text-red-400'" x-text="wr.text"></div>
                            </div>

                            <!-- Tackled By (rush/pass/fumble, defense side) -->
                            <div x-show="playType === 'RUN' || playType === 'PASS' || playType === 'INCOMPLETE' || playType === 'FUMBLE' || playType === 'SACK'" x-cloak>
                                <label class="block text-xs text-gray-400 mb-1">Tackled By Jersey # ({{ $defSide }})</label>
                                <input type="number" min="0"
                                       class="w-full bg-gray-900 border border-white/10 rounded px-3 py-2"
                                       x-model="tkl.number"
                                       @keydown.enter.prevent="doLookup('tkl','{{ $defSide }}')"
                                       @blur="doLookup('tkl','{{ $defSide }}')"
                                       placeholder="e.g. 32">
                                <input type="hidden" name="tackled_by_team_player_id" :value="tkl.team_player_id">
                                <div class="mt-1 text-xs" :class="tkl.found ? 'text-green-400' : 'text-red-400'" x-text="tkl.text"></div>
                            </div>

                            <!-- Intercepted By (only if play result is INT — you can gate this on a checkbox/select) -->
                            <div x-show="playType === 'INT'" x-cloak>
                                <label class="block text-xs text-gray-400 mb-1">Intercepted By Jersey # ({{ $defSide }})</label>
                                <input type="number" min="0"
                                       class="w-full bg-gray-900 border border-white/10 rounded px-3 py-2"
                                       x-model="intBy.number"
                                       @keydown.enter.prevent="doLookup('intBy','{{ $defSide }}')"
                                       @blur="doLookup('intBy','{{ $defSide }}')"
                                       placeholder="e.g. 35">
                                <input type="hidden" name="intercepted_by_team_player_id" :value="intBy.team_player_id">
                                <div class="mt-1 text-xs" :class="intBy.found ? 'text-green-400' : 'text-red-400'" x-text="intBy.text"></div>
                            </div>

                            <!-- Fumble recovered by (fumble only) -->
                            <div x-show="playType === 'FUMBLE'" x-cloak>
                                <label class="block text-xs text-gray-400 mb-1">Fumble Recovered By Jersey #</label>
                                <div class="flex gap-2">
                                    <select class="bg-gray-900 border border-white/10 rounded px-2 py-2 text-sm"
                                            x-data
                                            x-on:change="$event.target.dataset.side = $event.target.value">
                                        <option value="{{ $offSide }}">Recovered by {{ $offSide }}</option>
                                        <option value="{{ $defSide }}">Recovered by {{ $defSide }}</option>
                                    </select>

                                    <input type="number" min="0"
                                           class="w-full bg-gray-900 border border-white/10 rounded px-3 py-2"
                                           x-model="fumRec.number"
                                           @keydown.enter.prevent="
                           // default to defense if you want; otherwise hardcode a side or add a dedicated side selector
                           doLookup('fumRec','{{ $defSide }}')
                       "
                                           @blur="doLookup('fumRec','{{ $defSide }}')"
                                           placeholder="e.g. 56">
                                </div>

                                <input type="hidden" name="fumble_recovered_by_team_player_id" :value="fumRec.team_player_id">
                                <div class="mt-1 text-xs" :class="fumRec.found ? 'text-green-400' : 'text-red-400'" x-text="fumRec.text"></div>
                            </div>
                        </div>
                    </div>




                    <button type="submit"
                        class="px-3 py-1 rounded bg-green-600 hover:bg-green-500">
                    Add
                </button>
            </div>

            @error('play_type') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
            @error('play_yards') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
            @error('play_note') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror


                <div class="mb-4">
                    <div class="gap-8">
                        <div>
                            <h3 class="font-bold">Offense</h3>
                            <div class="flex gap-2">
{{--                                @dump($offensePlayers)--}}
                                @foreach ($offensePlayers as $player)
                                    <div>
                                    <div>{{ $player['depth_chart_position'] }}</div>
                                    <div>{{ $player['jersey_number'] ?? 'N/A' }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <h3 class="font-bold">Defense</h3>
                            <div class="flex gap-2">
                                @foreach ($defensePlayers as $player)
                                    <div>
                                    <div>{{ $player['depth_chart_position'] }}</div>
                                    <div>{{ $player['jersey_number'] ?? 'N/A' }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>



            <div class="mt-2 rounded-md border border-white/10 bg-black/20 px-3 py-2 text-xs text-white/75">
                <div class="font-semibold text-white/85 mb-1">Shortcuts</div>
                <div class="grid grid-cols-2 gap-x-6 gap-y-1">
                    <div><span class="font-semibold text-white">R</span> Run (yards)</div>
                    <div><span class="font-semibold text-white">P</span> Pass (yards)</div>

                    <div><span class="font-semibold text-white">I</span> Incomplete</div>
                    <div><span class="font-semibold text-white">S</span> Sack (yards)</div>

                    <div><span class="font-semibold text-white">N</span> PeNaltry (yards)</div>
                    <div><span class="font-semibold text-white">F</span> Fumble</div>

                    <div><span class="font-semibold text-white">T</span> InTerception</div>
                    <div><span class="font-semibold text-white">U</span> pUnt</div>

                    <div><span class="font-semibold text-white">K</span> Kickoff</div>
                </div>
            </div>

        </div>
        </form>
        @endif
        @if($game->phase === 'FIELDGOAL')
            <div class="p-4 rounded bg-gray-800 border border-gray-700 space-y-3">
                <div class="font-semibold">Field Goal</div>

                <div class="flex gap-2">
                    <button wire:click="recordFieldGoal(true)"
                            class="px-3 py-1 rounded bg-green-600 hover:bg-green-500">
                        FG Good
                    </button>

                    <button wire:click="recordFieldGoal(false)"
                            class="px-3 py-1 rounded bg-red-600 hover:bg-red-500">
                        FG Miss
                    </button>
                </div>
            </div>
        @endif
        @if($game->phase === 'INT')
            <div class="p-4 rounded bg-gray-800 border border-gray-700 space-y-3">
                <div class="font-semibold">Interception</div>

                <div class="flex gap-2 items-center">
                    <label class="text-sm opacity-80">Return yards</label>
                    <input type="number" min="-20" max="99" wire:model="int_return_yards"
                           class="w-24 bg-gray-900 border border-gray-700 rounded px-2 py-1" />

                    <button wire:click="recordInterceptionReturn"
                            class="px-3 py-1 rounded bg-green-600 hover:bg-green-500">
                        Record Return
                    </button>

                    <button wire:click="$set('int_return_yards', 0); recordInterceptionReturn()"
                            class="px-3 py-1 rounded bg-gray-700 hover:bg-gray-600">
                        No Return
                    </button>
                </div>
            </div>
        @endif
        @if($game->phase === 'FUMBLE')
            <div class="p-4 rounded bg-gray-800 border border-gray-700 space-y-3">
                <div class="font-semibold">Fumble</div>

                <div class="flex flex-wrap gap-3 items-center">
                    <label class="text-sm opacity-80">Recovered by</label>
                    <select wire:model="fumble_recovered_by"
                            class="bg-gray-900 border border-gray-700 rounded px-2 py-1">
                        <option value="OFFENSE">Offense keeps it</option>
                        <option value="DEFENSE">Defense recovered</option>
                    </select>

{{--                    @if($fumble_recovered_by === 'DEFENSE')--}}
                        <label class="text-sm opacity-80">Return yards</label>
                        <input type="number" min="-20" max="99" wire:model="fumble_return_yards"
                               class="w-24 bg-gray-900 border border-gray-700 rounded px-2 py-1" />
{{--                    @endif--}}

                    <button wire:click="resolveFumble"
                            class="px-3 py-1 rounded bg-green-600 hover:bg-green-500">
                        Record Fumble
                    </button>
                </div>
            </div>
        @endif


    @if($game->phase === 'TRY')
            <div class="p-4 rounded bg-gray-800 border border-gray-700 space-y-3">
                <div class="font-semibold">Try After Touchdown</div>
                <div class="text-sm opacity-80">
                    Team attempting: <span class="font-semibold">{{ $game->try_team }}</span>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button wire:click="recordTry('XP', true)"
                            class="px-3 py-1 rounded bg-green-600 hover:bg-green-500">
                        XP Good
                    </button>

                    <button wire:click="recordTry('XP', false)"
                            class="px-3 py-1 rounded bg-red-600 hover:bg-red-500">
                        XP Fail
                    </button>

                    <button wire:click="recordTry('2PT', true)"
                            class="px-3 py-1 rounded bg-green-600 hover:bg-green-500">
                        2PT Good
                    </button>

                    <button wire:click="recordTry('2PT', false)"
                            class="px-3 py-1 rounded bg-red-600 hover:bg-red-500">
                        2PT Fail
                    </button>
                </div>
            </div>
        @endif


        @if($game->phase === 'KICKOFF')
            <div class="p-4 rounded bg-gray-800 border border-gray-700 space-y-3">
                <div class="font-semibold">Kickoff</div>

                @if($game->phase === 'KICKOFF' && empty($game->kick_team))
                    <div class="p-3 rounded bg-gray-900/60 border border-gray-700 space-y-2">
                        <div class="text-sm font-semibold">Select kicking team</div>

                        <div class="flex items-center gap-2">
                            <select wire:model="kickoff_kicking_team"
                                    class="bg-gray-900 border border-gray-700 rounded px-2 py-1">
                                <option value="">Choose…</option>
                                <option value="AWAY">{{ $game->away_label }}</option>
                                <option value="HOME">{{ $game->home_label }}</option>

                            </select>

                            <button type="button"
                                    wire:click="chooseKickoffTeam"
                                    class="px-3 py-1 rounded bg-blue-600 hover:bg-blue-500">
                                Start Kickoff
                            </button>
                        </div>

                        @error('kickoff_kicking_team') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
                    </div>
                @else


                <div class="text-sm opacity-80">
                    Kicking team: <span class="font-semibold">{{ $game->kick_team ?? $game->possession }}</span>
                </div>

                <div class="flex flex-wrap gap-2 items-center">
                    <label class="text-sm opacity-80">Kick yards</label>
                    <input type="number" min="0" max="99" wire:model="kick_yards"
                           class="w-24 bg-gray-900 border border-gray-700 rounded px-2 py-1" />

                    <button wire:click="recordKickoff"
                            class="px-3 py-1 rounded bg-blue-600 hover:bg-blue-500">
                        Record Kick
                    </button>
                </div>
                @endif

                @if($kick_recorded)
                    <div class="flex flex-wrap gap-2 items-center pt-2 border-t border-gray-700">
                        <label class="text-sm opacity-80">Return yards</label>
                        <input type="number" min="-20" max="99" wire:model="return_yards"
                               class="w-24 bg-gray-900 border border-gray-700 rounded px-2 py-1" />

                        <button wire:click="recordKickReturnWithReason(null)"
                                class="px-3 py-1 rounded bg-green-600 hover:bg-green-500">
                            Record Return
                        </button>
                        <button wire:click="kickoffFairCatch"
                                class="px-3 py-1 rounded bg-gray-700 hover:bg-gray-600">
                            No Return / Fair Catch
                        </button>

                        <button wire:click="kickoffTouchback"
                                class="px-3 py-1 rounded bg-yellow-700 hover:bg-yellow-600">
                            Touchback
                        </button>





                    </div>
                @else
                    <div class="text-xs opacity-70">
                        After recording the kick, enter the return yards.
                    </div>
                @endif

                @error('kick_yards') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
                @error('return_yards') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
            </div>
        @endif
        @if($game->phase === 'PUNT')
            <div class="p-4 rounded bg-gray-800 border border-gray-700 space-y-3">
                <div class="font-semibold">Punt</div>

                <div class="text-sm opacity-80">
                    Punting team: <span class="font-semibold">{{ $game->punt_team ?? $game->possession }}</span>
                </div>

                <div class="flex flex-wrap gap-2 items-center">
                    <label class="text-sm opacity-80">Punt yards</label>
                    <input type="number" min="0" max="99" wire:model="punt_yards"
                           class="w-24 bg-gray-900 border border-gray-700 rounded px-2 py-1" />

                    <button wire:click="recordPunt"
                            class="px-3 py-1 rounded bg-blue-600 hover:bg-blue-500">
                        Record Punt
                    </button>

                    <button
                        type="button"
                        wire:click="cancelPunt"
                        class="px-3 py-1 rounded bg-gray-700 hover:bg-gray-600 border border-white/10"
                    >
                        Cancel
                    </button>

                </div>

                @if($punt_recorded)
                    <div class="flex flex-wrap gap-2 items-center pt-2 border-t border-gray-700">
                        <label class="text-sm opacity-80">Return yards</label>
                        <input type="number" min="-20" max="99" wire:model="punt_return_yards"
                               class="w-24 bg-gray-900 border border-gray-700 rounded px-2 py-1" />

                        <button wire:click="recordPuntReturnWithReason(null)"
                                class="px-3 py-1 rounded bg-green-600 hover:bg-green-500">
                            Record Return
                        </button>

                        <button wire:click="puntFairCatch"
                                class="px-3 py-1 rounded bg-gray-700 hover:bg-gray-600">
                            Fair Catch
                        </button>

                        <button wire:click="puntDowned"
                                class="px-3 py-1 rounded bg-blue-700 hover:bg-blue-600">
                            Downed Punt
                        </button>

                        <button wire:click="puntTouchback"
                                class="px-3 py-1 rounded bg-yellow-700 hover:bg-yellow-600">
                            Touchback
                        </button>



                    </div>
                @endif

                @error('punt_yards') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
                @error('punt_return_yards') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
            </div>
        @endif




    {{-- Play log --}}
    <div class="p-4 rounded bg-gray-800 border border-gray-700">
        <div class="font-semibold mb-2">Play-by-Play</div>

        <div class="space-y-2 h-110 overflow-y-auto pr-1">
            @forelse($plays as $p)
                <div class="text-sm border-b border-gray-700 pb-2">
                    <div class="opacity-80">
                        #{{ $p->seq }}
                        — {{ strtoupper($p->possessionBeforeTeam()->name) }}
                        — {{ $p->side_before }} {{ $p->yardline_before }}
                        — {{ $p->down_before }}&amp;{{ $p->togo_before }}
                    </div>
                    <div class="font-medium">{{ $p->summary }}</div>
                    <div class="opacity-80">
                        After: {{ strtoupper($p->possessionAfterTeam()->name) }}
                        } — {{ $p->side_after }} {{ $p->yardline_after }}
                        — {{ $p->down_after }}&amp;{{ $p->togo_after }}
                    </div>
                </div>
            @empty
                <div class="text-sm opacity-80">No plays yet.</div>
            @endforelse
        </div>
    </div>


            <div class="p-4 rounded bg-gray-800 border border-gray-700 space-y-4">
                <div class="flex justify-between">
                    <div class="font-semibold">Game Controls</div>
                    <div class="font-semibold cursor-pointer text-sm" onclick="openPlayCard('offense')" >Offense Plays</div>
                    <div class="font-semibold cursor-pointer text-sm" onclick="openPlayCard('defense')" >Defense Plays</div>
                    <div class="font-semibold cursor-pointer text-sm" onclick="openPlayCard('coach')" >Coach</div>
                    <div class="font-semibold cursor-pointer text-sm" onclick="openDice()" >Dice</div>
                </div>

                {{-- Spot (Kickoff / Spot) --}}
                <div class="flex flex-wrap gap-2 items-end">
                    <div class="text-xs text-white/70 w-full -mb-1">Spot</div>

                    <select wire:model="kick_side" class="bg-gray-900 border border-gray-700 rounded px-2 py-1">
                        <option value="OWN">OWN</option>
                        <option value="OPP">OPP</option>
                    </select>

                    <input type="number" min="1" max="50" wire:model="kick_yardline"
                           class="w-24 bg-gray-900 border border-gray-700 rounded px-2 py-1" />

                    <button type="button" wire:click="setKickoffSpot"
                            class="px-3 py-1 rounded bg-blue-600 hover:bg-blue-500">
                        Set Spot
                    </button>
                </div>

                @error('kick_side') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
                @error('kick_yardline') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror

                <div class="h-px bg-white/10"></div>

                {{-- Down & Distance --}}
                <div class="flex flex-wrap gap-2 items-end">
                    <div class="text-xs text-white/70 w-full -mb-1">Down &amp; Distance</div>

                    <input type="number" min="1" max="4" wire:model.defer="edit_down"
                           class="w-20 bg-gray-900 border border-gray-700 rounded px-2 py-1"
                           placeholder="Down" />

                    <input type="number" min="1" max="99" wire:model.defer="edit_to_go"
                           class="w-24 bg-gray-900 border border-gray-700 rounded px-2 py-1"
                           placeholder="To Go" />

                    <button type="button" wire:click="setDownAndDistance"
                            class="px-3 py-1 rounded bg-blue-600 hover:bg-blue-500">
                        Save
                    </button>
                </div>

                @error('edit_down') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
                @error('edit_to_go') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror

                <div class="h-px bg-white/10"></div>

                {{-- Clock --}}
                <div class="flex flex-wrap gap-2 items-end">
                    <div class="text-xs text-white/70 w-full -mb-1">Clock</div>

                    <input type="number" min="0" max="15" wire:model.defer="edit_clock_min"
                           class="w-20 bg-gray-900 border border-gray-700 rounded px-2 py-1"
                           placeholder="Min" />

                    <input type="number" min="0" max="59" wire:model.defer="edit_clock_sec"
                           class="w-20 bg-gray-900 border border-gray-700 rounded px-2 py-1"
                           placeholder="Sec" />

                    <button type="button" wire:click="setClock"
                            class="px-3 py-1 rounded bg-blue-600 hover:bg-blue-500">
                        Set Clock
                    </button>

                    <div class="text-xs text-white/60">
                        Current: <span class="font-semibold text-white/80">{{ $this->clockDisplay }}</span>
                    </div>
                </div>

                @error('edit_clock_min') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
                @error('edit_clock_sec') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
            </div>


            {{--            stats--}}
            @php($stats = $this->stats)

            <div class="col-span-3 p-4 rounded bg-gray-800 border border-gray-700 space-y-4">
                <div class="font-semibold">Game Stats (Quick)</div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div class="rounded border border-white/10 p-3 bg-black/20">
                        <div class="font-semibold mb-2">{{ $game->home_label }} (HOME)</div>
                        <div>Pass: {{ $stats['teams']['HOME']['passing']['cmp'] }}/{{ $stats['teams']['HOME']['passing']['att'] }} ({{ $stats['teams']['HOME']['passing']['yds'] }} yds), INT {{ $stats['teams']['HOME']['passing']['int'] }}</div>
                        <div>Rush: {{ $stats['teams']['HOME']['rushing']['att'] }} att ({{ $stats['teams']['HOME']['rushing']['yds'] }} yds)</div>
                        <div>DEF: Tkl {{ $stats['teams']['HOME']['defense']['tkl'] }}, Sacks {{ $stats['teams']['HOME']['defense']['sacks'] }}, INT {{ $stats['teams']['HOME']['defense']['int'] }}</div>
                    </div>

                    <div class="rounded border border-white/10 p-3 bg-black/20">
                        <div class="font-semibold mb-2">{{ $game->away_label }} (AWAY)</div>
                        <div>Pass: {{ $stats['teams']['AWAY']['passing']['cmp'] }}/{{ $stats['teams']['AWAY']['passing']['att'] }} ({{ $stats['teams']['AWAY']['passing']['yds'] }} yds), INT {{ $stats['teams']['AWAY']['passing']['int'] }}</div>
                        <div>Rush: {{ $stats['teams']['AWAY']['rushing']['att'] }} att ({{ $stats['teams']['AWAY']['rushing']['yds'] }} yds)</div>
                        <div>DEF: Tkl {{ $stats['teams']['AWAY']['defense']['tkl'] }}, Sacks {{ $stats['teams']['AWAY']['defense']['sacks'] }}, INT {{ $stats['teams']['AWAY']['defense']['int'] }}</div>
                    </div>
                </div>

                <div class="overflow-x-auto rounded border border-white/10">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/5 text-gray-300">
                        <tr>
                            <th class="text-left px-3 py-2">Side</th>
                            <th class="text-left px-3 py-2">#</th>
                            <th class="text-left px-3 py-2">Player</th>
                            <th class="text-center px-3 py-2">Pass</th>
                            <th class="text-center px-3 py-2">Rush</th>
                            <th class="text-center px-3 py-2">Rec</th>
                            <th class="text-center px-3 py-2">Tkl</th>
                            <th class="text-center px-3 py-2">Sacks</th>
                            <th class="text-center px-3 py-2">INT</th>
                            <th class="text-center px-3 py-2">FumRec</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                        @foreach($stats['players'] as $row)
                            @php($s = $row['stats'])
                            <tr class="bg-gray-950">
                                <td class="px-3 py-2">
                                    {{ $row['side'] === 'HOME'
                                        ? $game->homeTeam->name
                                        : $game->awayTeam->name }}
                                </td>
                                <td class="px-3 py-2 font-semibold">{{ $row['jersey'] }}</td>
                                <td class="px-3 py-2">{{ $row['name'] }} <span class="text-xs text-gray-400">({{ $row['pos'] }})</span></td>

                                <td class="text-center px-3 py-2">
                                    {{ $s['passing']['cmp'] }}/{{ $s['passing']['att'] }} ({{ $s['passing']['yds'] }}), TD {{ $s['passing']['td'] }} INT {{ $s['passing']['int'] }}
                                </td>
                                <td class="text-center px-3 py-2">
                                    {{ $s['rushing']['att'] }} ({{ $s['rushing']['yds'] }}) {{ $s['rushing']['td'] > 0 ? ', '.$s['rushing']['td'] . ' TD' : '' }}
                                </td>
                                <td class="text-center px-3 py-2">
                                    {{ $s['receiving']['rec'] }} ({{ $s['receiving']['yds'] }}) {{ $s['receiving']['td'] > 0 ? ', '.$s['receiving']['td'] . ' TD' : '' }}
                                </td>
                                <td class="text-center px-3 py-2">{{ $s['defense']['tkl'] }}</td>
                                <td class="text-center px-3 py-2">{{ $s['defense']['sacks'] }}</td>
                                <td class="text-center px-3 py-2">{{ $s['defense']['int'] }}</td>
                                <td class="text-center px-3 py-2">{{ $s['defense']['fum_rec'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>



    </div>
    {{-- Period Alert Modal --}}
    <div
        x-data="{ open:false, message:'', timer:null }"
        x-init="
        window.addEventListener('period-ended', (e) => {
            message = (e.detail?.message) ?? 'Update';
            open = true;

{{--            // Auto-close after 3 seconds (optional)--}}
{{--            if (timer) clearTimeout(timer);--}}
{{--            timer = setTimeout(() => { open = false; }, 3000);--}}
        });
    "
        x-cloak
    >
        {{-- Backdrop --}}
        <div
            x-show="open"
            x-transition.opacity
            class="fixed inset-0 z-[9998] bg-black/60"
            @click="open = false"
        ></div>

        {{-- Modal --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95 translate-y-2"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-95 translate-y-2"
            class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
            @keydown.escape.window="open = false"
        >
            <div class="w-full max-w-md rounded-xl border border-white/10 bg-gray-900 text-white shadow-2xl">
                <div class="flex items-start gap-3 p-4 border-b border-white/10">
                    <div class="mt-1 h-3 w-3 rounded-full bg-yellow-400"></div>

                    <div class="flex-1">
                        <div class="text-base font-semibold">Period Alert</div>
                        <div class="text-sm text-white/80 mt-1" x-text="message"></div>
                    </div>

                    <button
                        type="button"
                        class="text-white/60 hover:text-white px-2"
                        @click="open = false"
                        aria-label="Close"
                    >
                        ✕
                    </button>
                </div>

                <div class="p-4 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        class="px-3 py-1.5 rounded-md bg-white/10 hover:bg-white/15 border border-white/10"
                        @click="open = false"
                    >
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('playKeys', () => ({
                init() {
                    // Refocus Result after Livewire updates (submit play, etc.)
                    Livewire.hook('message.processed', () => {
                        this.$nextTick(() => {
                            if (this.$refs?.result) this.$refs.result.focus();
                        });
                    });

                    Livewire.on('focus-result', () => {
                        this.$nextTick(() => {
                            if (this.$refs?.result) this.$refs.result.focus();
                        });
                    });


                    // Initial focus
                    this.$nextTick(() => {
                        if (this.$refs?.result) this.$refs.result.focus();
                    });
                },

                setPlay(type, focus = 'yards', selectYards = true) {
                    this.$wire.set('play_type', type);

                    this.$nextTick(() => {
                        if (focus === 'yards' && this.$refs?.yards) {
                            this.$refs.yards.focus();
                            if (selectYards) this.$refs.yards.select();
                        } else if (focus === 'result' && this.$refs?.result) {
                            this.$refs.result.focus();
                        }
                    });
                },

                onKey(e) {
                    const key = (e.key || '').toLowerCase();
                    const isShift = !!e.shiftKey;

                    // If a modal is open (your Period Alert), don't steal keys.
                    // Optional: if you add x-data state, you can check it; otherwise skip this.

                    // We only want to ignore keystrokes while typing NOTES,
                    // but we DO want Shift+R to work even in inputs like yards.
                    const t = e.target;
                    const tag = (t?.tagName || '').toLowerCase();
                    const isTypingNotes = tag === 'input' && (t.type === 'text' || t.type === 'search') || tag === 'textarea';

                    // Allow Shift+R everywhere (including yards input).
                    if (isShift && key === 'r') {
                        e.preventDefault();
                        this.setPlay('RUN', 'yards', true);
                        return;
                    }
                    if (isShift && key === 'p') {
                        e.preventDefault();
                        this.setPlay('PASS', 'yards', true);
                        return;
                    }
                    if (isShift && key === 'i') {
                        e.preventDefault();
                        this.setPlay('INCOMPLETE', 'yards', true);
                        return;
                    }
                    if (isShift && key === 's') {
                        e.preventDefault();
                        this.setPlay('SACK', 'yards', true);
                        return;
                    }

                    // If typing in notes, don't hijack normal letters.
                    if (isTypingNotes) return;

                    // If user is in the yards input, we typically should NOT steal plain keys
                    // (so they can type negative numbers, etc). But hotkeys are still OK if you want.
                    const isYardsInput = tag === 'input' && (t.type === 'number');

                    // If you want hotkeys to work even in yards input, remove this guard.
                    // Keeping it means: while in yards, only Shift+R is global.
                    // if (isYardsInput) return;
                    // Hotkeys (plain letters)
                    switch (key) {
                        case 'r': // Run
                            e.preventDefault();
                            this.setPlay('RUN', 'yards', true);
                            return;

                        case 'p': // Pass complete
                            e.preventDefault();
                            this.setPlay('PASS', 'yards', true);
                            return;

                        case 'i': // Incomplete
                            e.preventDefault();
                            this.setPlay('INCOMPLETE', 'result', false); // yards forced to 0 by backend anyway
                            return;

                        case 's': // Sack
                            e.preventDefault();
                            this.setPlay('SACK', 'yards', true);
                            return;

                        case 'n': // peNaltry (since P is pass)
                            e.preventDefault();
                            this.setPlay('PENALTY', 'yards', true);
                            return;

                        case 'f': // Fumble
                            e.preventDefault();
                            this.setPlay('FUMBLE', 'result', false);
                            return;

                        case 't': // inTerception (since I is incomplete)
                            e.preventDefault();
                            this.setPlay('INT', 'result', false);
                            return;

                        case 'u': // pUnt
                            e.preventDefault();
                            this.setPlay('PUNT', 'result', false);
                            return;

                        case 'k': // K ickoff
                            e.preventDefault();
                            this.setPlay('KICKOFF', 'result', false);
                            return;
                    }
                },
            }));
        });
    </script>


    <script>
        function openTeamSheet(teamId, label) {
            new WinBox({
                id: 'team-sheet-' + teamId,
                title: label + ' — Team Sheet',
                url: `/teams/${teamId}/sheet`, // your existing team sheet route
                width: 1000,
                height: 700,
                x: 'center',
                y: 'center',
                // background: '#111827',

                index: 100000,
            });
        }

        function openPlayCard(cardType) {

            x = 'center';
            y = 'center';
            if (cardType === 'offense') {
                x = 0;
                y = 0;
            }
            if (cardType === 'defense') {
                x = window.innerWidth - 410;
                y = 0;
            }

            new WinBox({
                id: 'play-card-' + cardType,
                title: cardType.toUpperCase() + ' Play Card',
                url: `/game-cards/${cardType}`, // your existing team sheet route
                width: 400,
                height: 700,
                x: x,
                y: y,
                // background: '#111827',

                index: 100000,
            });
        }

        function openAdvanced(cardType) {

            x = 'center';
            y = 'center';
            if (cardType === 'offense') {
                x = 0;
                y = 0;
            }
            if (cardType === 'defense') {
                x = window.innerWidth - 410;
                y = 0;
            }

            new WinBox({
                id: 'play-card-' + cardType,
                title: cardType.toUpperCase() + ' Play Card',
                url: `/game-cards/${cardType}`, // your existing team sheet route
                width: 800,
                height: 700,
                x: x,
                y: y,
                // background: '#111827',

                index: 100000,
            });
        }

        function openDice() {
            new WinBox({
                id: 'dice',
                title: 'Game Dice',
                url: `/games/{{ $game->id }}/dice`, // your existing team sheet route
                width: 500,
                height: 800,
                x: 'center',
                y: 'center',
                // background: '#111827',

                index: 100000,
            });
        }


        function openPdfLibrary() {
            new WinBox({
                id: 'dice',
                title: 'Game Dice',
                url: "{{ route('pdf.library') }}",
                width: 900,
                height: 800,
                x: 'center',
                y: 0,
                // background: '#111827',

                index: 100000,
            });
        }



    </script>





</div>
