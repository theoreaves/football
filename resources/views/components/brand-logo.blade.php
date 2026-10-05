@props(['sport' => 'football'])
<img src="{{ asset($sport === 'general' ? 'branding/websports.png' : 'branding/websports-football.png') }}"
     alt="{{ $sport === 'general' ? 'WebSports' : 'WebSports Football' }}"
     {{ $attributes->class(['block h-auto object-contain']) }}>
