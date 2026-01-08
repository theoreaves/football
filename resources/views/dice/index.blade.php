{{-- resources/views/dice/roller.blade.php --}}
<x-layouts.app>
    {{-- resources/views/dice/index.blade.php (or wherever you render it) --}}
    <div
        x-data="diceRoller({
        diceOnly: @js($diceOnly),
        resolveUrl: @js(route('dice.resolve', $game)),
        phase: @js($game->phase),
        csrf: @js(csrf_token()),
        playType: @js($game->phase),
        allowedPlayTypes: [
            'NORMAL','KICKOFF','PUNT-START','PUNT','TRY',
            'FUMBLE-HAPPENED','FUMBLE','INT-HAPPENED','INT','BREAKAWAY'
        ]
    })"
        x-init="init()"
        class="p-6 text-gray-100 select-none"
    >


        <div class="flex items-start justify-between gap-6">
            <!-- TOP-LEFT crop -->
            <div class="w-[173px] h-[117px] overflow-hidden relative">
                <img
                    src="{{ asset($game->homeTeam->jersey_image_dark) }}"
                    alt=""
                    class="block max-w-none absolute top-0 left-0"
                >
            </div>


            <!-- TOP-RIGHT crop -->
            <div class="w-[173px] h-[117px] overflow-hidden relative">
                <img
                    src="{{ asset($game->awayTeam->jersey_image_white) }}"
                    alt=""
                    class="block max-w-none absolute top-0 right-0"
                >
            </div>

        </div>

            <div class="flex items-start justify-between gap-6">
            <br>
            Possession: {{ $possessionTeam->name }}
            {{ $game->down }} & {{ $game->to_go }} at {{ $game->pos_side }} {{ $game->pos_yardline }} yard line
            <BR>
            {{ $game->phase }}
        </div>
{{--        <div--}}
{{--            x-show="!diceOnly"--}}
{{--            x-data="{--}}
{{--        playType: 'NORMAL',--}}
{{--        init() {--}}
{{--            const phase = @js($game->phase);--}}
{{--            const allowed = [--}}
{{--                'NORMAL','KICKOFF','PUNT-START','PUNT','TRY',--}}
{{--                'FUMBLE-HAPPENED','FUMBLE','INT-HAPPENED','INT','BREAKAWAY'--}}
{{--            ];--}}
{{--  this.playType = this.allowedPlayTypes.includes(this.phase) ? this.phase : 'NORMAL';--}}

{{--  window.addEventListener('keydown', (e) => {--}}
{{--    const key = (e.key || '').toLowerCase();--}}
{{--    const tag = (e.target?.tagName || '').toLowerCase();--}}
{{--    const typing = tag === 'input' || tag === 'textarea' || tag === 'select';--}}
{{--    if (typing) return;--}}

{{--    if (key === 'r') {--}}
{{--      e.preventDefault();--}}
{{--      this.rollAll();--}}
{{--    }--}}
{{--  });--}}
{{--        }--}}
{{--    }"--}}
{{--            x-init="init()"--}}
{{--            class="space-y-4"--}}
{{--        >--}}
            <div x-show="!diceOnly" class="space-y-4">


            {{-- Top empty div: radio buttons --}}
                <div class="flex flex-wrap gap-3 text-sm font-semibold">

                    <!-- Scrimmage -->
                    <label class="group relative inline-flex items-center cursor-pointer select-none">
                        <input type="radio" x-model="playType" value="NORMAL" class="sr-only peer">
                        <span
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/15 bg-white/5
             peer-checked:bg-white/15 peer-checked:border-white/30 hover:bg-white/10 transition"
                            aria-hidden="true"
                        >
      <!-- football / scrimmage -->
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 15c4 4 10 4 14 0s4-10 0-14c-4-4-10-4-14 0S1 11 5 15Z"/>
        <path d="M9 9h6"/>
        <path d="M10 7v4"/>
        <path d="M12 7v4"/>
        <path d="M14 7v4"/>
      </svg>
    </span>

                        <!-- tooltip -->
                        <span class="pointer-events-none absolute -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap
                 rounded bg-black/80 px-2 py-1 text-xs font-medium text-white opacity-0
                 group-hover:opacity-100 transition">
      Scrimmage
    </span>
                    </label>

                    <!-- Kickoffs -->
                    <label class="group relative inline-flex items-center cursor-pointer select-none">
                        <input type="radio" x-model="playType" value="KICKOFF" class="sr-only peer">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/15 bg-white/5
                 peer-checked:bg-white/15 peer-checked:border-white/30 hover:bg-white/10 transition"
                              aria-hidden="true">
      <!-- tee + ball -->
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 3c-2 1-3 2.5-3 4s1 3 3 4c2-1 3-2.5 3-4s-1-3-3-4Z"/>
        <path d="M8 20h8"/>
        <path d="M10 20v-5h4v5"/>
        <path d="M12 11v4"/>
      </svg>
    </span>
                        <span class="pointer-events-none absolute -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap
                 rounded bg-black/80 px-2 py-1 text-xs font-medium text-white opacity-0
                 group-hover:opacity-100 transition">
      Kickoffs
    </span>
                    </label>

                    <!-- Punts (start) -->
                    <label class="group relative inline-flex items-center cursor-pointer select-none">
                        <input type="radio" x-model="playType" value="PUNT-START" class="sr-only peer">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/15 bg-white/5
                 peer-checked:bg-white/15 peer-checked:border-white/30 hover:bg-white/10 transition"
                              aria-hidden="true">
      <!-- foot kicking -->
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 3c1.5 0 2.5 1 2.5 2.5S15.5 8 14 8s-2.5-1-2.5-2.5S12.5 3 14 3Z"/>
        <path d="M7 21l4-7 4 2 2 5"/>
        <path d="M9 10l3 2 2-2"/>
        <path d="M3 14h4"/>
      </svg>
    </span>
                        <span class="pointer-events-none absolute -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap
                 rounded bg-black/80 px-2 py-1 text-xs font-medium text-white opacity-0
                 group-hover:opacity-100 transition">
      Punts
    </span>
                    </label>

                    <!-- Punt Returns -->
                    <label class="group relative inline-flex items-center cursor-pointer select-none">
                        <input type="radio" x-model="playType" value="PUNT" class="sr-only peer">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/15 bg-white/5
                 peer-checked:bg-white/15 peer-checked:border-white/30 hover:bg-white/10 transition"
                              aria-hidden="true">
      <!-- return arrow -->
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 6H9a5 5 0 0 0 0 10h10"/>
        <path d="M14 9l-3-3 3-3"/>
      </svg>
    </span>
                        <span class="pointer-events-none absolute -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap
                 rounded bg-black/80 px-2 py-1 text-xs font-medium text-white opacity-0
                 group-hover:opacity-100 transition">
      Punt Returns
    </span>
                    </label>

                    <!-- Field Goals & PATs -->
                    <label class="group relative inline-flex items-center cursor-pointer select-none">
                        <input type="radio" x-model="playType" value="TRY" class="sr-only peer">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/15 bg-white/5
                 peer-checked:bg-white/15 peer-checked:border-white/30 hover:bg-white/10 transition"
                              aria-hidden="true">
      <!-- uprights -->
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M6 4v6h12V4"/>
        <path d="M12 10v10"/>
        <path d="M8 20h8"/>
      </svg>
    </span>
                        <span class="pointer-events-none absolute -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap
                 rounded bg-black/80 px-2 py-1 text-xs font-medium text-white opacity-0
                 group-hover:opacity-100 transition">
      Field Goals &amp; PATs
    </span>
                    </label>

                    <!-- Fumble -->
                    <label class="group relative inline-flex items-center cursor-pointer select-none">
                        <input type="radio" x-model="playType" value="FUMBLE-HAPPENED" class="sr-only peer">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/15 bg-white/5
                 peer-checked:bg-white/15 peer-checked:border-white/30 hover:bg-white/10 transition"
                              aria-hidden="true">
      <!-- broken ball / zigzag -->
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M7 16c3 3 7 3 10 0s3-7 0-10S10 3 7 6 4 13 7 16Z"/>
        <path d="M9 7l2 2-2 2 2 2-2 2"/>
      </svg>
    </span>
                        <span class="pointer-events-none absolute -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap
                 rounded bg-black/80 px-2 py-1 text-xs font-medium text-white opacity-0
                 group-hover:opacity-100 transition">
      Fumble
    </span>
                    </label>

                    <!-- Fumble/Interception Returns -->
                    <label class="group relative inline-flex items-center cursor-pointer select-none">
                        <input type="radio" x-model="playType" value="FUMBLE" class="sr-only peer">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/15 bg-white/5
                 peer-checked:bg-white/15 peer-checked:border-white/30 hover:bg-white/10 transition"
                              aria-hidden="true">
      <!-- swap/turnover arrows -->
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M7 7h10l-2-2"/>
        <path d="M17 17H7l2 2"/>
        <path d="M7 7l-2 2"/>
        <path d="M17 17l2-2"/>
      </svg>
    </span>
                        <span class="pointer-events-none absolute -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap
                 rounded bg-black/80 px-2 py-1 text-xs font-medium text-white opacity-0
                 group-hover:opacity-100 transition">
      Fumble/Interception Returns
    </span>
                    </label>

                </div>


                {{-- Second div: only visible for scrimmage --}}
            <div x-show="playType === 'NORMAL'"
                 x-transition>
        <div class="flex gap-4 mt-2">
            <div>
                <label for="offense-play" class="block text-xs text-gray-400 mb-1">Offense Play</label>
                <select id="offense-play" x-model="offensePlay" class="rounded-lg border border-white/10 bg-gray-800 text-gray-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-600">
                    <option value="" disabled selected>Select Offense Play</option>
                    @foreach($offensePlays as $play)
                        <option value="{{ $play->id }}">{{ $play->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="defense-play" class="block text-xs text-gray-400 mb-1">Defense Play</label>
                <select id="defense-play" x-model="defensePlay" class="rounded-lg border border-white/10 bg-gray-800 text-gray-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600">
                    <option value="" disabled selected>Select Defense Play</option>
                    @foreach($defensePlays as $play)
                        <option value="{{ $play->id }}">{{ $play->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

            </div>
        </div>

        <div x-show="!diceOnly" class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
            <div class="flex items-center justify-between">
                <div class="text-xl font-semibold">Results</div>
                <div class="text-sm text-gray-400">
                    <span x-show="resolving" x-cloak>Resolving…</span>
                    <span x-show="!diceOnly && !resolving && resolved" x-cloak
                          class="cursor-pointer"
{{--                          @click="resolved = false"--}}
                        @click="location.reload()"
                    >OK</span>
                </div>
            </div>

            <template x-if="diceOnly">
                <div class="mt-3 text-sm text-gray-400">
                    Dice-only mode enabled.
                </div>
            </template>

            <template x-if="resolveError">
                <ul class="mt-3 text-sm text-red-300 space-y-1">
                    <template
                        x-for="msg in (typeof resolveError === 'string'
                ? [resolveError]
                : Object.values(resolveError).flat())"
                        :key="msg"
                    >
                        <li x-text="msg"></li>
                    </template>
                </ul>
            </template>


            <template x-if="resolved">
    <pre class="mt-3 whitespace-pre-wrap text-sm text-gray-200"
         x-text="
            Object.entries(resolved.play_results ?? resolved)
              .map(([k, v]) => `${k}: ${v}`)
              .join('\n')
         ">
    </pre>
            </template>


            <template x-if="resolved">
                <div x-data="{ show_full_result: false }" class="mt-2">
                <button type="button" @click="show_full_result = !show_full_result"
                        class="text-sm text-blue-400 underline">
                    <span x-show="!show_full_result" x-cloak>Show Full Result</span>
                    <span x-show="show_full_result" x-cloak>Hide Full Result</span>
                </button>
                <div x-show="show_full_result" class="mt-4">
                    <div class="text-sm text-gray-400 mb-2">
                        Full Result:

        <pre class="mt-3 whitespace-pre-wrap text-sm text-gray-200"
             x-text="JSON.stringify(resolved.resolved ?? resolved, null, 2)"></pre>
                    </div>
                </div>
                </div>

            </template>

            <template x-if="!diceOnly && !resolved && !resolveError">
                <div class="mt-3 text-sm text-gray-400">
                    Roll to resolve a play.
                </div>
            </template>
        </div>





        <div class="flex items-start justify-between gap-6">
            <div>
                <div class="text-sm text-gray-400">Game Dice Roller</div>
            </div>

            <div class="text-right text-sm text-gray-300">
                <div>Last Roll: <span class="font-semibold" x-text="lastRollLabel || '—'"></span></div>
                <div>
                    Play Result:
                    <span class="font-semibold" x-text="playResult === null ? '—' : playResult"></span>
                </div>
            </div>
        </div>

        <div class="mt-4 h-px bg-white/10"></div>

        <div class="mt-6 flex items-center gap-3">
            <button
                type="button"
                @click="rollAll()"
                :disabled="rolling"
                class="px-5 py-2 rounded-lg font-semibold border border-white/10
                   bg-green-600 hover:bg-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <span x-show="!rolling">Roll</span>
                <span x-show="rolling" x-cloak>Rolling…</span>
            </button>

            <button
                type="button"
                @click="resetAll()"
                :disabled="rolling"
                class="px-5 py-2 rounded-lg font-semibold border border-white/10
                   bg-gray-700 hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                Reset
            </button>

            <div class="ml-auto text-sm text-gray-400">
                Shortcut: <span class="text-gray-200 font-semibold">R</span> to roll
            </div>
        </div>

        {{-- ROW 1: Result Dice (Red d6 + White d10) + Blue d10 --}}
        <div class="mt-6 rounded-xl border border-white/10 bg-black/20 p-4">
            <div class="flex items-center justify-between">
                <div class="text-xl font-semibold">Result Dice</div>

                <div class="text-sm text-gray-300">
                    Play Result:
                    <span class="font-semibold text-gray-100">
                    <span x-text="red.value ?? '—'"></span>
                    <span class="text-gray-400">+</span>
                    <span x-text="white.value ?? '—'"></span>
                    <span class="text-gray-400">=</span>
                    <span x-text="playResult === null ? '—' : playResult"></span>
                    <span class="mx-2">|</span>
                    Skill: <span class="text-gray-100 font-semibold" x-text="blue.value ?? '—'"></span>
                </span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-4">
                {{-- Red d6 --}}
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between">
                        <div class="font-semibold text-lg">Red</div>
                        <div class="text-sm text-gray-400">1d6</div>
                    </div>

                    <div class="mt-4 flex items-center justify-center">
                        <div
                            class="w-20 h-20 rounded-2xl border border-white/10 shadow-inner
                               flex items-center justify-center text-3xl font-black"
                            :class="rolling ? 'animate-pulse' : ''"
                            style="background: rgba(127,29,29,0.95);"
                        >
                            <span x-text="display(red)"></span>
                        </div>
                    </div>

                    <div class="mt-3 text-center text-sm text-gray-400">Range: 1–6</div>
                </div>

                {{-- White d10 (diamond-ish) --}}
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between">
                        <div class="font-semibold text-lg">White</div>
                        <div class="text-sm text-gray-400">1d10</div>
                    </div>

                    <div class="mt-4 flex items-center justify-center">
                        <div class="relative w-20 h-20 flex items-center justify-center">
                            <div
                                class="absolute inset-0 border border-white/10 shadow-inner"
                                :class="rolling ? 'animate-pulse' : ''"
                                style="background: rgba(255,255,255,0.95); transform: rotate(45deg); border-radius: 18px;"
                            ></div>
                            <div class="relative text-3xl font-black text-gray-900">
                                <span x-text="display(white)"></span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 text-center text-sm text-gray-400">Range: 1–10</div>
                </div>

                {{-- Blue d10 --}}
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between">
                        <div class="font-semibold text-lg">Blue</div>
                        <div class="text-sm text-gray-400">1d10</div>
                    </div>

                    <div class="mt-4 flex items-center justify-center">
                        <div class="relative w-20 h-20 flex items-center justify-center">
                            <div
                                class="absolute inset-0 border border-white/10 shadow-inner"
                                :class="rolling ? 'animate-pulse' : ''"
                                style="background: rgba(37,99,235,0.95); transform: rotate(45deg); border-radius: 18px;"
                            ></div>
                            <div class="relative text-3xl font-black text-white">
                                <span x-text="display(blue)"></span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 text-center text-sm text-gray-400">Range: 1–10</div>
                </div>
            </div>
        </div>

        {{-- ROW 2: Player + Disrupter on same row --}}
        <div class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
            <div class="flex items-center justify-between">
                <div class="text-xl font-semibold">Player, Disrupter & Tackler Dice</div>
                <div class="text-sm text-gray-400">
                    Player: <span class="text-gray-100 font-semibold" x-text="green.value ?? '—'"></span>
                    <span class="mx-2">|</span>
                    Disrupter: <span class="text-gray-100 font-semibold" x-text="orange.value ?? '—'"></span>
                    <span class="mx-2">|</span>
                    Tackler: <span class="text-gray-100 font-semibold" x-text="purple.value ?? '—'"></span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-4">
                {{-- Green d20 (Player) --}}
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between">
                        <div class="font-semibold text-lg">Player</div>
                        <div class="text-sm text-gray-400">1d20</div>
                    </div>

                    <div class="mt-4 flex items-center justify-center">
                        <div
                            class="w-20 h-20 border border-white/10 shadow-inner flex items-center justify-center text-3xl font-black"
                            :class="rolling ? 'animate-pulse' : ''"
                            style="
                            background: rgba(22,163,74,0.95);
                            clip-path: polygon(50% 0%, 85% 12%, 100% 50%, 85% 88%, 50% 100%, 15% 88%, 0% 50%, 15% 12%);
                            border-radius: 12px;
                        "
                        >
                            <span x-text="display(green)"></span>
                        </div>
                    </div>

                    <div class="mt-3 text-center text-sm text-gray-400">Range: 1–20</div>
                </div>

                {{-- Orange d20 (Disrupter) --}}
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between">
                        <div class="font-semibold text-lg">Disrupter</div>
                        <div class="text-sm text-gray-400">1d20</div>
                    </div>

                    <div class="mt-4 flex items-center justify-center">
                        <div
                            class="w-20 h-20 border border-white/10 shadow-inner flex items-center justify-center text-3xl font-black"
                            :class="rolling ? 'animate-pulse' : ''"
                            style="
                            background: rgba(249,115,22,0.95);
                            clip-path: polygon(50% 0%, 85% 12%, 100% 50%, 85% 88%, 50% 100%, 15% 88%, 0% 50%, 15% 12%);
                            border-radius: 12px;
                        "
                        >
                            <span x-text="display(orange)"></span>
                        </div>
                    </div>

                    <div class="mt-3 text-center text-sm text-gray-400">Range: 1–20</div>
                </div>

                {{-- Purple d10 (Tackler) --}}
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between">
                        <div class="font-semibold text-lg">Tackler</div>
                        <div class="text-sm text-gray-400">1d10</div>
                    </div>

                    <div class="mt-4 flex items-center justify-center">
                        <div class="relative w-20 h-20 flex items-center justify-center">
                            <div
                                class="absolute inset-0 border border-white/10 shadow-inner"
                                :class="rolling ? 'animate-pulse' : ''"
                                style="background: rgba(168,85,247,0.95); transform: rotate(45deg); border-radius: 18px;"
                            ></div>
                            <div class="relative text-3xl font-black text-white">
                                <span x-text="display(purple)"></span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 text-center text-sm text-gray-400">Range: 1–10</div>
                </div>
            </div>
        </div>



            <script>
                function diceRoller(opts = {}) {
                return {
                // config
                diceOnly: !!opts.diceOnly,
                resolveUrl: opts.resolveUrl || null,
                csrf: opts.csrf || null,

                    // NEW: phase/playType support
                    phase: opts.phase || 'NORMAL',
                    allowedPlayTypes: Array.isArray(opts.allowedPlayTypes)
                        ? opts.allowedPlayTypes
                        : ['NORMAL','KICKOFF','PUNT-START','PUNT','TRY','FUMBLE-HAPPENED','FUMBLE','INT-HAPPENED','INT','BREAKAWAY'],
                    playType: opts.playType || 'NORMAL',

                // ui state
                rolling: false,
                resolving: false,
                lastRollLabel: '',
                playResult: null,

                resolved: null,
                resolveError: null,

                red:    { sides: 6,  value: null, spin: null },
                white:  { sides: 10, value: null, spin: null }, // 0..9 digit
                blue:   { sides: 10, value: null, spin: null }, // 1..10 skill
                green:  { sides: 20, value: null, spin: null },
                orange: { sides: 20, value: null, spin: null },
                purple: { sides: 10, value: null, spin: null }, // 1..10 tackler

                offensePlay: '',
                defensePlay: '',

                    init() {
                        // Default playType from phase if valid
                        this.playType = this.allowedPlayTypes.includes(this.phase) ? this.phase : 'NORMAL';

                        window.addEventListener('keydown', (e) => {
                            const key = (e.key || '').toLowerCase();
                            const tag = (e.target?.tagName || '').toLowerCase();
                            const typing = tag === 'input' || tag === 'textarea' || tag === 'select';
                            if (typing) return;

                            if (key === 'r') {
                                e.preventDefault();
                                this.rollAll();
                            }
                        });
                    },

                display(die) {
                if (this.rolling) return die.spin ?? '—';
                return die.value ?? '—';
            },

                rand(min, max) {
                return Math.floor(Math.random() * (max - min + 1)) + min;
            },

                finalizeDie(die) { // generic 1..sides
                die.value = this.rand(1, die.sides);
                die.spin = null;
            },

                finalize10Digit(die) { // white only: 0..9
                die.value = this.rand(0, 9);
                die.spin = null;
            },

                finalizeD10(die) { // blue/purple: 1..10
                die.value = this.rand(1, 10);
                die.spin = null;
            },

                computePlayResult() {
                if (this.red.value === null || this.white.value === null) return null;
                return parseInt(String(this.red.value) + String(this.white.value), 10);
            },

                async resolvePlay() {
                this.resolveError = null;
                this.resolved = null;

                if (this.diceOnly) return;
                if (!this.resolveUrl) {
                this.resolveError = 'resolveUrl not set.';
                return;
            }
                // if (!this.offensePlay || !this.defensePlay) {
                // this.resolveError = 'Select offense and defense plays first.';
                // return;
            // }

                // Ensure dice are present
                if ([this.red, this.white, this.blue, this.green, this.orange, this.purple].some(d => d.value === null)) {
                this.resolveError = 'Dice not finalized.';
                return;
            }

                this.resolving = true;

                try {
                const resp = await fetch(this.resolveUrl, {
                method: 'POST',
                headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrf,
                'Accept': 'application/json',
            },
                body: JSON.stringify({
                offense_play_id: this.offensePlay,
                defense_play_id: this.defensePlay,

                    play_type: this.playType, // <-- add this

                red: this.red.value,
                white: this.white.value,
                blue: this.blue.value,
                green: this.green.value,
                orange: this.orange.value,
                purple: this.purple.value,

                // optional if you want to pass this from UI later
                // redzone: false,
            }),
            });

                const json = await resp.json().catch(() => null);

                if (!resp.ok) {
                // Laravel validation errors come back here too
                this.resolveError = json?.message || 'Resolve failed.';
                // if validation payload exists:
                    if (json?.errors) {
                        // Flatten Laravel validation errors into a simple array of messages
                        this.resolveError = Object.values(json.errors).flat();
                        return;
                    }

            }

                this.resolved = json;
            } catch (e) {
                this.resolveError = (e && e.message) ? e.message : 'Resolve failed (network).';
            } finally {
                this.resolving = false;
            }
            },

                rollAll() {
                if (this.rolling || this.resolving) return;

                this.resolveError = null;
                this.resolved = null;

                this.rolling = true;
                const start = Date.now();
                const duration = 2000;
                const tickMs = 90;

                const spinTimer = setInterval(async () => {
                this.red.spin    = this.rand(1, 6);
                this.white.spin  = this.rand(0, 9);

                this.blue.spin   = this.rand(1, 10);
                this.purple.spin = this.rand(1, 10);

                this.green.spin  = this.rand(1, 20);
                this.orange.spin = this.rand(1, 20);

                if (Date.now() - start >= duration) {
                clearInterval(spinTimer);

                this.finalizeDie(this.red);
                this.finalize10Digit(this.white);

                this.finalizeD10(this.blue);
                this.finalizeDie(this.green);
                this.finalizeDie(this.orange);
                this.finalizeD10(this.purple);

                this.playResult = this.computePlayResult();

                this.lastRollLabel = [
                `R:${this.red.value}`,
                `W:${this.white.value}`,
                `B:${this.blue.value}`,
                `G:${this.green.value}`,
                `O:${this.orange.value}`,
                `P:${this.purple.value}`,
                ].join('  ');

                this.rolling = false;

                // Call engine after dice finalize
                await this.resolvePlay();
            }
            }, tickMs);
            },

                resetAll() {
                if (this.rolling || this.resolving) return;

                [this.red, this.white, this.blue, this.green, this.orange, this.purple].forEach(d => {
                d.value = null;
                d.spin = null;
            });

                this.playResult = null;
                this.lastRollLabel = '';
                this.resolveError = null;
                this.resolved = null;
            },
            };
            }





        </script>
    </div>


</x-layouts.app>
