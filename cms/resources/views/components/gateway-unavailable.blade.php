@props(['exception' => null])
<div class="state state-error" role="alert">
    <x-icon name="alert" class="state-icon" />
    <h2 class="state-title">Gateway unreachable</h2>
    <p>The CMS could not get a valid response from the Unihub AI gateway, so this information cannot be shown right now. Nothing was changed.</p>
    @if ($exception?->errorCode === 'NOT_CONFIGURED' || $exception?->errorCode === 'SIGNING_FAILED')
        <p>The gateway connection is not configured on this server. An administrator needs to set <code>GATEWAY_MGMT_URL</code>, <code>GATEWAY_MGMT_TOKEN</code> and <code>CMS_ASSERTION_SECRET_KEY_PATH</code>.</p>
    @elseif ($exception instanceof \App\Gateway\Exceptions\GatewayAuthenticationException)
        <p>The gateway rejected the CMS credentials. Check the management token, the assertion key pair and the server clock.</p>
    @else
        <p>Try again in a minute. If it keeps happening, check Server &amp; alerts or contact the operations team.</p>
    @endif
    @if ($exception?->requestId)
        <p class="muted small">Reference: <code>{{ $exception->requestId }}</code></p>
    @endif
    <p><a href="{{ url()->current() }}" class="btn btn-secondary">Try again</a></p>
</div>
