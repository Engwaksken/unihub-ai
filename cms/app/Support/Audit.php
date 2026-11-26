<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes CMS audit events (logins, invitations, role/membership changes,
 * session revocations, user disable/enable). Secrets are never recorded:
 * metadata keys that look like credentials are dropped before insert.
 */
final class Audit
{
    private const FORBIDDEN_KEY = '/(pass(word)?|token|secret|recovery|assertion|authorization|cookie|^code$|otp)/i';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $action,
        ?User $actor = null,
        ?User $subjectUser = null,
        array $metadata = [],
        ?string $projectUuid = null,
        ?string $actorLabel = null,
    ): AuditEvent {
        $actor ??= Auth::user();
        $fromConsole = app()->runningInConsole() && ! app()->runningUnitTests();
        $request = $fromConsole ? null : request();

        return AuditEvent::query()->create([
            'occurred_at' => now(),
            'request_id' => RequestId::current(),
            'actor_user_id' => $actor?->getKey(),
            'actor_label' => $actorLabel ?? $actor->email ?? ($fromConsole ? 'console' : null),
            'action' => $action,
            'subject_type' => $subjectUser ? 'user' : null,
            'subject_id' => $subjectUser?->getKey(),
            'gateway_project_uuid' => $projectUuid ? strtolower($projectUuid) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'metadata' => self::sanitize($metadata) ?: null,
        ]);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN_KEY, $key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }
}
