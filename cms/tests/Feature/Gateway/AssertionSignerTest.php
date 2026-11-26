<?php

namespace Tests\Feature\Gateway;

use App\Enums\Role;
use App\Gateway\AssertionSigner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AssertionSignerTest extends TestCase
{
    use RefreshDatabase;

    private const P1 = '6f1d8a8e-1111-4c4c-9a9a-0000000000c1';

    private const P2 = '6f1d8a8e-1111-4c4c-9a9a-0000000000c2';

    public function test_signature_verifies_with_sodium_and_claims_match_the_contract(): void
    {
        $user = User::factory()->withTwoFactor()
            ->withRoles([Role::ProjectManager, Role::Auditor])
            ->withMemberships([self::P2 => Role::Auditor, self::P1 => Role::ProjectManager])
            ->create(['name' => 'Pat Manager']);

        $now = 1_795_000_000;
        $jws = app(AssertionSigner::class)->sign($user, $now);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $jws, 'compact JWS, base64url without padding');

        $decoded = $this->decodeAssertion($jws);
        $this->assertTrue($decoded['verified'], 'sodium_crypto_sign_verify_detached must accept the signature');
        $this->assertSame(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'cms-1'], $decoded['header']);

        $claims = $decoded['payload'];
        $this->assertSame('unihub-cms', $claims['iss']);
        $this->assertSame('unihub-gateway-mgmt', $claims['aud']);
        $this->assertSame($now, $claims['iat']);
        $this->assertLessThanOrEqual(60, $claims['exp'] - $claims['iat']);
        $this->assertGreaterThan($claims['iat'], $claims['exp']);
        $this->assertTrue(Str::isUuid($claims['jti']));
        $this->assertSame($user->id, $claims['sub']);
        $this->assertSame('Pat Manager', $claims['name']);
        $this->assertSame(['project_manager', 'auditor'], $claims['roles']);
        $this->assertSame([self::P1, self::P2], $claims['projects']);
        // Per-project role: PM on P1 must not become PM on P2 where the user is only auditor.
        $this->assertEquals([self::P1 => 'project_manager', self::P2 => 'auditor'], $claims['project_roles']);
        $this->assertTrue($claims['mfa']);
        $this->assertEqualsCanonicalizing(
            ['iss', 'aud', 'iat', 'exp', 'jti', 'sub', 'name', 'roles', 'projects', 'project_roles', 'mfa'],
            array_keys($claims),
        );
    }

    public function test_owner_assertion_has_empty_projects(): void
    {
        $owner = User::factory()->owner()->create();
        $claims = $this->decodeAssertion(app(AssertionSigner::class)->sign($owner))['payload'];

        $this->assertSame(['owner'], $claims['roles']);
        $this->assertSame([], $claims['projects']);
        $this->assertSame([], $claims['project_roles']);
        $this->assertStringContainsString('"project_roles":{}', base64_decode(strtr(explode('.', app(AssertionSigner::class)->sign($owner))[1], '-_', '+/')));
    }

    public function test_every_assertion_has_a_fresh_jti(): void
    {
        $owner = User::factory()->owner()->create();
        $signer = app(AssertionSigner::class);

        $a = $this->decodeAssertion($signer->sign($owner))['payload']['jti'];
        $b = $this->decodeAssertion($signer->sign($owner))['payload']['jti'];
        $this->assertNotSame($a, $b);
    }

    public function test_tampered_assertion_does_not_verify(): void
    {
        $owner = User::factory()->owner()->create();
        [$h, $p, $s] = explode('.', app(AssertionSigner::class)->sign($owner));

        $payload = json_decode(AssertionSigner::base64UrlDecode($p), true);
        $payload['roles'] = ['owner', 'ops_admin'];
        $forged = $h.'.'.AssertionSigner::base64UrlEncode(json_encode($payload)).'.'.$s;

        $this->assertFalse($this->decodeAssertion($forged)['verified']);
    }

    public function test_refuses_to_sign_without_confirmed_mfa(): void
    {
        $user = User::factory()->withRoles(Role::Owner)->create();

        $this->expectException(LogicException::class);
        app(AssertionSigner::class)->sign($user);
    }

    public function test_refuses_to_sign_for_disabled_user(): void
    {
        $user = User::factory()->owner()->disabled()->create();

        $this->expectException(LogicException::class);
        app(AssertionSigner::class)->sign($user);
    }

    public function test_keypair_command_writes_secret_and_prints_matching_public_key(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cms-keytest-'.Str::random(6);
        $path = $dir.DIRECTORY_SEPARATOR.'assertion.key';

        $this->assertSame(0, Artisan::call('cms:assertion-keypair', ['path' => $path]));
        $output = Artisan::output();

        $secret = base64_decode(trim(file_get_contents($path)), true);
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($secret));
        $public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
        $this->assertStringContainsString($public, $output);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(base64_decode($public)));

        // Never overwrites silently.
        $this->assertSame(1, Artisan::call('cms:assertion-keypair', ['path' => $path]));

        // Refuses the public web root.
        $this->assertSame(1, Artisan::call('cms:assertion-keypair', ['path' => public_path('leak.key')]));
        $this->assertFileDoesNotExist(public_path('leak.key'));

        @unlink($path);
        @rmdir($dir);
    }
}
