<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_registration_route(): void
    {
        $this->assertFalse(Route::has('register'));

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Mallory', 'email' => 'mallory@example.test',
            'password' => 'Secret-Password-1', 'password_confirmation' => 'Secret-Password-1',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_page_does_not_offer_sign_up(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('/register')->assertSee('invitation only');
    }
}
