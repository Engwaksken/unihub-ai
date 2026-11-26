<?php

namespace Tests;

use App\Gateway\AssertionSigner;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    public const GATEWAY = 'https://gateway.test/internal/v1';

    public const MGMT_TOKEN = 'test-management-token';

    /** Base64 Ed25519 public key matching the CMS signing key used in tests. */
    protected string $assertionPublicKey;

    protected string $assertionKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();

        [$secret, $public] = AssertionSigner::generateKeypair();
        $this->assertionPublicKey = $public;
        $this->assertionKeyPath = tempnam(sys_get_temp_dir(), 'cms-assertion-');
        file_put_contents($this->assertionKeyPath, $secret);

        config([
            'services.gateway.url' => self::GATEWAY,
            'services.gateway.token' => self::MGMT_TOKEN,
            'services.gateway.assertion_secret_key_path' => $this->assertionKeyPath,
            'services.gateway.assertion_kid' => 'cms-1',
            'session.secure' => false,
        ]);
        $this->app->forgetInstance(AssertionSigner::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->assertionKeyPath) && is_file($this->assertionKeyPath)) {
            @unlink($this->assertionKeyPath);
        }

        parent::tearDown();
    }

    protected function gatewayUrl(string $path): string
    {
        return self::GATEWAY.$path;
    }

    /**
     * A gateway Project resource as documented in management-auth.md.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function projectPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'organization' => ['id' => '6f1d8a8e-1111-4c4c-9a9a-000000000001', 'name' => 'Synthetic Org'],
            'slug' => 'synthetic-'.Str::lower(Str::random(6)),
            'name' => 'Synthetic Project',
            'owner_ref' => 'Pilot lead',
            'contact_email' => 'pilot@example.test',
            'environment' => 'staging',
            'status' => 'active',
            'daily_limit' => 100,
            'rpm_limit' => 5,
            'queue_limit' => 2,
            'allowed_tasks' => ['daily_plan'],
            'created_at' => '2026-11-26T09:00:00Z',
            'updated_at' => '2026-11-26T09:00:00Z',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function organizationPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'name' => 'Synthetic Org',
            'status' => 'active',
            'project_count' => 1,
            'created_at' => '2026-11-26T09:00:00Z',
            'updated_at' => '2026-11-26T09:00:00Z',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function page(array $items, ?string $next = null): PromiseInterface
    {
        return Http::response(['data' => $items, 'next_cursor' => $next], 200);
    }

    /**
     * @param  array<string, list<string>>  $fields
     */
    protected function gatewayError(int $status, string $code, string $message, array $fields = []): PromiseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['details'] = ['fields' => $fields];
        }

        return Http::response(['error' => $error, 'request_id' => (string) Str::uuid()], $status);
    }

    /**
     * Decode and verify an X-Actor-Assertion with the test public key.
     *
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, verified: bool}
     */
    protected function decodeAssertion(string $jws): array
    {
        [$h, $p, $s] = explode('.', $jws);
        $verified = sodium_crypto_sign_verify_detached(
            AssertionSigner::base64UrlDecode($s),
            $h.'.'.$p,
            base64_decode($this->assertionPublicKey),
        );

        return [
            'header' => json_decode(AssertionSigner::base64UrlDecode($h), true),
            'payload' => json_decode(AssertionSigner::base64UrlDecode($p), true),
            'verified' => $verified,
        ];
    }
}
