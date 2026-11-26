<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Project;
use App\Support\Base32;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Project API keys (ADR D9): uhk_<live|test>_<prefix>_<secret>.
 * Only HMAC-SHA256(pepper, key) is stored; the secret is returned once at issue.
 */
class KeyService
{
    private const PATTERN = '/^uhk_(live|test)_([0-9a-z]{10})_([A-Za-z0-9_-]{43})$/';

    public function __construct(private readonly Secrets $secrets) {}

    /** @return array{0: ApiKey, 1: string} */
    public function issue(Project $project, string $createdBy, ?string $label = null, ?DateTimeInterface $expiresAt = null, ?ApiKey $rotates = null, int $overlapDays = 7): array
    {
        $environment = $project->environment === 'production' ? 'live' : 'test';

        return DB::transaction(function () use ($project, $createdBy, $label, $expiresAt, $rotates, $overlapDays, $environment) {
            do {
                $prefix = Base32::random(10);
            } while (ApiKey::where('prefix', $prefix)->exists());

            $secret = sprintf('uhk_%s_%s_%s', $environment, $prefix, Base32::base64url(random_bytes(32)));

            $key = ApiKey::create([
                'project_id' => $project->id,
                'label' => $label,
                'environment' => $environment,
                'prefix' => $prefix,
                'digest' => $this->secrets->hmac($secret),
                'rotated_from_id' => $rotates?->id,
                'created_by' => $createdBy,
                'expires_at' => $expiresAt,
            ]);

            if ($rotates !== null) {
                // Old key keeps working until the approved overlap ends (never extended).
                $overlapEnd = now()->addDays($overlapDays);
                if ($rotates->expires_at === null || $rotates->expires_at > $overlapEnd) {
                    $rotates->expires_at = $overlapEnd;
                    $rotates->save();
                }
            }

            return [$key, $secret];
        });
    }

    public function revoke(ApiKey $key): bool
    {
        if ($key->revoked_at !== null) {
            return false;
        }
        $key->forceFill(['revoked_at' => now()])->save();

        return true;
    }

    /** Returns the usable key or null. Constant-time digest comparison; dummy HMAC on unknown prefix. */
    public function verify(?string $token): ?ApiKey
    {
        if (! is_string($token) || ! preg_match(self::PATTERN, $token, $m)) {
            return null;
        }
        $digest = $this->secrets->hmac($token);
        $key = ApiKey::with('project')->where('prefix', $m[2])->first();
        // Compare against a dummy digest for unknown prefixes so timing doesn't reveal which prefixes exist.
        $expected = $key !== null ? $key->digest : str_repeat('0', 64);
        if (! hash_equals($expected, $digest) || $key === null || $key->environment !== $m[1] || ! $key->isUsable()) {
            return null;
        }

        // Throttle last_used_at writes to once a minute per key.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            ApiKey::whereKey($key->id)->update(['last_used_at' => now()->format('Y-m-d H:i:s.u')]);
        }

        return $key;
    }
}
