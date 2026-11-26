<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\UsageEvent;
use App\Services\JobQueue;
use App\Services\KeyService;
use App\Services\Ollama\OllamaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WorkerTest extends TestCase
{
    private function fakeOllama(string $content = 'Morning: review notes.', int $status = 200): void
    {
        Http::fake(['127.0.0.1:11434/api/chat' => Http::response(
            ['message' => ['role' => 'assistant', 'content' => $content], 'done' => true, 'prompt_eval_count' => 31, 'eval_count' => 12],
            $status,
        )]);
    }

    private function work(array $options = ['--stop-when-empty' => true]): int
    {
        return Artisan::call('gateway:work', $options);
    }

    public function test_worker_completes_job_and_records_usage_once(): void
    {
        $this->fakeOllama();
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key, ['max_tokens' => 200])->json('job_id');

        $this->assertSame(0, $this->work());

        $this->withToken($key)->getJson("/v1/jobs/{$id}")->assertOk()
            ->assertJson(['status' => 'completed', 'output' => 'Morning: review notes.', 'model' => 'qwen2.5:1.5b', 'tokens_in' => 31, 'tokens_out' => 12]);
        $this->assertSame(1, UsageEvent::where('job_id', $id)->count());
        $this->assertSame('completed', UsageEvent::where('job_id', $id)->value('status'));

        Http::assertSent(function (Request $r) {
            $b = $r->data();

            return $r->url() === 'http://127.0.0.1:11434/api/chat'
                && $b['stream'] === false && $b['model'] === 'qwen2.5:1.5b'
                && $b['options']['num_ctx'] === 2048 && $b['options']['num_thread'] === 2
                && $b['options']['num_predict'] <= 256
                && $b['messages'][0]['role'] === 'system' && $b['messages'][1]['content'] === 'Plan my study day'
                && ! isset($b['tools']);
        });
    }

    public function test_round_robin_between_projects(): void
    {
        $order = [];
        Http::fake(function (Request $r) use (&$order) {
            $order[] = $r->data()['messages'][1]['content'];

            return Http::response(['message' => ['content' => 'ok']]);
        });
        [, $a] = $this->projectWithKey(['queue_limit' => 3]);
        [, $b] = $this->projectWithKey();
        $this->submit($a, ['input' => 'A1']);
        $this->submit($a, ['input' => 'A2']);
        $this->submit($a, ['input' => 'A3']);
        $this->submit($b, ['input' => 'B1']);

        $this->work();
        $this->assertSame(['A1', 'B1', 'A2', 'A3'], $order);
    }

    public function test_transient_failure_retries_once_then_succeeds(): void
    {
        Http::fakeSequence('127.0.0.1:11434/*')
            ->push(['error' => 'busy'], 500)
            ->push(['message' => ['content' => 'second time lucky']]);
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');

        $this->work();

        $job = Job::find($id);
        $this->assertSame('completed', $job->state);
        $this->assertSame(2, $job->attempts);
        $this->assertSame(1, UsageEvent::where('job_id', $id)->count());
    }

    public function test_transient_failure_is_retried_at_most_once(): void
    {
        $this->fakeOllama('x', 503);
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');

        $this->work();

        $job = Job::find($id);
        $this->assertSame('failed', $job->state);
        $this->assertSame(2, $job->attempts);
        $this->assertSame('INFERENCE_FAILED', $job->error_code);
        Http::assertSentCount(2);
        $this->withToken($key)->getJson("/v1/jobs/{$id}")->assertJsonPath('error.code', 'INFERENCE_FAILED');
    }

    public function test_timeout_fails_without_retry(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds'));
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');

        $this->work();

        $this->assertSame('failed', Job::find($id)->state);
        $this->assertSame('INFERENCE_TIMEOUT', Job::find($id)->error_code);
        $this->assertSame(1, Job::find($id)->attempts);
    }

    public function test_non_retryable_4xx_and_invalid_json_output(): void
    {
        $this->fakeOllama('not json');
        $this->task('extract_json', 128, 'json');
        $project = $this->project([], ['extract_json']);
        [, $key] = app(KeyService::class)->issue($project, 'test');
        $id = $this->submit($key, ['task' => 'extract_json', 'max_tokens' => 100])->json('job_id');

        $this->work();

        $this->assertSame('OUTPUT_INVALID', Job::find($id)->error_code);
        $this->assertSame(1, Job::find($id)->attempts);
    }

    public function test_restart_recovers_orphaned_running_job_without_duplicate_completion(): void
    {
        $this->fakeOllama('recovered');
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');

        // Simulate a worker that claimed the job and then died.
        $queue = app(JobQueue::class);
        $claimed = $queue->claim('dead-worker');
        $this->assertSame($id, $claimed->id);

        $this->work();

        $job = Job::find($id);
        $this->assertSame('completed', $job->state);
        $this->assertSame(2, $job->attempts);

        // The dead worker "wakes up" and tries to complete: it no longer owns the job.
        $this->assertFalse($queue->complete($claimed, 'dead-worker', 'x', 1, 1));
        $this->assertSame('lost', $queue->fail($claimed, 'dead-worker', 'INFERENCE_FAILED', 'x', true));
        $this->assertSame(1, UsageEvent::where('job_id', $id)->count());
    }

    public function test_orphan_on_last_attempt_fails_with_worker_lost(): void
    {
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');
        Job::whereKey($id)->update(['state' => 'running', 'attempts' => 2, 'worker_id' => 'dead', 'lease_until' => now()->subMinute()->format('Y-m-d H:i:s.u')]);

        $this->work();

        $this->assertSame('failed', Job::find($id)->state);
        $this->assertSame('WORKER_LOST', Job::find($id)->error_code);
    }

    public function test_second_worker_refuses_to_start_while_lock_held(): void
    {
        $path = config('gateway.worker.lock_path');
        @mkdir(dirname($path), 0700, true);
        $fh = fopen($path, 'c');
        $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB));

        $this->assertSame(1, $this->work());
        $this->assertStringContainsString('Another gateway worker', Artisan::output());

        flock($fh, LOCK_UN);
        fclose($fh);
    }

    public function test_stale_queued_jobs_expire(): void
    {
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');
        Job::whereKey($id)->update(['queue_deadline_at' => now()->subSecond()->format('Y-m-d H:i:s.u')]);

        $this->work();

        $this->withToken($key)->getJson("/v1/jobs/{$id}")->assertJson(['status' => 'expired', 'error' => ['code' => 'QUEUE_TIMEOUT']]);
        Http::assertNothingSent();
    }

    public function test_payloads_are_encrypted_at_rest_and_never_logged(): void
    {
        $canary = 'CANARY-'.bin2hex(random_bytes(8));
        $this->fakeOllama("answer {$canary}-OUT");
        $logged = [];
        Log::listen(function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key, ['input' => "plan {$canary}"])->json('job_id');
        $this->work();

        $row = (array) DB::table('job_payloads')->where('job_id', $id)->first();
        $this->assertStringNotContainsString($canary, (string) $row['input_ct']);
        $this->assertStringNotContainsString($canary, (string) $row['output_ct']);
        foreach (['jobs', 'usage_events', 'audit_events'] as $table) {
            $this->assertStringNotContainsString($canary, json_encode(DB::table($table)->get()), "{$table} leaks payload");
        }
        $this->assertStringNotContainsString($canary, implode("\n", $logged));
        $this->withToken($key)->getJson("/v1/jobs/{$id}")->assertJsonPath('output', "answer {$canary}-OUT");
    }

    public function test_purge_removes_expired_payloads_and_output_becomes_unavailable(): void
    {
        $this->fakeOllama();
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');
        $this->work();
        Job::whereKey($id)->update(['expires_at' => now()->subMinute()->format('Y-m-d H:i:s.u')]);

        Artisan::call('gateway:purge');

        $this->assertNull(DB::table('job_payloads')->where('job_id', $id)->first());
        $this->withToken($key)->getJson("/v1/jobs/{$id}")->assertOk()->assertJson(['status' => 'completed', 'output' => null]);
    }

    public function test_ollama_url_must_be_loopback(): void
    {
        $this->expectException(\RuntimeException::class);
        new OllamaClient('http://10.0.0.5:11434');
    }
}
