@php
    $skin = $portraitPlayer['skin_tone'] ?? '#bd906f';
    $hair = $portraitPlayer['appearance']['hair_color'] ?? '#29241f';
    $hairStyle = $portraitPlayer['appearance']['hair'] ?? 'short';
    $headShape = $portraitPlayer['appearance']['head_shape'] ?? 'round';
    $beardStyle = $portraitPlayer['appearance']['beard'] ?? 'none';
    $browStyle = $portraitPlayer['appearance']['brow'] ?? 'straight';
    $eyeColor = $portraitPlayer['appearance']['eye_color'] ?? '#17202b';
@endphp
<div class="shrink-0 w-14 h-16 rounded overflow-hidden" style="background:#354d69" role="img" aria-label="Portrait of {{ $portraitPlayer['name'] }}">
                                      @if(!empty($portraitPlayer['portrait_url']))
                                        <img src="{{ $portraitPlayer['portrait_url'] }}" alt="" loading="lazy" class="w-full h-full object-cover">
                                      @else
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56 64" width="56" height="64" aria-hidden="true">
                                          <rect width="56" height="64" fill="#354d69"/>
                                          <ellipse cx="28" cy="67" rx="28" ry="22" fill="{{ $appearance[$portraitSide]['uniform']['shirt'] ?? '#64748b' }}"/>
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
