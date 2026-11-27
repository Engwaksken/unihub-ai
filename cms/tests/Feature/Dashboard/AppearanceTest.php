<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Services\CmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake([self::GATEWAY.'/*' => Http::response(['data' => []])]);
    }

    private function save(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->from('/settings')->patch('/settings/appearance', $overrides + [
            '_modal' => 'appearance', 'primary_color' => '#1d4ed8', 'accent_color' => '#f59e0b', 'font' => 'system', 'density' => 'compact',
        ]);
    }

    public function test_colours_font_and_density_apply_to_every_page(): void
    {
        $owner = User::factory()->owner()->create();
        $this->save($owner)->assertRedirect('/settings?tab=appearance')->assertSessionHasNoErrors();

        $html = $this->actingAs($owner)->get('/settings')->assertOk()->getContent();
        $this->assertStringContainsString('--burgundy:#1d4ed8', $html);
        $this->assertStringContainsString('--gold:#f59e0b', $html);
        $this->assertStringContainsString('--font:system-ui', $html);
        $this->assertStringContainsString('class="portal density-compact"', $html);
        $this->assertDatabaseHas('audit_events', ['action' => 'settings.cms_updated']);

        // Also on the sign-in page.
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('--burgundy:#1d4ed8', false);
    }

    public function test_primary_colour_too_light_for_white_text_is_refused(): void
    {
        $this->save(User::factory()->owner()->create(), ['primary_color' => '#f2e3b3'])
            ->assertSessionHasErrorsIn('appearance', 'primary_color');
        $this->assertSame('', app(CmsSettings::class)->themeCss());
    }

    public function test_logo_and_favicon_upload_are_served_and_replaced(): void
    {
        $owner = User::factory()->owner()->create();
        $this->save($owner, [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
            'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
        ])->assertSessionHasNoErrors();

        $settings = app(CmsSettings::class);
        $settings->flush();
        $logo = $settings->get('logo_path');
        $this->assertStringStartsWith('branding/logo-', $logo);
        Storage::disk('local')->assertExists($logo);

        $html = $this->actingAs($owner)->get('/overview')->getContent();
        $this->assertMatchesRegularExpression('#<img class="brand-logo" src="[^"]+/branding/logo\?v=#', $html);
        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]+/branding/favicon\?v=#', $html);
        $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/branding/nope')->assertNotFound();

        // A new upload replaces (and deletes) the old file; removal clears it.
        $this->save($owner, ['logo' => UploadedFile::fake()->image('new.png', 100, 30)]);
        Storage::disk('local')->assertMissing($logo);
        $this->save($owner, ['remove_logo' => 1]);
        $settings->flush();
        $this->assertNull($settings->get('logo_path'));
        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_svg_and_oversized_files_are_refused(): void
    {
        $owner = User::factory()->owner()->create();
        $this->save($owner, ['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])
            ->assertSessionHasErrorsIn('appearance', 'logo');
        $this->save($owner, ['favicon' => UploadedFile::fake()->image('big.png')->size(400)])
            ->assertSessionHasErrorsIn('appearance', 'favicon');
    }

    public function test_settings_sections_open_in_modals(): void
    {
        $html = $this->actingAs(User::factory()->owner()->create())->get('/settings')->assertOk()->getContent();
        foreach (['Edit portal settings', 'Edit appearance', 'Send test e-mail'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('name="_modal" value="appearance"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
    }

    public function test_auditors_cannot_change_appearance(): void
    {
        $this->actingAs(User::factory()->auditor([])->create())->patch('/settings/appearance', [])->assertForbidden();
    }
}
