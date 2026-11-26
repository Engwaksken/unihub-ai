@if ($kind === 'local_unreachable')
The CMS has not been able to reach the Unihub AI gateway management API since {{ \App\Support\DisplayTime::format($alert['first_seen_at'] ?? null, 'j M Y, H:i') }} ({{ $zone }}).

Last error: {{ $alert['message'] ?? 'unknown' }}

Gateway alerts cannot be delivered while this lasts. Check the VPS, nginx and the management vhost allowlist.
@elseif ($kind === 'local_recovered')
The CMS can reach the Unihub AI gateway again (it was unreachable since {{ \App\Support\DisplayTime::format($alert['first_seen_at'] ?? null, 'j M Y, H:i') }} {{ $zone }}).
@else
A gateway alert is open.

Type:       {{ $alert['type'] ?? 'unknown' }}
Severity:   {{ $alert['severity'] ?? 'unknown' }}
Message:    {{ $alert['message'] ?? '' }}
First seen: {{ \App\Support\DisplayTime::format($alert['first_seen_at'] ?? null, 'j M Y, H:i') }} ({{ $zone }})
Alert id:   {{ $alert['id'] ?? '' }}
@endif

Server & alerts: {{ $url }}

You receive this because you are listed as a notification recipient in the portal settings.
