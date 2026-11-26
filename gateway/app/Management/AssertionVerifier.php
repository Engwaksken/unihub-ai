<?php

namespace App\Management;

use App\Services\Secrets;
use App\Support\ApiException;
use App\Support\Base32;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Verifies the CMS Ed25519 actor assertion (docs/api/management-auth.md). */
class AssertionVerifier
{
    public function __construct(private readonly Secrets $secrets) {}

    public function verify(?string $jws): Actor
    {
        $publicKey = $this->secrets->cmsPublicKey();
        if ($publicKey === null || ! is_string($jws) || substr_count($jws, '.') !== 2) {
            throw ApiException::unauthenticated();
        }
        [$h64, $p64, $s64] = explode('.', $jws);
        $header = json_decode((string) Base32::base64urlDecode($h64), true);
        $claims = json_decode((string) Base32::base64urlDecode($p64), true);
        $sig = Base32::base64urlDecode($s64);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'EdDSA' || ! is_array($claims)
            || ! is_string($sig) || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($sig, $h64.'.'.$p64, $publicKey)) {
            throw ApiException::unauthenticated();
        }

        $cfg = config('gateway.management');
        $now = time();
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        $roles = $claims['roles'] ?? null;
        // {project uuid: role}; decoded as an assoc array ({} → []). `projects` is informational only.
        $projectRoles = $claims['project_roles'] ?? null;

        $valid = ($claims['iss'] ?? null) === $cfg['assertion_issuer']
            && ($claims['aud'] ?? null) === $cfg['assertion_audience']
            && is_int($iat) && is_int($exp)
            && $exp > $now
            && $exp - $iat <= $cfg['assertion_max_ttl']
            && $iat <= $now + $cfg['assertion_clock_skew']
            && is_string($claims['jti'] ?? null) && strlen($claims['jti']) >= 16 && strlen($claims['jti']) <= 64
            && is_string($claims['sub'] ?? null) && $claims['sub'] !== ''
            && ($claims['mfa'] ?? null) === true
            && is_array($roles) && $roles !== [] && array_is_list($roles) && array_diff($roles, Capabilities::ROLES) === []
            && is_array($projectRoles) && ($projectRoles === [] || ! array_is_list($projectRoles));
        if ($valid) {
            foreach ($projectRoles as $projectId => $role) {
                // Only project-scoped roles may be per-project, and only roles the user actually holds.
                if (! is_string($projectId) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $projectId)
                    || ! in_array($role, Capabilities::PROJECT_SCOPED_ROLES, true) || ! in_array($role, $roles, true)) {
                    $valid = false;
                    break;
                }
            }
        }
        if (! $valid) {
            throw ApiException::unauthenticated();
        }

        // Single-use nonce (replay protection).
        try {
            DB::table('assertion_nonces')->insert(['jti' => $claims['jti'], 'expires_at' => date('Y-m-d H:i:s', $exp + 300)]);
        } catch (UniqueConstraintViolationException) {
            throw ApiException::unauthenticated();
        }

        return new Actor($claims['sub'], is_string($claims['name'] ?? null) ? mb_substr($claims['name'], 0, 120) : null, array_values(array_unique($roles)), $projectRoles);
    }
}
