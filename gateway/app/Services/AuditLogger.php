<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Support\RequestId;

/**
 * Append-only audit (PRD §04). Metadata must contain only safe values: field names,
 * before/after of policy fields, key prefixes. Never secrets, digests or payloads.
 */
class AuditLogger
{
    private const FORBIDDEN_KEYS = ['secret', 'digest', 'input', 'output', 'token', 'password', 'system_template_body'];

    public function record(string $actorRef, string $action, string $targetType, ?string $targetId, ?string $projectId = null, array $metadata = [], ?string $actorName = null): AuditEvent
    {
        foreach (array_keys($metadata) as $k) {
            if (in_array(strtolower((string) $k), self::FORBIDDEN_KEYS, true)) {
                unset($metadata[$k]);
            }
        }

        return AuditEvent::create([
            'actor_ref' => $actorRef,
            'actor_name' => $actorName,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'project_id' => $projectId,
            'metadata' => $metadata ?: null,
            'request_id' => RequestId::current(),
        ]);
    }

    /** Before/after diff of changed attributes (only those listed). */
    public static function diff(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $after) && ($before[$f] ?? null) !== $after[$f]) {
                $changes[$f] = ['from' => $before[$f] ?? null, 'to' => $after[$f]];
            }
        }

        return $changes;
    }
}
