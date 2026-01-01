<div class="p-4 space-y-4 text-gray-100">

    <div class="flex items-center gap-6 text-white/90">
        <div class="font-semibold">Q{{ $game->quarter }} — {{ $this->clockDisplay }}</div>
    </div>

    <table class="w-full mt-3 text-sm text-white/80">
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


    <div class="flex flex-wrap gap-4 items-center">
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
            <div class="font-semibold">{{ $game->away_score }} {{ $game->away_name }} - {{ $game->home_name }} {{ $game->home_score }}</div>
        </div>
    </div>




    {{-- NEW Field --}}
    @php
        // Total visual width = 120 yards (10 EZ + 100 field + 10 EZ)
        $endZonePct = 10 / 120 * 100;      // 8.3333%
        $fieldPct   = 100 / 120 * 100;     // 83.3333%

        // Ball position is 0..100 from HOME goal line, but field of play starts after left end zone
        $ballLeft = $endZonePct + ($this->absFromHome / 100) * $fieldPct;

        // Yard labels at 10..90 (we’ll show 10..50..10)
        $yardMarks = [
            10 => 10, 20 => 20, 30 => 30, 40 => 40, 50 => 50,
            60 => 40, 70 => 30, 80 => 20, 90 => 10,
        ];
    @endphp

    <div class="w-full">
        <div class="relative w-full h-48 md:h-56 rounded-lg overflow-hidden border border-gray-700">

            {{-- LEFT END ZONE (10 yards) --}}
            <div class="absolute inset-y-0 left-0"
                 style="width: {{ $endZonePct }}%; background: rgba(30,58,138,0.70);">
            </div>

            {{-- RIGHT END ZONE (10 yards) --}}
            <div class="absolute inset-y-0 right-0"
                 style="width: {{ $endZonePct }}%; background: rgba(153,27,27,0.70);">
            </div>

            {{-- FIELD OF PLAY (100 yards) --}}
            <div class="absolute inset-y-0"
                 style="left: {{ $endZonePct }}%; width: {{ $fieldPct }}%;
            background: linear-gradient(to bottom, #14532d, #14532d);">
            </div>


            {{-- 10-yard thick lines across FIELD OF PLAY only --}}
            @for($i = 0; $i <= 20; $i++)
                <div class="absolute top-0 bottom-0"
                     style="
            left: calc({{ $endZonePct }}% + ({{ $i }} * {{ $fieldPct / 20 }}%));
            width: {{ $i % 2 === 0 ? '2px' : '2px' }};
            background: rgba(255,255,255,0.35);
         ">
                </div>
            @endfor


            {{-- Middle hash marks (two rows) --}}
            <div class="absolute"
                 style="
                left: {{ $endZonePct }}%;
                width: {{ $fieldPct }}%;
                top: 48%;
                height: 6px;
                background: repeating-linear-gradient(
                    to right,
                    rgba(255,255,255,0.45) 0,
                    rgba(255,255,255,0.45) 2px,
                    rgba(0,0,0,0) 2px,
                    rgba(0,0,0,0) 1%
                );
                opacity: 0.9;
             ">
            </div>

            <div class="absolute"
                 style="
                left: {{ $endZonePct }}%;
                width: {{ $fieldPct }}%;
                top: 52%;
                height: 6px;
                background: repeating-linear-gradient(
                    to right,
                    rgba(255,255,255,0.45) 0,
                    rgba(255,255,255,0.45) 2px,
                    rgba(0,0,0,0) 2px,
                    rgba(0,0,0,0) 1%
                );
                opacity: 0.9;
             ">
            </div>


            {{-- Sideline hash marks (near top) --}}
            <div class="absolute"
                 style="
        left: {{ $this->endZonePct }}%;
        width: {{ $this->fieldPct }}%;
        top: 18%;
        height: 6px;
        background: repeating-linear-gradient(
            to right,
            rgba(255,255,255,0.40) 0,
            rgba(255,255,255,0.40) 10px,
            rgba(0,0,0,0) 10px,
            rgba(0,0,0,0) 24px
        );
        opacity: 0.9;
     ">
            </div>

            {{-- Sideline hash marks (near bottom) --}}
            <div class="absolute"
                 style="
        left: {{ $this->endZonePct }}%;
        width: {{ $this->fieldPct }}%;
        top: 82%;
        height: 6px;
        background: repeating-linear-gradient(
            to right,
            rgba(255,255,255,0.40) 0,
            rgba(255,255,255,0.40) 10px,
            rgba(0,0,0,0) 10px,
            rgba(0,0,0,0) 24px
        );
        opacity: 0.9;
     ">
            </div>

            @php
                $goalLeft  = $this->endZonePct;
                $goalRight = $this->endZonePct + $this->fieldPct;
            @endphp

            {{-- Left goal line pylons --}}
            <div class="absolute w-2 h-2 rounded-sm"
                 style="left: calc({{ $goalLeft }}% - 4px); top: 6px; background: rgba(255,140,0,0.95);"></div>
            <div class="absolute w-2 h-2 rounded-sm"
                 style="left: calc({{ $goalLeft }}% - 4px); bottom: 6px; background: rgba(255,140,0,0.95);"></div>

            {{-- Right goal line pylons --}}
            <div class="absolute w-2 h-2 rounded-sm"
                 style="left: calc({{ $goalRight }}% - 4px); top: 6px; background: rgba(255,140,0,0.95);"></div>
            <div class="absolute w-2 h-2 rounded-sm"
                 style="left: calc({{ $goalRight }}% - 4px); bottom: 6px; background: rgba(255,140,0,0.95);"></div>




            {{-- Yard numbers that STRADDLE the 10-yard line (digit on each side) --}}
            @foreach($yardMarks as $yard => $label)
                @php
                    // Convert yard (10..90) to % across the 100-yard field-of-play
                    $x = $endZonePct + ($yard / 100) * $fieldPct;
                    $digits = str_split((string)$label);
                    $leftDigit = $digits[0] ?? '';
                    $rightDigit = $digits[1] ?? '';
                @endphp

                <div class="absolute top-2 text-white/70 text-xs md:text-sm font-semibold select-none"
                     style="left: {{ $x }}%; transform: translateX(-50%);">
                    <div class="flex items-center gap-2">
                        <span>{{ $leftDigit }}</span>
                        <span class="opacity-0">|</span> {{-- spacing only; line is behind --}}
                        <span>{{ $rightDigit }}</span>
                    </div>
                </div>
            @endforeach

            {{-- Football marker (positioned on 120-yard graphic) --}}
            <div class="absolute top-1/2 -translate-y-1/2 transition-all duration-500 ease-in-out"
                 style="left: calc({{ $this->ballLeft120 }}% - 10px); z-index: 20;">
                <div class="flex items-center gap-2">
                    <div class="w-5 h-3 rounded-full border border-white/60"
                         style="background:#8b4513;">
                    </div>

                    <div class="text-xs text-white/80 font-semibold">
                        @if($game->possession === 'HOME') → @else ← @endif
                    </div>
                </div>
            </div>


            @if($this->showLineToGain)
                <div class="absolute top-0 bottom-0 pointer-events-none"
                     style="
            left: {{ $this->lineToGainLeft120 }}%;
            width: 3px;
            background: rgba(255, 215, 0, 0.55);
            box-shadow: 0 0 10px rgba(255, 215, 0, 0.35);
            z-index: 15;
         ">
                </div>

                <div class="absolute pointer-events-none text-[10px] font-semibold px-2 py-0.5 rounded"
                     style="
            left: calc({{ $this->lineToGainLeft120 }}% + 6px);
            top: 8px;
            background: rgba(255, 215, 0, 0.35);
            color: rgba(255,255,255,0.9);
            z-index: 16;
         ">
                    1st
                </div>

                @if($this->seriesLeft120 !== null)
                    <div class="absolute pointer-events-none" style=" left: calc({{ $this->seriesLeft120 }}% - 18px); bottom: 8px; z-index: 16; ">
                        <div class="text-[10px] font-semibold px-2 py-0.5 rounded border border-white/20"
                             style="background: rgba(0,0,0,0.35); color: rgba(255,255,255,0.85);">
                            <div class="mx-auto mb-1" style="width:2px; height:10px; background: rgba(255,255,255,0.45);"></div>
                            S
                        </div>
                    </div>
                @endif


                {{-- Current ball marker with down number (same row as 1st) --}}
                <div class="absolute pointer-events-none"
                     style=" left: calc({{ $this->ballSideMarkerLeft120 }}% - 10px); top: 8px; z-index: 17; ">
                    <div class="text-[10px] font-semibold w-6 text-center py-0.5 rounded border border-white/20"
                         style="background: rgba(0,0,0,0.35); color: rgba(255,255,255,0.85);">
{{--                         style="background: rgba(255,255,255,0.12); color: rgba(255,255,255,0.92);">--}}
                        {{ $this->downLabel }}
                        <div class="mx-auto mt-1" style="width:2px; height:10px; background: rgba(255,255,255,0.45);"></div>
                    </div>
                </div>




            @endif

            {{-- Optional spot text --}}
            <div class="absolute bottom-2 left-2 text-xs text-white/80 bg-black/40 px-2 py-1 rounded">
                {{ $game->possession }} ball — {{ $game->pos_side }} {{ (int)$game->pos_yardline }}
                (abs from HOME: {{ $this->absFromHome }})
            </div>

        </div>
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

                <button type="submit"
                        class="px-3 py-1 rounded bg-green-600 hover:bg-green-500">
                    Add
                </button>
            </div>

            @error('play_type') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
            @error('play_yards') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
            @error('play_note') <div class="text-red-300 text-sm">{{ $message }}</div> @enderror
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
                        — {{ $p->possession_before }}
                        — {{ $p->side_before }} {{ $p->yardline_before }}
                        — {{ $p->down_before }}&amp;{{ $p->togo_before }}
                    </div>
                    <div class="font-medium">{{ $p->summary }}</div>
                    <div class="opacity-80">
                        After: {{ $p->possession_after }} — {{ $p->side_after }} {{ $p->yardline_after }}
                        — {{ $p->down_after }}&amp;{{ $p->togo_after }}
                    </div>
                </div>
            @empty
                <div class="text-sm opacity-80">No plays yet.</div>
            @endforelse
        </div>
    </div>


            <div class="p-4 rounded bg-gray-800 border border-gray-700 space-y-4">
                <div class="font-semibold">Game Controls</div>

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





</div>
