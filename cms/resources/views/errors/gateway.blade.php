@auth
    <x-layouts.app title="Gateway unreachable">
        <x-gateway-unavailable :exception="$exception ?? null" />
    </x-layouts.app>
@else
    <x-layouts.guest title="Gateway unreachable">
        <x-gateway-unavailable :exception="$exception ?? null" />
    </x-layouts.guest>
@endauth
