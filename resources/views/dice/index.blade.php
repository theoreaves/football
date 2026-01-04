{{-- resources/views/dice/roller.blade.php --}}
<x-layouts.app>
    {{-- resources/views/dice/index.blade.php (or wherever you render it) --}}
    <div
        x-data="diceRoller({
    diceOnly: @js($diceOnly),
    resolveUrl: @js(route('dice.resolve', $game)),
    csrf: @js(csrf_token())
})"

        x-init="init()"
        class="p-6 text-gray-100 select-none"
    >
{{--    <div--}}
{{--        x-data="diceRoller()"--}}
{{--        x-init="init()"--}}
{{--        class="p-6 text-gray-100 select-none"--}}
{{--    >--}}
        <div class="flex items-start justify-between gap-6">
            {{ $game->homeTeam->name }} vs {{ $game->awayTeam->name }}
            <br>
            Possession: {{ $possessionTeam->name }}
        </div>
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


        <div class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
            <div class="flex items-center justify-between">
                <div class="text-xl font-semibold">Engine Result</div>
                <div class="text-sm text-gray-400">
                    <span x-show="resolving" x-cloak>Resolving…</span>
                    <span x-show="!diceOnly && !resolving && resolved" x-cloak
                          class="cursor-pointer"
                          @click="resolved = false"
                    >OK</span>
                </div>
            </div>

            <template x-if="diceOnly">
                <div class="mt-3 text-sm text-gray-400">
                    Dice-only mode enabled.
                </div>
            </template>

            <template x-if="resolveError">
                <pre class="mt-3 whitespace-pre-wrap text-sm text-red-300" x-text="resolveError"></pre>
            </template>

            <template x-if="resolved">
        <pre class="mt-3 whitespace-pre-wrap text-sm text-gray-200"
             x-text="JSON.stringify(resolved.resolved ?? resolved, null, 2)"></pre>
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
                if (!this.offensePlay || !this.defensePlay) {
                this.resolveError = 'Select offense and defense plays first.';
                return;
            }

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
                if (json?.errors) this.resolveError = JSON.stringify(json.errors, null, 2);
                return;
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
