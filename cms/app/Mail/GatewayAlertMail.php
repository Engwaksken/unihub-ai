<?php

namespace App\Mail;

use App\Services\CmsSettings;
use App\Support\DisplayTime;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Alert e-mail sent by the scheduled notifier. Plain text only; alert text
 * from the gateway is never interpreted as HTML.
 */
class GatewayAlertMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $alert  gateway alert fields (type, severity, message, first_seen_at, ...)
     */
    public function __construct(
        public readonly array $alert,
        public readonly string $kind = 'gateway', // gateway | local_unreachable | local_recovered
    ) {}

    public function envelope(): Envelope
    {
        $brand = app(CmsSettings::class)->brandName();
        $subject = match ($this->kind) {
            'local_unreachable' => "[{$brand}] Gateway unreachable",
            'local_recovered' => "[{$brand}] Gateway reachable again",
            default => sprintf('[%s] %s alert: %s', $brand, ucfirst((string) ($this->alert['severity'] ?? 'unknown')), (string) ($this->alert['type'] ?? 'alert')),
        };

        return new Envelope(subject: mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 150));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.gateway-alert', with: [
            'alert' => $this->alert,
            'kind' => $this->kind,
            'zone' => DisplayTime::zone(),
            'url' => route('health.index'),
        ]);
    }
}
