<?php

namespace App\Gateway\Data;

/**
 * Read-only view of a gateway project (docs/api/management-auth.md "Project").
 */
final class Project
{
    public const STATUSES = ['active', 'disabled', 'archived'];

    public const ENVIRONMENTS = ['staging', 'production'];

    /**
     * @param  list<string>  $allowedTasks
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $organizationId,
        public readonly ?string $organizationName,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $ownerRef,
        public readonly ?string $contactEmail,
        public readonly string $environment,
        public readonly string $status,
        public readonly ?int $dailyLimit,
        public readonly ?int $rpmLimit,
        public readonly ?int $queueLimit,
        public readonly array $allowedTasks,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}

    /**
     * @param  array<mixed>  $data  decoded JSON object from the gateway
     */
    public static function fromArray(array $data): self
    {
        $org = is_array($data['organization'] ?? null) ? $data['organization'] : [];
        $int = fn (string $k) => isset($data[$k]) && is_numeric($data[$k]) ? (int) $data[$k] : null;
        $str = fn (string $k) => isset($data[$k]) && is_scalar($data[$k]) ? (string) $data[$k] : null;

        return new self(
            id: strtolower((string) ($data['id'] ?? '')),
            organizationId: isset($org['id']) ? (string) $org['id'] : $str('organization_id'),
            organizationName: isset($org['name']) ? (string) $org['name'] : null,
            slug: (string) ($data['slug'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            ownerRef: $str('owner_ref'),
            contactEmail: $str('contact_email'),
            environment: (string) ($data['environment'] ?? ''),
            status: (string) ($data['status'] ?? 'unknown'),
            dailyLimit: $int('daily_limit'),
            rpmLimit: $int('rpm_limit'),
            queueLimit: $int('queue_limit'),
            allowedTasks: array_values(array_map('strval', array_filter((array) ($data['allowed_tasks'] ?? []), 'is_scalar'))),
            createdAt: $str('created_at'),
            updatedAt: $str('updated_at'),
        );
    }
}
