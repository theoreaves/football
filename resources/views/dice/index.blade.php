{{-- resources/views/dice/roller.blade.php --}}
<x-layouts.app>
    {{-- resources/views/dice/index.blade.php (or wherever you render it) --}}
    <div
        x-data="diceRoller()"
        x-init="init()"
        class="p-6 text-gray-100 select-none"
    >
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
                <div class="text-xl font-semibold">Player &amp; Disrupter Dice</div>
                <div class="text-sm text-gray-400">
                    Player: <span class="text-gray-100 font-semibold" x-text="green.value ?? '—'"></span>
                    <span class="mx-2">|</span>
                    Disrupter: <span class="text-gray-100 font-semibold" x-text="orange.value ?? '—'"></span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-4">
                {{-- Green d20 (more “poly” feel: clipped hex-like) --}}
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

                {{-- Orange d20 --}}
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
            </div>
        </div>

        <script>
            function diceRoller() {
                return {
                    rolling: false,
                    lastRollLabel: '',
                    playResult: null,

                    red:    { sides: 6,  value: null, spin: null },
                    white:  { sides: 10, value: null, spin: null },
                    blue:   { sides: 10, value: null, spin: null },
                    green:  { sides: 20, value: null, spin: null },
                    orange: { sides: 20, value: null, spin: null },

                    init() {
                        // Hotkey: R to roll (ignore if typing in an input/textarea)
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
                        // while rolling, show the spin value; otherwise the final
                        if (this.rolling) return die.spin ?? '—';
                        return die.value ?? '—';
                    },

                    rand(min, max) {
                        return Math.floor(Math.random() * (max - min + 1)) + min;
                    },

                    finalizeDie(die) {
                        die.value = this.rand(1, die.sides);
                        die.spin = null;
                    },

                    finalize10Die(die) {
                        die.value = this.rand(0, 9);
                        die.spin = null;
                    },

                    computePlayResult() {
                        // only from first two dice: red + white => "57" style
                        if (!this.red.value && !this.white.value) return null;
                        return parseInt(String(this.red.value) + String(this.white.value), 10);
                    },

                    rollAll() {
                        if (this.rolling) return;

                        this.rolling = true;
                        const start = Date.now();
                        const duration = 2000; // 2 seconds
                        const tickMs = 90;

                        // spinning values
                        const spinTimer = setInterval(() => {
                            this.red.spin    = this.rand(1, this.red.sides);
                            // this.white.spin  = this.rand(1, this.white.sides);
                            // this.blue.spin   = this.rand(1, this.blue.sides);
                            this.green.spin  = this.rand(1, this.green.sides);
                            this.orange.spin = this.rand(1, this.orange.sides);
                            this.white.spin  = this.rand(0, 9);
                            this.blue.spin   = this.rand(0, 9);


                            if (Date.now() - start >= duration) {
                                clearInterval(spinTimer);

                                this.finalizeDie(this.red);
                                this.finalize10Die(this.white);
                                this.finalize10Die(this.blue);
                                this.finalizeDie(this.green);
                                this.finalizeDie(this.orange);

                                this.playResult = this.computePlayResult();

                                this.lastRollLabel = [
                                    `R:${this.red.value}`,
                                    `W:${this.white.value}`,
                                    `B:${this.blue.value}`,
                                    `G:${this.green.value}`,
                                    `O:${this.orange.value}`,
                                ].join('  ');

                                this.rolling = false;
                            }
                        }, tickMs);
                    },

                    resetAll() {
                        if (this.rolling) return;
                        [this.red, this.white, this.blue, this.green, this.orange].forEach(d => {
                            d.value = null;
                            d.spin = null;
                        });
                        this.playResult = null;
                        this.lastRollLabel = '';
                    },
                };
            }
        </script>
    </div>


</x-layouts.app>
