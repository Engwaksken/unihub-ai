<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelsTasksTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = '6f1d8a8e-1111-4c4c-9a9a-0000000004a1';

    private function model(array $overrides = []): array
    {
        return array_merge([
            'id' => self::MODEL, 'tag' => 'qwen2.5:1.5b', 'digest' => 'sha256:abcdef0123456789abcdef', 'licence' => null,
            'size_bytes' => 986061892, 'installed' => true, 'enabled' => false, 'checked_at' => '2026-11-26T09:00:00Z',
        ], $overrides);
    }

    private function task(): array
    {
        return [
            'id' => '6f1d8a8e-1111-4c4c-9a9a-0000000004b2', 'key' => 'daily_plan', 'description' => 'Plan the day', 'current_version' => 1,
            'versions' => [
                ['id' => 'v1', 'version' => 1, 'system_template' => 'You plan days. <script>alert(1)</script>', 'output_cap' => 200, 'output_format' => 'text',
                    'model_id' => self::MODEL, 'published_at' => '2026-11-25T09:00:00Z', 'created_by' => 'cms:x', 'created_at' => '2026-11-25T08:00:00Z'],
                ['id' => 'v2', 'version' => 2, 'system_template' => 'Draft template text', 'output_cap' => 256, 'output_format' => 'json',
                    'model_id' => self::MODEL, 'published_at' => null, 'created_by' => 'cms:x', 'created_at' => '2026-11-26T08:00:00Z'],
            ],
            'created_at' => '2026-11-25T08:00:00Z',
        ];
    }

    private function fakeGateway(): void
    {
        Http::fake([
            self::GATEWAY.'/models/refresh' => Http::response(['data' => [$this->model()]]),
            self::GATEWAY.'/models/*' => fn (Request $r) => Http::response(['data' => $this->model($r->data())]),
            self::GATEWAY.'/models*' => $this->page([$this->model()]),
            self::GATEWAY.'/tasks/*/versions/*/publish' => Http::response(['data' => $this->task()]),
            self::GATEWAY.'/tasks?*' => $this->page([$this->task()]),
            self::GATEWAY.'/tasks' => Http::response(['data' => $this->task()], 201),
        ]);
    }

    public function test_models_list_for_every_role_but_actions_only_for_owner_and_ops(): void
    {
        $this->fakeGateway();

        $this->actingAs(User::factory()->opsAdmin()->create())->get('/models')->assertOk()
            ->assertSee('qwen2.5:1.5b')->assertSee('940.4 MB')->assertSee('Not recorded')
            ->assertSee('Refresh installed models')->assertSee('Enable…');

        $pm = User::factory()->projectManager([])->create();
        $this->actingAs($pm)->get('/models')->assertOk()->assertSee('qwen2.5:1.5b')
            ->assertDontSee('Refresh installed models')->assertDontSee('Enable…');
        $this->actingAs($pm)->post('/models/refresh')->assertForbidden();
        $this->actingAs($pm)->patch('/models/'.self::MODEL, ['enabled' => 1, 'licence' => 'x'])->assertForbidden();
    }

    public function test_refresh_posts_and_never_offers_pull_or_shell(): void
    {
        $this->fakeGateway();
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)->get('/models')->getContent();
        // Only one mutating form on the page, and it is the refresh.
        preg_match_all('#<form method="POST" action="([^"]+)"#', $html, $m);
        $this->assertEqualsCanonicalizing([route('models.refresh'), route('models.update', self::MODEL), route('logout')], array_values(array_unique($m[1])));
        $this->assertDoesNotMatchRegularExpression('#action="[^"]*(pull|delete|shell|exec)#i', $html);

        $this->actingAs($owner)->post('/models/refresh')->assertRedirect('/models');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/models/refresh'));
    }

    public function test_enable_requires_licence(): void
    {
        $this->fakeGateway();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->patch('/models/'.self::MODEL, ['enabled' => 1, 'licence' => ''])->assertSessionHasErrors('licence');

        $this->actingAs($owner)->patch('/models/'.self::MODEL, ['enabled' => 1, 'licence' => 'Apache-2.0'])->assertRedirect('/models');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['enabled' => true, 'licence' => 'Apache-2.0']);
    }

    public function test_enable_conflict_surfaces(): void
    {
        Http::fake([self::GATEWAY.'/models/*' => $this->gatewayError(409, 'INVALID_STATE', 'Only one model may be enabled; disable the current model first')]);

        $this->actingAs(User::factory()->owner()->create())->from('/models')
            ->patch('/models/'.self::MODEL, ['enabled' => 1, 'licence' => 'MIT'])
            ->assertSessionHasErrors(['form' => 'Only one model may be enabled; disable the current model first']);
    }

    public function test_tasks_list_escapes_templates_and_marks_live_and_draft(): void
    {
        $this->fakeGateway();

        $this->actingAs(User::factory()->auditor([])->create())->get('/tasks')->assertOk()
            ->assertSee('daily_plan')->assertSee('Live')->assertSee('Draft')
            ->assertSee('<pre class="template">You plan days. &lt;script&gt;alert(1)&lt;/script&gt;</pre>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('New task or version')
            ->assertDontSee('Publish version');
    }

    public function test_owner_can_publish_drafts_only_with_confirmation(): void
    {
        $this->fakeGateway();
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)->get('/tasks')->getContent();
        $this->assertSame(1, substr_count($html, '/tasks/daily_plan/versions/'), 'only the draft (v2) offers publish');
        $this->assertMatchesRegularExpression('#<dialog[^>]*>\s*<form method="POST" action="[^"]+/tasks/daily_plan/versions/2/publish"#', $html);

        $this->actingAs($owner)->post('/tasks/daily_plan/versions/2/publish')->assertRedirect('/tasks');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/tasks/daily_plan/versions/2/publish'));
    }

    public function test_create_draft_version_validates_and_posts(): void
    {
        $this->fakeGateway();
        $ops = User::factory()->opsAdmin()->create();

        $this->actingAs($ops)->get('/tasks/versions/create?key=daily_plan')->assertOk()->assertSee('value="daily_plan"', false)->assertSee('qwen2.5:1.5b');

        $this->actingAs($ops)->post('/tasks', [
            'key' => 'daily_plan', 'system_template' => 'short', 'output_cap' => 300, 'output_format' => 'xml', 'model_id' => self::MODEL,
        ])->assertSessionHasErrors(['system_template', 'output_cap', 'output_format']);

        $this->actingAs($ops)->post('/tasks', [
            'key' => 'daily_plan', 'system_template' => 'You are a helpful planner.', 'output_cap' => 256, 'output_format' => 'json', 'model_id' => self::MODEL,
        ])->assertRedirect('/tasks');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/tasks') && $r->data() === [
            'key' => 'daily_plan', 'system_template' => 'You are a helpful planner.', 'output_cap' => 256, 'output_format' => 'json', 'model_id' => self::MODEL,
        ]);
    }

    public function test_gateway_validation_on_task_create_lands_on_fields(): void
    {
        Http::fake([self::GATEWAY.'/tasks' => $this->gatewayError(422, 'VALIDATION_FAILED', 'Unknown model', ['model_id' => ['not found']])]);

        $this->actingAs(User::factory()->owner()->create())->from('/tasks/versions/create')->post('/tasks', [
            'key' => 'daily_plan', 'system_template' => 'You are a helpful planner.', 'output_cap' => 100, 'output_format' => 'text', 'model_id' => self::MODEL,
        ])->assertSessionHasErrors(['model_id' => 'not found']);
    }
}
