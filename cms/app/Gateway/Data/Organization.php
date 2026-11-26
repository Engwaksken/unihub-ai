<?php

namespace App\Gateway\Data;

/**
 * Read-only view of a gateway organization. The CMS never stores these.
 */
final class Organization
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $status,
        public readonly ?int $projectCount,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}

    /**
     * @param  array<mixed>  $data  decoded JSON object from the gateway
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            status: (string) ($data['status'] ?? 'unknown'),
            projectCount: isset($data['project_count']) && is_numeric($data['project_count']) ? (int) $data['project_count'] : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        );
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
