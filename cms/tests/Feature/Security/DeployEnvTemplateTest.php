<?php

namespace Tests\Feature\Security;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * The WITU production .env is copied from this template (runbook step 5). A value
 * Laravel cannot parse stops every artisan command, including the deploy's
 * migration review, so the template must always parse and keep safe defaults.
 */
class DeployEnvTemplateTest extends TestCase
{
    public function test_witu_env_template_parses_with_production_defaults(): void
    {
        $path = dirname(__DIR__, 4).'/infra/witu/env/cms.env.example';
        $this->assertFileExists($path);

        $env = Dotenv::parse((string) file_get_contents($path));

        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('', $env['APP_KEY']); // generated on the server, never committed
        $this->assertSame('', $env['DB_PASSWORD']);
        $this->assertSame('', $env['MAIL_PASSWORD']);
        $this->assertSame('', $env['GATEWAY_MGMT_TOKEN']);
    }
}
