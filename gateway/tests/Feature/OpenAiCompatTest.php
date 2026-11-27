<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Job;
use App\Models\UsageEvent;
use App\Services\JobWaiter;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** OpenAI-compatible /v1/models and /v1/chat/completions (audit G3, phase C). */
class OpenAiCompatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // In production the worker runs as a separate process; here it runs in-process.
        $this->app->instance(JobWaiter::class, new class extends JobWaiter
        {
            public function await(Job $job, int $seconds): Job
            {
                Artisan::call('gateway:work', ['--stop-when-empty' => true]);

                return $job->refresh();
            }
        });
    }

    private function chatProject(): string
    {
        $this->task('chat');
        [, $key] = $this->projectWithKey(['name' => 'Elevate Her'], ['chat']);

        return $key;
    }

    private function chat(string $key, array $body)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/v1/chat/completions', $body);
    }

    public function test_models_lists_only_enabled_installed_models(): void
    {
        $key = $this->chatProject();
        AiModel::create(['tag' => 'qwen2.5:0.5b', 'installed' => true, 'enabled' => false]);

        $this->withHeaders(['Authorization' => 'Bearer '.$key])->getJson('/v1/models')->assertOk()
            ->assertJsonPath('object', 'list')
            ->assertJsonPath('data.0.id', 'qwen2.5:1.5b')
            ->assertJsonCount(1, 'data');
        $this->flushHeaders();
        $this->getJson('/v1/models')->assertStatus(401);
    }

    public function test_chat_completion_returns_openai_shape_and_is_accounted(): void
    {
        Http::fake(['*/api/chat' => Http::response(['message' => ['content' => 'Pick a field you enjoy.'], 'prompt_eval_count' => 42, 'eval_count' => 7])]);
        $key = $this->chatProject();

        $r = $this->chat($key, [
            'model' => 'qwen2.5:1.5b',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a concise career assistant.'],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'One tip for choosing a career?']]],
            ],
            'temperature' => 0.3,
        ])->assertOk();

        $r->assertJsonPath('object', 'chat.completion')
            ->assertJsonPath('choices.0.message.role', 'assistant')
            ->assertJsonPath('choices.0.message.content', 'Pick a field you enjoy.')
            ->assertJsonPath('choices.0.finish_reason', 'stop')
            ->assertJsonPath('usage.prompt_tokens', 42)
            ->assertJsonPath('usage.completion_tokens', 7)
            ->assertJsonPath('usage.total_tokens', 49)
            ->assertJsonPath('model', 'qwen2.5:1.5b');
        $this->assertStringStartsWith('chatcmpl-', $r->json('id'));

        // The operator's task template goes first, then the app's own messages; options pass through.
        Http::assertSent(function (HttpRequest $req) {
            $m = $req->data()['messages'];

            return $m[0] === ['role' => 'system', 'content' => 'You are a concise planning assistant.']
                && $m[1]['content'] === 'You are a concise career assistant.'
                && $m[2] === ['role' => 'user', 'content' => 'One tip for choosing a career?']
                && $req->data()['options']['temperature'] === 0.3
                && $req->data()['options']['num_predict'] === 256;
        });

        $job = Job::sole();
        $this->assertSame(Job::INPUT_CHAT, $job->input_kind);
        $this->assertSame(1, UsageEvent::count()); // shows up in CMS usage reports
    }

    public function test_json_response_format_asks_ollama_for_json(): void
    {
        Http::fake(['*/api/chat' => Http::response(['message' => ['content' => '{"suggested_rate": 1200}'], 'prompt_eval_count' => 30, 'eval_count' => 9])]);
        $key = $this->chatProject();

        $this->chat($key, ['messages' => [['role' => 'user', 'content' => 'Rate for cement?']], 'response_format' => ['type' => 'json_object']])
            ->assertOk()->assertJsonPath('choices.0.message.content', '{"suggested_rate": 1200}');
        Http::assertSent(fn (HttpRequest $req) => ($req->data()['format'] ?? null) === 'json');

        // Plain text stays plain; an unknown type is refused.
        $this->chat($key, ['messages' => [['role' => 'user', 'content' => 'hi']], 'response_format' => ['type' => 'text']])->assertOk();
        Http::assertSent(fn (HttpRequest $req) => ! isset($req->data()['format']) && $req->data()['messages'][1]['content'] === 'hi');
        $this->chat($key, ['messages' => [['role' => 'user', 'content' => 'hi']], 'response_format' => ['type' => 'xml']])->assertStatus(422);
    }

    public function test_reaching_max_tokens_reports_length(): void
    {
        Http::fake(['*/api/chat' => Http::response(['message' => ['content' => 'cut'], 'prompt_eval_count' => 5, 'eval_count' => 20])]);
        $key = $this->chatProject();

        $this->chat($key, ['messages' => [['role' => 'user', 'content' => 'hi']], 'max_tokens' => 20])
            ->assertOk()->assertJsonPath('choices.0.finish_reason', 'length');
    }

    public function test_project_without_chat_task_and_unknown_model_are_refused(): void
    {
        Http::fake();
        [, $plainKey] = $this->projectWithKey(); // allowed: daily_plan only
        $this->chat($plainKey, ['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertStatus(403)->assertJsonPath('error.code', 'TASK_NOT_ALLOWED');

        $key = $this->chatProject();
        $this->chat($key, ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertStatus(404)->assertJsonPath('error.code', 'MODEL_NOT_FOUND')
            ->assertJsonPath('error.details.available', ['qwen2.5:1.5b']);
        Http::assertNothingSent();
    }

    public function test_invalid_requests_are_rejected_with_a_message(): void
    {
        $key = $this->chatProject();

        $this->chat($key, ['messages' => [['role' => 'user', 'content' => 'hi']], 'stream' => true])
            ->assertStatus(422)->assertJsonPath('error.message', 'Streaming is not supported; send "stream": false');
        $this->chat($key, ['messages' => [['role' => 'tool', 'content' => 'x']]])->assertStatus(422);
        $this->chat($key, ['messages' => [['role' => 'system', 'content' => 'only a system prompt']]])->assertStatus(422);
        $this->chat($key, ['messages' => []])->assertStatus(422);
        $this->chat($key, ['messages' => [['role' => 'user', 'content' => 'hi']], 'n' => 2])->assertStatus(422);
        $this->chat('uhk_live_wrong', ['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertStatus(401)->assertJsonPath('error.message', 'Invalid or missing credentials');
    }

    public function test_failed_inference_returns_an_error_not_a_completion(): void
    {
        Http::fake(['*/api/chat' => Http::response(['error' => 'model crashed'], 400)]);
        $key = $this->chatProject();

        $this->chat($key, ['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertStatus(502)->assertJsonPath('error.code', 'INFERENCE_FAILED');
    }

    public function test_timeout_answers_504_and_keeps_the_job(): void
    {
        $this->app->instance(JobWaiter::class, new class extends JobWaiter
        {
            public function await(Job $job, int $seconds): Job
            {
                return $job; // the worker never got to it
            }
        });
        $key = $this->chatProject();

        $r = $this->chat($key, ['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertStatus(504)->assertJsonPath('error.code', 'TIMEOUT');
        $this->assertSame(Job::QUEUED, Job::find($r->json('error.details.job_id'))->state);
    }
}
