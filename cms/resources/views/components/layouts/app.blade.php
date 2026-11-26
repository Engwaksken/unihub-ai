@props(['title' => null])
@php($navigation = \App\Support\Navigation::for(auth()->user()))
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
<body class="portal" x-data="{ navOpen: false }" x-on:keydown.escape.window="navOpen = false">
    <a href="#main" class="skip-link">Skip to main content</a>

    <header class="topbar">
        <button type="button" class="nav-toggle" x-on:click="navOpen = ! navOpen"
                x-bind:aria-expanded="navOpen ? 'true' : 'false'" aria-controls="sidebar">
            <x-icon name="menu" />
            <span class="visually-hidden">Toggle menu</span>
        </button>
        <a class="brand" href="{{ route('home') }}">
            <span class="brand-mark" aria-hidden="true">U</span>
            <span>{{ app(\App\Services\CmsSettings::class)->brandName() }}</span>
        </a>
        <div class="topbar-user">
            <a href="{{ route('account.show') }}" class="topbar-link">
                <x-icon name="user" />
                <span class="topbar-name">{{ auth()->user()->name }}</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm">Sign out</button>
            </form>
        </div>
    </header>

    <div class="shell">
        <nav id="sidebar" class="sidebar" x-bind:class="{ 'is-open': navOpen }" aria-label="Main">
            <ul class="nav-list">
                @foreach ($navigation as $item)
                    <li>
                        <a href="{{ $item['url'] }}" @class(['nav-link', 'is-active' => $item['active']])
                           @if ($item['active']) aria-current="page" @endif>
                            <x-icon :name="$item['icon']" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <p class="sidebar-foot">Times shown in {{ \App\Support\DisplayTime::zone() }}</p>
        </nav>
        <div class="nav-backdrop" x-show="navOpen" x-on:click="navOpen = false" x-cloak></div>

        <main id="main" class="main" tabindex="-1">
            <x-flash />
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
