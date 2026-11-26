@props(['title' => null, 'wide' => false])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ app(\App\Services\CmsSettings::class)->brandName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="guest">
    <a href="#main" class="skip-link">Skip to main content</a>
    <main id="main" @class(['guest-card', 'guest-card-wide' => $wide]) tabindex="-1">
        <div class="guest-brand">
            <span class="brand-mark" aria-hidden="true">U</span>
            <span>{{ app(\App\Services\CmsSettings::class)->brandName() }}</span>
        </div>
        @if ($title)
            <h1 class="guest-title">{{ $title }}</h1>
        @endif
        <x-flash />
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
