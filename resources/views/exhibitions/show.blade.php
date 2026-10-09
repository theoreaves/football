<x-layouts.app :immersive="true">
@php
    $state = $exhibition->state;
    $watching = request('watch') === '1' && $last;
    $shown = $watching ? $last['before'] : $state;
    $teamNames = ['home' => $exhibition->homeTeam->name, 'away' => $exhibition->awayTeam->name];
@endphp
<div data-show-highlights="{{ request()->boolean('highlights') && !$replayOnly ? 'true' : 'false' }}" data-summary="{{ request()->boolean('summary') && !$replayOnly && $state['status'] === 'final' ? 'true' : 'false' }}" data-practice data-crowd="{{ json_encode($state['crowd'] ?? ['fullness' => 80, 'visitors' => 10, 'seed' => $exhibition->id]) }}" data-exhibition data-cpu-special="{{ $cpuPlan && in_array($cpuPlan['call'], ['punt', 'field_goal', 'kickoff', 'extra_point'], true) ? 'true' : 'false' }}" data-cpu-only="{{ $cpuOffense && $cpuDefense ? 'true' : 'false' }}" data-before-state="{{ json_encode($last['before'] ?? $state) }}" data-after-state="{{ json_encode($state) }}" data-team-names="{{ json_encode($teamNames) }}" data-coach-offense="{{ json_encode($coachSuggestion) }}" data-coach-defense="{{ json_encode($coachDefense) }}" data-defense-options="{{ json_encode($defenseOptions) }}" data-next-line="{{ \App\Services\Simulation\FieldOrientation::line($state) }}" data-next-possession="{{ $state['possession'] }}" data-next-direction="{{ \App\Services\Simulation\FieldOrientation::direction($state) }}" data-next-distance="{{ $state['distance'] }}" data-camera-key="football-camera-{{ $exhibition->world_id }}-{{ $exhibition->id }}" data-play-number="{{ $state['version'] }}" data-animation="{{ json_encode($animation) }}" data-appearance="{{ json_encode($appearance) }}" data-autoplay="{{ request('watch') === '1' || $replayOnly ? 'true' : 'false' }}" class="game-stage text-white">
    @if(!$replayOnly && !request()->boolean('summary') && (int) ($state['version'] ?? 0) === 0 && !($cpuOffense && $cpuDefense))
    <dialog data-pregame-dialog class="game-dialog" style="width:min(94vw,1050px);max-width:1050px;max-height:90vh;overflow:auto;background:#101827;color:#f9fafb;border:1px solid #36537b;border-radius:16px;padding:24px;">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <div><p class="text-blue-300 text-sm tracking-widest uppercase">Game Day</p><h2 class="text-2xl font-bold">Starting Lineups</h2></div>
            <button type="button" data-pregame-close class="bg-blue-700 hover:bg-blue-600 rounded px-5 py-2">Continue to Coin Toss →</button>
        </div>
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
                                    <div class="shrink-0 w-14 h-16 rounded overflow-hidden" style="background:#354d69" role="img" aria-label="Portrait of {{ $starter['name'] }}">
                                      @if(!empty($starter['portrait_url']))
                                        <img src="{{ $starter['portrait_url'] }}" alt="" loading="lazy" class="w-full h-full object-cover">
                                      @else
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56 64" width="56" height="64" aria-hidden="true">
                                          <rect width="56" height="64" fill="#354d69"/>
                                          <ellipse cx="28" cy="67" rx="28" ry="22" fill="{{ $appearance[$lineupSide]['uniform']['shirt'] ?? '#64748b' }}"/>
                                          <rect x="23" y="40" width="10" height="12" rx="4" fill="{{ $skin }}"/>
                                          <ellipse cx="28" cy="27" rx="{{ $headShape === 'wide' ? 19 : ($headShape === 'long' ? 14 : 16) }}" ry="{{ $headShape === 'long' ? 22 : ($headShape === 'wide' ? 17 : 19) }}" fill="{{ $skin }}"/>
                                          @if($hairStyle === 'bald')
                                            {{-- No hair --}}
                                          @elseif($hairStyle === 'buzz')
                                            <path d="M12 24 Q10 6 28 7 Q46 6 44 24 Q28 14 12 24Z" fill="{{ $hair }}"/>
                                          @elseif($hairStyle === 'curly')
                                            @foreach([13,19,26,33,40,44] as $curlX)
                                              <circle cx="{{ $curlX }}" cy="{{ $curlX === 13 || $curlX === 44 ? 19 : 12 }}" r="7" fill="{{ $hair }}"/>
                                            @endforeach
                                          @elseif($hairStyle === 'long')
                                            <path d="M11 24 Q6 4 29 6 Q50 3 46 25 L48 50 L39 47 L40 18 Q28 11 16 19 L16 48 L8 50Z" fill="{{ $hair }}"/>
                                          @else
                                            <path d="M12 25 Q8 5 29 7 Q48 5 44 26 L41 18 Q27 12 15 20Z" fill="{{ $hair }}"/>
                                          @endif
                                          <circle cx="22" cy="29" r="1.5" fill="{{ $eyeColor }}"/><circle cx="34" cy="29" r="1.5" fill="{{ $eyeColor }}"/>
                                          @if($browStyle === 'thick')
                                            <path d="M18 25h8 M30 25h8" stroke="{{ $hair }}" stroke-width="2.5" stroke-linecap="round"/>
                                          @elseif($browStyle === 'angled')
                                            <path d="M18 26l8 -2 M30 24l8 2" stroke="{{ $hair }}" stroke-width="1.5"/>
                                          @endif
                                          @if($beardStyle === 'full')
                                            <path d="M14 34 Q15 49 28 50 Q41 49 42 34 Q36 45 28 46 Q20 45 14 34Z" fill="{{ $hair }}"/>
                                          @elseif($beardStyle === 'goatee')
                                            <path d="M23 43 Q28 54 33 43Z" fill="{{ $hair }}"/>
                                          @elseif($beardStyle === 'moustache')
                                            <path d="M20 39 Q24 35 28 39 Q32 35 36 39 Q30 42 28 40 Q24 42 20 39" fill="{{ $hair }}"/>
                                          @elseif($beardStyle === 'stubble')
                                            <path d="M18 38 Q28 51 38 38" stroke="{{ $hair }}" stroke-opacity=".42" stroke-width="3" fill="none"/>
                                          @endif
                                          <path d="M23 38 Q28 41 33 38" stroke="#49352a" stroke-width="1" fill="none"/>
                                        </svg>
                                      @endif
                                    </div>
                                    <div class="min-w-0"><p class="text-xs text-blue-300">{{ $lineupRole }} · #{{ $starter['number'] ?? '—' }}</p><p class="text-sm font-semibold truncate" title="{{ $starter['name'] }}">{{ $starter['name'] }}</p></div>
                                </div>
                            @endif
                        @endforeach
                        </div>
                    @endforeach
                </section>
            @endforeach
        </div>
        <p class="mt-4 text-xs text-gray-400">Portraits are lightweight illustrations based on player appearance. Custom player photos can be supported later.</p>
        <button type="button" data-pregame-close class="mt-4 bg-blue-700 hover:bg-blue-600 rounded px-5 py-2">Start Game →</button>
    </dialog>
    @endif
    @if($state['version'] === 0 && isset($state['coin_toss']))
    <dialog data-coin-dialog data-pending="{{ ($state['coin_toss']['pending'] ?? false) ? 'true' : 'false' }}" class="game-dialog text-center">
        <h2 class="game-event-title text-blue-300">COIN TOSS</h2>
        <p class="mt-4">{{ $teamNames['away'] }} called {{ $state['coin_toss']['call'] }}. The coin landed {{ $state['coin_toss']['result'] }}.</p>
        <p class="mt-3 font-semibold">{{ $teamNames[$state['coin_toss']['winner']] }} wins the toss.</p>
        @if($state['coin_toss']['pending'] ?? false)
        <p class="mt-3">Choose whether to kick or receive.</p>
        <form method="POST" action="{{ route('exhibitions.play', $exhibition) }}" class="mt-4 flex justify-center gap-4">
            @csrf
            <input type="hidden" name="version" value="0"><input type="hidden" name="action" value="coin">
            <button name="choice" value="kick">Kick</button><button name="choice" value="receive">Receive</button>
        </form>
        @else
        <p class="mt-3">{{ $teamNames[$state['coin_toss']['winner']] }} chooses to {{ $state['coin_toss']['choice'] ?? 'receive' }}. {{ $teamNames[$state['opening_receiver']] }} receives the opening kickoff.</p>
        <form method="dialog" class="mt-4"><button>OK · Start game</button></form>
        @endif
    </dialog>
    @endif
    @if($replayOnly)<a class="game-replay-return" href="{{ route('exhibitions.show', $exhibition) }}">← Return to game · Replay #{{ $last['number'] }}</a>@endif
    @if(!$replayOnly && app(\App\Services\Simulation\Overtime::class)->pending($state))
    <dialog data-ot-dialog class="game-dialog text-center">
        <h2 class="game-event-title text-blue-300">OVERTIME · COIN TOSS</h2>
        <p class="mt-3">{{ str_starts_with($state['rules']['overtime'], 'traditional') ? 'First score wins.' : 'Both teams get an opportunity, subject to the clock.' }} {{ \App\Services\Simulation\Overtime::playoff($state) ? '15-minute periods until a winner · three timeouts per two OT periods.' : 'One 10-minute period · two timeouts per team.' }}</p>
        <form method="POST" action="{{ route('exhibitions.play', $exhibition) }}" class="mt-4 flex flex-wrap gap-4 justify-center">
            @csrf<input type="hidden" name="version" value="{{ $state['version'] }}">
            @if($state['overtime']['toss']['call_pending'])
                <input type="hidden" name="action" value="ot_call">
                <p class="w-full">{{ $teamNames['away'] }} calls the overtime toss.</p>
                <button name="toss_call" value="heads">Heads</button><button name="toss_call" value="tails">Tails</button>
            @else
                <input type="hidden" name="action" value="ot_choice">
                <p class="w-full">The coin landed {{ $state['overtime']['toss']['result'] }}. {{ $teamNames[$state['overtime']['toss']['winner']] }} wins—choose kick or receive.</p>
                <button name="choice" value="kick">Kick</button><button name="choice" value="receive">Receive</button>
            @endif
        </form>
    </dialog>
    @endif
    <div class="game-brand-watermark" aria-hidden="true"><x-brand-logo /></div>
    <div class="game-scoreboard">
        @if($exhibition->seasonFixture)<a href="{{ route('seasons.show', $exhibition->seasonFixture->season_id) }}" class="score-exit">Season · Week {{ $exhibition->seasonFixture->week }}</a>@else<a href="{{ route('exhibitions.index') }}" class="score-exit">Exhibitions</a>@endif
        @foreach(['away', 'home'] as $scoreSide)
        <div class="score-team">
            @if($appearance[$scoreSide]['team_logo'] ?? null)<img class="score-team-logo" src="{{ $appearance[$scoreSide]['team_logo'] }}" alt="{{ $teamNames[$scoreSide] }} logo">@endif
            <div class="score-team-label"><span class="score-team-name">{{ $teamNames[$scoreSide] }}</span>
                <span class="timeout-marks" data-timeout-marks="{{ $scoreSide }}" role="img" aria-label="{{ $shown['timeouts'][$scoreSide] ?? 3 }} timeouts remaining">
                    @for($mark = 0; $mark < 3; $mark++)<i aria-hidden="true" class="timeout-mark {{ $mark >= ($shown['timeouts'][$scoreSide] ?? 3) ? 'timeout-used' : '' }}"></i>@endfor
                </span>
            </div>
            <strong @if($scoreSide === 'home') data-home-score @else data-away-score @endif>{{ $shown[$scoreSide.'_score'] }}</strong>
            <span data-possession="{{ $scoreSide }}">{{ $shown['possession'] === $scoreSide ? '●' : '' }}</span>
        </div>
        @endforeach
        <p data-situation class="game-situation">{{ $shown['status'] === 'final' ? 'Game over' : match($shown['phase'] ?? 'scrimmage') { 'kickoff' => 'Kickoff', 'extra_point' => 'Extra point try', default => (['', '1st', '2nd', '3rd', '4th'][$shown['down']] ?? 'Down '.$shown['down']).' & '.($shown['distance'] >= 100 - $shown['spot'] ? 'Goal' : $shown['distance']).' · '.($shown['spot'] <= 50 ? 'Own '.$shown['spot'] : 'Opp '.(100-$shown['spot'])) } }}</p>
        <div class="score-clock"><p data-clock>{{ $shown['status'] === 'final' ? 'FINAL' : ($shown['quarter'] >= 5 ? 'OT'.($shown['quarter'] > 5 ? $shown['quarter'] - 4 : '') : 'Q'.$shown['quarter']).' · '.gmdate('i:s', $shown['clock']) }}</p><span data-clock-status class="score-clock-status">{{ $shown['status'] !== 'final' && ($shown['clock_running'] ?? false) ? 'Running' : 'Stopped' }}</span></div>
        <h1 data-scoreboard class="sr-only">{{ $exhibition->awayTeam->name }} {{ $shown['away_score'] }} — {{ $exhibition->homeTeam->name }} {{ $shown['home_score'] }}</h1>
    </div>
    <p class="game-coaches">{{ $teamNames['home'] }}: {{ strtoupper($controls['home']) }} · {{ $teamNames['away'] }}: {{ strtoupper($controls['away']) }}</p>
    @if($errors->any())<p class="text-red-300">{{ $errors->first() }}</p>@endif
    @if(!$replayOnly && $state['status'] === 'playing' && !($state['penalty_pending'] ?? false))
    <form data-call-form @if($watching) hidden @endif method="POST" action="{{ route('exhibitions.play', $exhibition) }}" class="game-play-panel flex flex-wrap gap-3 items-end">
        @csrf<input type="hidden" name="version" value="{{ $state['version'] }}">
        @unless($cpuOffense)
        <button type="button" data-coach-offense class="border rounded px-3 py-2">Coach pick offense</button>
        <label>Motion<select name="motion" class="block bg-gray-900 rounded p-2 mt-1"><option value="none">None</option>@foreach(['WR1', 'WR2', 'WR3', 'TE', 'RB'] as $role)<option value="{{ $role }}">{{ $role }} · {{ $personnel[$offenseSide]['players'][$role]['name'] }}</option>@endforeach</select></label>
        <label>Tempo<select name="tempo" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="normal">Normal</option><option value="hurry">Hurry-up</option><option value="drain">Run the clock</option></select></label>
        <label>Clock strategy<select name="clock_strategy" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="normal">Normal finish</option>@if(app(\App\Services\Simulation\GameClock::class)->lateHalf($state))<option value="sideline">Try to get out of bounds</option>@endif</select></label>
        <label>Offense formation<select data-formation name="offense_formation" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach(\App\Services\Simulation\ExhibitionEngine::OFFENSE_FORMATIONS as $value => $label)<option value="{{ $value }}" @selected(($last['offense_formation'] ?? 'shotgun') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Offense play<select name="call" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach($calls as $call)<option value="{{ $call }}" @selected($last && ($last['design'] ?? $last['call']) === $call)>{{ match($call) { 'kneel' => 'QB kneel', 'extra_point' => '1-point kick', 'two_point_run' => '2-point run', 'two_point_pass' => '2-point pass', default => ucwords(str_replace('_', ' ', $call)) } }}</option>@endforeach</select></label>
        @else
        <p class="text-sm">{{ $teamNames[$offenseSide] }} offense: CPU @if(in_array($cpuPlan['call'], ['punt', 'field_goal', 'kickoff', 'extra_point'], true)) · {{ ucwords(str_replace('_', ' ', $cpuPlan['call'])) }}@endif</p>
        @endunless
        @unless($cpuDefense)
        <button type="button" data-coach-defense class="border rounded px-3 py-2">Coach pick defense</button>
        <label>Expect<select name="expect" class="block bg-gray-900 rounded p-2 mt-1"><option value="balanced">Balanced</option><option value="run">Run</option><option value="pass">Pass</option></select></label>
        <label class="flex gap-2 items-center"><input type="checkbox" name="blitz" value="1">Send blitz</label>
        <label>Defense formation<select data-formation name="defense_formation" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach(\App\Services\Simulation\ExhibitionEngine::DEFENSE_FORMATIONS as $value => $label)<option value="{{ $value }}" @selected(($last['defense_formation'] ?? 'base_4_3') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Coverage / special teams<select name="defense" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach($humanDefenseOptions as $call)<option value="{{ $call }}" @selected($last && $last['defense'] === $call)>{{ match($call) { 'kneel' => 'QB kneel', 'extra_point' => '1-point kick', 'two_point_run' => '2-point run', 'two_point_pass' => '2-point pass', default => ucwords(str_replace('_', ' ', $call)) } }}</option>@endforeach</select></label>
        @else
        <p class="text-sm">{{ $teamNames[$defenseSide] }} defense: CPU</p>
        @endunless
        <button data-snap class="bg-blue-700 rounded px-6 py-2">{{ $cpuOffense && $cpuDefense ? 'Next CPU play' : 'Call play & watch' }}</button><p class="text-xs text-gray-400">{{ $cpuOffense || $cpuDefense ? 'CPU calls are chosen automatically.' : 'You call both teams.' }} Results save at the snap; replaying changes no stats.</p>
    </form>
    @endif
    @if(!$replayOnly && $state['status'] === 'playing' && $state['clock_running'] && !($state['penalty_pending'] ?? false))
    <div data-hidden-result @if($watching) hidden @endif class="game-timeouts flex flex-wrap gap-3">
    @foreach(['home', 'away'] as $timeoutSide)
        @if($controls[$timeoutSide] === 'human' && $state['timeouts'][$timeoutSide] > 0)
        <form method="POST" data-timeout-form action="{{ route('exhibitions.play', $exhibition) }}">@csrf<input type="hidden" name="version" value="{{ $state['version'] }}"><input type="hidden" name="action" value="timeout"><input type="hidden" name="timeout_team" value="{{ $timeoutSide }}"><button class="border border-gray-500 rounded px-4 py-2">{{ $teamNames[$timeoutSide] }} timeout ({{ $state['timeouts'][$timeoutSide] }})</button></form>
        @endif
    @endforeach
    </div>
    @endif
    @if(!$replayOnly && $cpuOffense && $cpuDefense && $state['status'] === 'playing')
    <button type="button" data-cpu-toggle class="game-cpu-toggle border border-blue-400 rounded px-5 py-2">Start CPU game</button><span data-cpu-status class="game-cpu-status text-sm text-gray-400">Paused between plays</span>
    @endif
    @if($last && !$replayOnly)
    <div data-result-popup hidden role="status" class="fixed z-50 bottom-8 left-1/2 -translate-x-1/2 bg-gray-800 text-white border border-blue-400 rounded-xl shadow-xl p-5 w-full max-w-lg text-center">
        @foreach(\App\Support\PlayAnnouncement::titles($last) as $title)
        <h2 class="game-event-title {{ $title === 'FLAG!' ? 'text-yellow-300' : 'text-blue-300' }}">{{ $title }}</h2>
        @endforeach
        <p class="mt-2">{{ $last['summary'] }}</p>
        <button type="button" data-result-ok class="bg-blue-700 rounded px-5 py-2 mt-3">OK · Continue</button>
        <p class="mt-2 text-sm text-gray-400">{{ $exhibition->awayTeam->name }} {{ $state['away_score'] }} — {{ $exhibition->homeTeam->name }} {{ $state['home_score'] }}</p>
    </div>
    @endif
    @if(!$replayOnly && $last && !empty($last['personnel_notices']))
    <dialog data-injury-dialog class="m-auto bg-gray-800 text-white rounded-xl border border-orange-400 p-6 max-w-xl backdrop:bg-black/70">
        <h2 class="game-event-title text-orange-300">{{ collect($last['personnel_notices'])->contains(fn ($notice) => !str_contains($notice, 'cleared to return')) ? 'INJURY!' : 'PLAYER RETURN' }}</h2>
        @foreach(['home', 'away'] as $noticeSide)
            @php
                $teamNotices = array_filter($last['personnel_notices'], fn ($notice) => str_starts_with($notice, ucfirst($noticeSide).' · '));
            @endphp
            @if($teamNotices)
            <div class="mt-4 flex items-center gap-3">
                @if($appearance[$noticeSide]['team_logo'] ?? null)<img src="{{ $appearance[$noticeSide]['team_logo'] }}" alt="{{ $teamNames[$noticeSide] }} logo" class="w-14 h-14 object-contain">@endif
                <h3 class="text-xl font-semibold">{{ $teamNames[$noticeSide] }}</h3>
            </div>
            @foreach($teamNotices as $notice)<p class="mt-3">{{ substr($notice, strlen(ucfirst($noticeSide).' · ')) }}</p>@endforeach
            @endif
        @endforeach
        <form method="dialog"><button class="bg-blue-700 rounded px-5 py-2 mt-5">OK</button></form>
    </dialog>
    @endif
    <dialog data-personnel-dialog class="m-auto bg-gray-800 text-white rounded-xl border border-gray-600 p-6 w-full max-w-4xl max-h-[85vh] overflow-y-auto backdrop:bg-black/70">
        <div class="flex justify-between items-center gap-4"><h2 class="text-xl font-semibold">Depth chart and availability</h2><form method="dialog"><button class="border rounded px-3 py-2">Close</button></form></div>
        <p class="text-sm text-gray-300 mt-3">Lowest depth number starts. Tired players rotate with a rested backup; injuries force replacements. Fatigue lowers performance by up to 25%. Human coaches can choose game-only starters below. Automatic restores the saved depth order. Injuries and fatigue rotation still apply; your selections remain until changed.</p>
        @foreach($personnel as $side => $roster)
            <h3 class="font-semibold text-lg mt-5">{{ $teamNames[$side] }}</h3>
            @if(!$replayOnly && $controls[$side] === 'human' && isset($roster['pool']) && $state['status'] === 'playing' && !($state['penalty_pending'] ?? false) && !($state['coin_toss']['pending'] ?? false))
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            @foreach(\App\Services\Simulation\RosterBuilder::GROUPS as $role => $positions)
                <form method="POST" action="{{ route('exhibitions.play', $exhibition) }}" class="border border-gray-600 rounded p-3">
                    @csrf
                    <input type="hidden" name="action" value="lineup"><input type="hidden" name="version" value="{{ $state['version'] }}">
                    <input type="hidden" name="team" value="{{ $side }}"><input type="hidden" name="role" value="{{ $role }}">
                    <label class="block">{{ $role }} · {{ $roster['players'][$role]['name'] ?? 'No active player' }}
                    <select name="player" class="block w-full bg-gray-900 text-white rounded p-2 mt-2">
                        <option value="">Automatic</option>
                        @foreach($roster['pool'] as $candidate)
                            @if(in_array($candidate['position'], $positions, true))
                            @php
                                $hurt = $state['injuries'][$side][$candidate['id']] ?? null;
                                $unavailable = $hurt && ($hurt['return_snap'] === null || ($state['personnel_snaps'] ?? 0) < $hurt['return_snap']);
                            @endphp
                            <option value="{{ $candidate['id'] }}" @selected(($state['game_lineup'][$side][$role] ?? null) === $candidate['id']) @disabled($unavailable)>#{{ $candidate['number'] }} {{ $candidate['name'] }} · {{ round($state['fatigue'][$side][$candidate['id']] ?? 0) }}% fatigue{{ $unavailable ? ' · Injured' : '' }}</option>
                            @endif
                        @endforeach
                    </select></label>
                    <button class="mt-2 border rounded px-3 py-2">Set {{ $role }}</button>
                </form>
            @endforeach
            </div>
            @endif
            @if(!isset($roster['pool']))<p class="text-orange-300">Start a new exhibition to capture backups and enable injuries.</p>@endif
            <table class="w-full text-sm mt-3"><thead><tr class="text-left"><th class="p-2">Role</th><th class="p-2">Player</th><th class="p-2">Fatigue</th><th class="p-2">Status</th></tr></thead><tbody>
            @foreach($roster['pool'] ?? $roster['players'] as $player)
                @php
                    $roles = array_keys(array_filter($roster['players'], fn ($active) => $active['id'] === $player['id']));
                    $injury = $state['injuries'][$side][$player['id']] ?? null;
                @endphp
                <tr class="border-t border-gray-700"><td class="p-2">{{ $player['depth'] ?? implode(', ', $roles) }}</td><td class="p-2">#{{ $player['number'] }} {{ $player['name'] }}</td><td class="p-2">{{ round($state['fatigue'][$side][$player['id']] ?? 0) }}%</td><td class="p-2">{{ $injury ? ($injury['return_snap'] === null ? 'Out for game' : 'Out · '.max(0, $injury['return_snap'] - ($state['personnel_snaps'] ?? 0)).' snaps') : ($roles ? 'Active · '.implode(', ', $roles) : 'Backup') }}</td></tr>
            @endforeach
            </tbody></table>
        @endforeach
    </dialog>
    @if(!$replayOnly && $last && isset($last['penalty']) && !($last['penalty']['decided'] ?? false))
    <dialog data-penalty-dialog class="m-auto bg-gray-800 text-white rounded-xl border border-yellow-400 p-6 max-w-xl backdrop:bg-black/70">
        <h2 class="game-event-title text-yellow-300">FLAG!</h2>
        <p class="mt-3 font-semibold">{{ ucwords(str_replace('_', ' ', $last['penalty']['type'])) }}</p>
        <p class="mt-3">On {{ $teamNames[$last['penalty']['team']] }}.</p>
        <p class="mt-3 text-gray-300">{{ $last['penalty']['explanation'] }}</p>
        @if(!($last['no_snap'] ?? false) && isset($last['penalty']['play_result']))<p class="mt-3 text-gray-300">Play result: {{ $last['penalty']['play_result'] }}</p>@endif
        <p class="mt-3 text-sm text-gray-400">Yardage is reduced to half the distance to the goal when needed.</p>
        @if($state['penalty_pending'] ?? false)
            <p class="mt-3">{{ $teamNames[$last['penalty']['beneficiary']] }} chooses:</p>
            <div class="grid gap-3 mt-4">
            @foreach(['accept' => 'Accept', 'decline' => 'Decline'] as $decision => $label)
                @php($option = $last['penalty_options'][$decision]['state'])
                <form data-penalty-form method="POST" action="{{ route('exhibitions.play', $exhibition) }}">@csrf
                    <input type="hidden" name="version" value="{{ $state['version'] }}"><input type="hidden" name="action" value="penalty"><input type="hidden" name="decision" value="{{ $decision }}">
                    <button class="bg-blue-700 rounded px-5 py-2">{{ $label }}</button>
                    <span class="ml-2 text-sm">{{ $teamNames[$option['possession']] }} · Down {{ $option['down'] }} & {{ $option['distance'] >= 100 - $option['spot'] ? 'Goal' : $option['distance'] }} · {{ $option['spot'] <= 50 ? 'Own '.$option['spot'] : 'Opponent '.(100-$option['spot']) }} · Score {{ $option['away_score'] }}–{{ $option['home_score'] }}</span>
                </form>
            @endforeach
            </div>
        @else
            <p class="mt-3">{{ $teamNames[$last['penalty']['beneficiary']] }} CPU {{ $last['penalty']['accepted'] ? 'accepted' : 'declined' }} the penalty.</p>
            <form method="dialog"><button class="bg-blue-700 rounded px-5 py-2 mt-5">OK</button></form>
        @endif
    </dialog>
    @endif
    @if(!request()->boolean('summary') && !$replayOnly && $last && (($last['two_minute_warning'] ?? false) || $last['before']['quarter'] !== $last['after']['quarter'] || $last['after']['status'] === 'final'))
    <dialog data-quarter-dialog class="m-auto bg-gray-800 text-white rounded-xl border border-gray-600 p-6 max-w-md backdrop:bg-black/70">
        <h2 class="text-2xl font-semibold">{{ $state['status'] === 'final' ? 'Final whistle' : (($last['two_minute_warning'] ?? false) ? 'Two-minute warning' : ($state['quarter'] === 5 && $last['before']['quarter'] === 4 ? 'OVERTIME' : ($last['before']['quarter'] === 2 ? 'Halftime' : ($last['before']['quarter'] >= 5 ? 'End of overtime '.($last['before']['quarter'] - 4) : 'End of quarter '.$last['before']['quarter'])))) }}</h2>
        @if($state['quarter'] === 5 && $last['before']['quarter'] === 4 && !app(\App\Services\Simulation\Overtime::class)->pending($state))
        <p class="mt-3">Overtime toss: {{ $teamNames[$state['overtime']['toss']['winner']] }} wins and chooses to {{ $state['overtime']['toss']['choice'] }}. {{ $teamNames[$state['overtime']['receiver']] }} receives.</p>
        @endif
        <p class="mt-3">{{ $exhibition->awayTeam->name }} {{ $state['away_score'] }} — {{ $exhibition->homeTeam->name }} {{ $state['home_score'] }}</p>
        <p class="mt-3 text-gray-300">
        @if($state['status'] === 'final')The exhibition is complete.
        @elseif($last['two_minute_warning'] ?? false)The clock is stopped. Choose your clock strategy for the rest of the period.
        @elseif($state['quarter'] === 5 && $last['before']['quarter'] === 4)
            {{ str_starts_with($state['rules']['overtime'], 'traditional') ? 'First score wins.' : 'Both teams get an opportunity; if the first team does not score, the next score wins.' }}
            {{ \App\Services\Simulation\Overtime::playoff($state) ? '15-minute periods continue until a winner.' : 'One 10-minute period; the game can end in a tie.' }}
        @elseif($last['before']['quarter'] === 2){{ $teamNames[$state['possession'] === 'home' ? 'away' : 'home'] }} receives to start the second half.
        @else{{ $state['quarter'] >= 5 ? 'Overtime '.($state['quarter'] - 4) : 'Quarter '.$state['quarter'] }} is ready. Possession and field position carry over.
        @endif
        </p>
        <form method="dialog"><button class="bg-blue-700 rounded px-5 py-2 mt-5">{{ $last['after']['status'] === 'final' ? 'View final result' : 'Continue' }}</button></form>
    </dialog>
    @endif
    <div class="game-camera-controls flex flex-wrap gap-3 items-center">
        <label>Camera<select data-camera class="bg-gray-800 text-white rounded p-2 ml-2"><option value="broadcast">Broadcast</option><option value="overhead">Overhead</option><option value="quarterback">Behind QB</option></select></label>
        <label>Speed<select data-speed class="bg-gray-800 text-white rounded p-2 ml-2"><option value="0.5">Half</option><option value="1" selected>Normal</option><option value="2">Double</option></select></label>
        @if($last && !($last['after']['penalty_pending'] ?? false))
        <form data-save-highlight-form data-hidden-result @if($watching && !$replayOnly) hidden @endif method="POST" action="{{ route('exhibitions.highlights.save', $exhibition) }}">@csrf<input type="hidden" name="number" value="{{ $last['number'] }}"><input type="hidden" name="return_replay" value="{{ $replayOnly ? 1 : 0 }}"><button class="border border-blue-400 rounded px-3 py-2" @disabled($last['saved_highlight'] ?? false)>{{ ($last['saved_highlight'] ?? false) ? 'Highlight saved' : 'Save highlight' }}</button></form>
        @endif
        <button data-play class="bg-gray-700 rounded px-4 py-2">{{ $last ? 'Replay' : 'Play preview' }}</button><button data-reset class="border border-gray-600 rounded px-4 py-2">Reset replay</button><button data-reset-camera class="border border-gray-600 rounded px-4 py-2">Reset camera</button>
        <span class="text-xs text-gray-400">Drag to orbit · Scroll to zoom</span>
    </div>
    <div data-field class="game-field"></div>
    <details class="game-audio absolute right-4 top-36 z-20 bg-gray-900/90 border border-gray-600 rounded p-3 text-sm">
        <summary class="cursor-pointer">Stadium sound</summary>
        <button type="button" data-sound-toggle class="border border-gray-500 rounded px-3 py-1 mt-2" aria-pressed="true">Sound on</button>
        @foreach(['master' => 'Master volume', 'crowd' => 'Crowd volume', 'effects' => 'Effects and stadium cues'] as $channel => $label)
        <label class="block mt-2">{{ $label }}<input data-sound-volume="{{ $channel }}" type="range" min="0" max="100" step="1" class="block w-48 accent-blue-400"></label>
        @endforeach
    </details>
    <div class="game-timeline flex items-center gap-4"><label class="sr-only" for="play-timeline">Replay timeline</label><input id="play-timeline" data-timeline type="range" min="0" max="6" step="0.01" value="0" class="flex-1 accent-blue-400"><span data-time class="text-gray-400 text-sm">0.0 / 6.0s</span></div>
    <p data-status aria-live="polite" class="game-status text-blue-300">Loading field…</p>
    @if($last)<p data-hidden-result @if($watching) hidden @endif class="game-last-result">Last play: {{ $last['summary'] }}</p>@endif
    <p class="game-help text-sm text-gray-400">Home offense moves toward the right end zone; away offense toward the left. The scoreboard updates when the replay reveals the result.</p>
    <div class="game-stats grid grid-cols-2 gap-4 text-sm">@foreach(['home', 'away'] as $side)<p data-stats="{{ $side }}">{{ $side === 'home' ? $exhibition->homeTeam->name : $exhibition->awayTeam->name }}: {{ $shown['stats'][$side]['plays'] }} plays · {{ $shown['stats'][$side]['yards'] }} yards · {{ $shown['stats'][$side]['turnovers'] }} turnovers · {{ $shown['stats'][$side]['penalties'] ?? 0 }} penalties / {{ $shown['stats'][$side]['penalty_yards'] ?? 0 }} yards</p>@endforeach</div>
    <div class="game-actions">@if(!$replayOnly && ($state['status'] === 'playing' || ($state['penalty_pending'] ?? false)))<form method="POST" action="{{ route('exhibitions.finish', $exhibition) }}" data-finish-sim-form>@csrf<input type="hidden" name="version" value="{{ $state['version'] }}"><button type="submit">Finish with Quick Sim</button></form>@endif@unless($replayOnly)<button type="button" data-hidden-result @if($watching) hidden @endif data-open-personnel>Depth chart</button>@endunless<button type="button" data-open-log>Play log (<span data-log-count>{{ count($exhibition->history) - ($watching && !$replayOnly ? 1 : 0) }}</span>)</button><button type="button" data-open-highlights>Highlights</button><button type="button" data-open-box>Box score</button><button type="button" data-fullscreen>Full screen</button></div>
    <dialog data-log-dialog class="game-dialog"><form method="dialog"><button class="float-right">Close</button></form><h2 class="text-2xl font-semibold mb-4">Play log</h2>
<ol class="space-y-2 mt-3 text-sm text-gray-400">@foreach(array_reverse($exhibition->history) as $play)<li @if($loop->first && !$replayOnly) data-hidden-result @if($watching) hidden @endif @endif>#{{ $play['number'] }} · {{ $play['before']['quarter'] >= 5 ? 'OT'.($play['before']['quarter'] > 5 ? $play['before']['quarter'] - 4 : '') : 'Q'.$play['before']['quarter'] }} {{ gmdate('i:s', $play['before']['clock']) }} · {{ $play['before']['possession'] }} · {{ $play['summary'] }}
@if(isset($play['animation']))<a class="text-blue-300 underline ml-2" href="{{ route('exhibitions.show', ['exhibition' => $exhibition, 'replay' => $play['number'], 'watch' => 1]) }}">Replay{{ \App\Support\PlayHighlights::saved($play) ? ' · Highlight' : '' }}</a>@endif</li>@endforeach</ol>
    </dialog>
    <dialog data-highlights-dialog class="game-dialog"><form method="dialog"><button class="float-right">Close</button></form><h2 class="text-2xl font-semibold mb-4">Highlight reel</h2>
        <p class="text-gray-300 mb-4">Gains over 20 yards, scoring plays, turnovers, and your saved plays.</p>
        <ol class="space-y-4">@forelse($highlights as $highlight)<li @if(!$replayOnly && $highlight['number'] === ($last['number'] ?? null)) data-hidden-result @if($watching) hidden @endif @endif>#{{ $highlight['number'] }} · {{ implode(' · ', \App\Support\PlayHighlights::reasons($highlight)) }}{{ ($highlight['saved_highlight'] ?? false) ? ' · Saved by you' : '' }}<p>{{ $highlight['summary'] }}</p><a class="text-blue-300 underline" href="{{ route('exhibitions.show', ['exhibition' => $exhibition, 'replay' => $highlight['number'], 'watch' => 1]) }}">Watch highlight</a></li>@empty<li>No highlights yet. You can save any play beside the Replay button.</li>@endforelse</ol>
    </dialog>
    <dialog data-box-dialog class="game-dialog"><form method="dialog"><button class="float-right">Close</button></form>@include('exhibitions.box-score')</dialog>

</div>
</x-layouts.app>
