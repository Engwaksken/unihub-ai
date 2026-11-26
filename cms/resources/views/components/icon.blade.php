@props(['name'])
@php
    // Simple 24px stroke icons (no external icon font or CDN).
    $paths = [
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'home' => 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
        'folder' => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z',
        'building' => 'M4 21V5a2 2 0 012-2h8a2 2 0 012 2v16M16 9h3a1 1 0 011 1v11M8 7h4M8 11h4M8 15h4M2 21h20',
        'key' => 'M15 7a4 4 0 11-3.9 4.9L3 20v-3l2-2h2v-2h2l1.1-1.1A4 4 0 0115 7z',
        'cpu' => 'M7 7h10v10H7zM9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3',
        'queue' => 'M4 6h16M4 12h10M4 18h6',
        'chart' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
        'server' => 'M3 5h18v6H3zM3 13h18v6H3zM7 8h.01M7 16h.01',
        'users' => 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM22 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8',
        'cog' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-2.9 1.2V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-2.9-1.2l-.1.1a2 2 0 11-2.8-2.8l.1-.1A1.7 1.7 0 003 15H3a2 2 0 110-4h.1a1.7 1.7 0 001.2-2.9l-.1-.1a2 2 0 112.8-2.8l.1.1A1.7 1.7 0 0010 4.1V4a2 2 0 114 0v.1a1.7 1.7 0 002.9 1.2l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 001.2 2.9h.1a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z',
        'user' => 'M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z',
        'alert' => 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
        'search' => 'M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.3-4.3',
        'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
        'dot' => 'M12 13a1 1 0 100-2 1 1 0 000 2z',
    ];
@endphp
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="{{ $paths[$name] ?? $paths['dot'] }}" />
</svg>
