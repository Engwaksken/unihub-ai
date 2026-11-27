<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WITU brief URL names redirect to the existing modules; they must not bypass
 * authentication (the target routes keep their own auth/permission checks).
 */
class UrlAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_redirects_to_home_and_guests_end_at_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/');
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_api_keys_redirects_to_keys_which_requires_login(): void
    {
        $this->get('/api-keys')->assertRedirect('/keys');
        $this->get('/keys')->assertRedirect(route('login'));
    }
}
