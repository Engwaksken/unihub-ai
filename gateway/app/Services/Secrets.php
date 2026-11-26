<?php

namespace App\Services;

use App\Support\Base32;
use RuntimeException;

/**
 * Reads gateway secrets from files outside the database and outside the repo
 * (ADR D9–D11). Layout under GATEWAY_SECRETS_PATH:
 *   key_pepper              base64, 32+ random bytes (HMAC pepper for API keys + mgmt token)
 *   payload_keys/<id>.key   base64, 32 bytes (XChaCha20-Poly1305); newest id is active
 *   mgmt_token_digests      one hex HMAC digest per line (two during rotation overlap)
 *   cms_assertion.pub       base64 Ed25519 public key of the CMS
 */
class Secrets
{
    private ?string $pepper = null;

    private ?array $payloadKeys = null;

    public function __construct(private readonly string $path) {}

    public function path(string $file = ''): string
    {
        return rtrim($this->path, '/\\').($file !== '' ? DIRECTORY_SEPARATOR.$file : '');
    }

    public function pepper(): string
    {
        if ($this->pepper === null) {
            $raw = base64_decode(trim((string) @file_get_contents($this->path('key_pepper'))), true);
            if ($raw === false || strlen($raw) < 32) {
                throw new RuntimeException('Gateway key pepper missing or invalid; run gateway:secrets:init.');
            }
            $this->pepper = $raw;
        }

        return $this->pepper;
    }

    public function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, $this->pepper());
    }

    /** @return array<string,string> key id => raw key, sorted ascending */
    public function payloadKeys(): array
    {
        if ($this->payloadKeys === null) {
            $keys = [];
            foreach (glob($this->path('payload_keys').DIRECTORY_SEPARATOR.'*.key') ?: [] as $file) {
                $raw = base64_decode(trim((string) file_get_contents($file)), true);
                if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                    $keys[basename($file, '.key')] = $raw;
                }
            }
            ksort($keys, SORT_STRING);
            if ($keys === []) {
                throw new RuntimeException('No payload encryption key; run gateway:secrets:init.');
            }
            $this->payloadKeys = $keys;
        }

        return $this->payloadKeys;
    }

    /** @return string[] */
    public function mgmtTokenDigests(): array
    {
        $lines = @file($this->path('mgmt_token_digests'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($l) => preg_match('/^[0-9a-f]{64}$/', $l)));
    }

    public function cmsPublicKey(): ?string
    {
        $raw = base64_decode(trim((string) @file_get_contents($this->path('cms_assertion.pub'))), true);

        return ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? $raw : null;
    }

    // ----- provisioning helpers (used by artisan commands and tests) -----

    public function ensureDir(string $dir = ''): void
    {
        $full = $this->path($dir);
        if (! is_dir($full) && ! mkdir($full, 0700, true) && ! is_dir($full)) {
            throw new RuntimeException("Cannot create {$full}");
        }
    }

    public function writeSecretFile(string $file, string $contents, bool $overwrite = false): bool
    {
        $full = $this->path($file);
        if (! $overwrite && file_exists($full)) {
            return false;
        }
        $this->ensureDir(dirname($file) === '.' ? '' : dirname($file));
        file_put_contents($full, $contents, LOCK_EX);
        @chmod($full, 0400);
        $this->pepper = null;
        $this->payloadKeys = null;

        return true;
    }

    public function initialise(): array
    {
        $this->ensureDir();
        $created = [];
        if ($this->writeSecretFile('key_pepper', base64_encode(random_bytes(32)))) {
            $created[] = 'key_pepper';
        }
        $this->ensureDir('payload_keys');
        if (glob($this->path('payload_keys').DIRECTORY_SEPARATOR.'*.key') === []) {
            $created[] = $this->addPayloadKey();
        }

        return $created;
    }

    public function addPayloadKey(): string
    {
        $id = gmdate('YmdHis').Base32::random(4);
        $this->writeSecretFile('payload_keys'.DIRECTORY_SEPARATOR.$id.'.key', base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()));

        return 'payload_keys/'.$id.'.key';
    }

    public function appendMgmtTokenDigest(string $digest, bool $replace): void
    {
        $existing = $replace ? [] : $this->mgmtTokenDigests();
        $existing[] = $digest;
        $existing = array_slice(array_values(array_unique($existing)), -2); // at most two live tokens
        $this->writeSecretFile('mgmt_token_digests', implode("\n", $existing)."\n", true);
    }
}
