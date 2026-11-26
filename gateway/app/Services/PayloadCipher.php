<?php

namespace App\Services;

use RuntimeException;

/**
 * XChaCha20-Poly1305 encryption of job payloads (ADR D10). AAD binds ciphertext
 * to job id + field so rows cannot be swapped. Keys live outside the DB.
 */
class PayloadCipher
{
    public function __construct(private readonly Secrets $secrets) {}

    /** @return array{0: string, 1: string} [ciphertext (nonce||ct), key id] */
    public function encrypt(string $jobId, string $field, string $plaintext): array
    {
        $keys = $this->secrets->payloadKeys();
        $keyId = array_key_last($keys);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $jobId.'|'.$field, $nonce, $keys[$keyId]);

        return [$nonce.$ct, $keyId];
    }

    public function encryptWithKey(string $jobId, string $field, string $plaintext, string $keyId): string
    {
        $key = $this->secrets->payloadKeys()[$keyId] ?? throw new RuntimeException('Unknown payload key id');
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $jobId.'|'.$field, $nonce, $key);
    }

    public function decrypt(string $jobId, string $field, string $blob, string $keyId): string
    {
        $key = $this->secrets->payloadKeys()[$keyId] ?? throw new RuntimeException('Unknown payload key id');
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($blob, $n), $jobId.'|'.$field, substr($blob, 0, $n), $key);
        if ($plain === false) {
            throw new RuntimeException('Payload decryption failed');
        }

        return $plain;
    }
}
