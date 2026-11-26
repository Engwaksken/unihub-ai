<x-layouts.guest title="This link can no longer be used">
    <div class="state state-error" role="alert">
        <p>The invitation link is invalid, has expired, or has already been used. Each link works once and expires after {{ (int) config('cms.invitation_ttl_hours', 24) }} hours.</p>
        <p>Ask an owner to send you a new invitation.</p>
    </div>
    <p class="form-note"><a href="{{ route('login') }}">Go to sign in</a></p>
</x-layouts.guest>
