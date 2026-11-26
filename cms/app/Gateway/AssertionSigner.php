<?php

namespace App\Gateway;

use App\Models\User;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * Signs the X-Actor-Assertion compact JWS (EdDSA / Ed25519) exactly as
 * specified in docs/api/management-auth.md.
 */
class AssertionSigner
{
    public const ISSUER = 'unihub-cms';

    public const AUDIENCE = 'unihub-gateway-mgmt';

    public const MAX_TTL = 60;

    private ?string $secretKey = null;

    public function __construct(
        private readonly ?string $secretKeyPath,
        private readonly string $kid = 'cms-1',
        private readonly int $ttl = self::MAX_TTL,
    ) {}

    /**
     * Build and sign an assertion for the user. Only active users who have
     * completed MFA enrolment may act on the gateway.
     */
    public function sign(User $user, ?int $now = null): string
    {
        if ($user->isDisabled()) {
            throw new LogicException('Refusing to sign an actor assertion for a disabled user.');
        }
        // System accounts have no interactive login at all (they cannot sign in,
        // see FortifyServiceProvider), so there is no session to protect with MFA.
        if (! $user->isSystem() && ! $user->hasConfirmedTwoFactor()) {
            throw new LogicException('Refusing to sign an actor assertion for a user without confirmed MFA.');
        }

        $iat = $now ?? time();

        $header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $this->kid];
        $payload = [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'iat' => $iat,
            'exp' => $iat + min(max($this->ttl, 1), self::MAX_TTL),
            'jti' => (string) Str::uuid(),
            'sub' => (string) $user->getKey(),
            'name' => (string) $user->name,
            'roles' => $user->roleValues(),
            'projects' => $user->assignedProjectUuids(),
            // Which role applies on which project (management-auth.md). Encoded as a JSON
            // object even when empty, so the gateway never confuses it with a list.
            'project_roles' => (object) $user->memberships
                ->mapWithKeys(fn ($m) => [strtolower($m->gateway_project_uuid) => $m->role->value])
                ->sortKeys()
                ->all(),
            'mfa' => true,
        ];

        $signingInput = self::base64UrlEncode(self::json($header)).'.'.self::base64UrlEncode(self::json($payload));
        $signature = sodium_crypto_sign_detached($signingInput, $this->secretKey());

        return $signingInput.'.'.self::base64UrlEncode($signature);
    }

    /**
     * @return array{0: string, 1: string} [base64 secret key (64 bytes), base64 public key (32 bytes)]
     */
    public static function generateKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
        sodium_memzero($keypair);

        return [base64_encode($secret), base64_encode($public)];
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    private function secretKey(): string
    {
        if ($this->secretKey !== null) {
            return $this->secretKey;
        }

        $path = $this->secretKeyPath;
        if ($path === null || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The actor assertion secret key file (CMS_ASSERTION_SECRET_KEY_PATH) is missing or unreadable.');
        }

        $key = base64_decode(trim((string) file_get_contents($path)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The actor assertion secret key file does not contain a base64-encoded 64-byte Ed25519 secret key.');
        }

        return $this->secretKey = $key;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
