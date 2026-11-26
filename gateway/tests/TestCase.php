<?php

namespace Tests;

use App\Models\AiModel;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVersion;
use App\Services\KeyService;
use App\Services\Secrets;
use App\Support\Base32;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected string $secretsDir;

    protected string $cmsSecretKey;

    protected string $mgmtToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uh-gw-test-'.Str::random(12);
        config(['gateway.secrets_path' => $this->secretsDir, 'gateway.worker.lock_path' => $this->secretsDir.'/worker.lock']);
        config(['gateway.worker.post_timeout_cooldown_seconds' => 0, 'gateway.worker.retry_backoff_seconds' => 0]);
        $this->app->forgetInstance(Secrets::class);
        $secrets = $this->app->make(Secrets::class);
        $secrets->initialise();

        $pair = sodium_crypto_sign_keypair();
        $this->cmsSecretKey = sodium_crypto_sign_secretkey($pair);
        $secrets->writeSecretFile('cms_assertion.pub', base64_encode(sodium_crypto_sign_publickey($pair)), true);

        $this->mgmtToken = 'uhm_'.Base32::base64url(random_bytes(32));
        $secrets->appendMgmtTokenDigest($secrets->hmac($this->mgmtToken), true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->secretsDir);
        parent::tearDown();
    }

    // ---------- fixtures ----------

    protected function model(array $attrs = []): AiModel
    {
        return AiModel::firstOrCreate(['tag' => $attrs['tag'] ?? 'qwen2.5:1.5b'], $attrs + [
            'digest' => 'sha256:65ec06548149', 'licence' => 'Apache-2.0', 'size_bytes' => 986_000_000,
            'installed' => true, 'enabled' => true, 'checked_at' => now(),
        ]);
    }

    protected function task(string $key = 'daily_plan', int $outputCap = 256, string $format = 'text'): Task
    {
        $task = Task::firstOrCreate(['key' => $key], ['description' => 'Test task']);
        if (! $task->currentVersion()) {
            $v = TaskVersion::create([
                'task_id' => $task->id, 'version' => 1, 'system_template' => 'You are a concise planning assistant.',
                'output_cap' => $outputCap, 'output_format' => $format, 'model_id' => $this->model()->id, 'created_by' => 'test',
            ]);
            $v->published_at = now();
            $v->save();
        }

        return $task;
    }

    protected function project(array $attrs = [], array $tasks = ['daily_plan']): Project
    {
        $org = Organization::firstOrCreate(['name' => 'Unihub Solutions'], ['status' => 'active']);
        $project = Project::create($attrs + [
            'organization_id' => $org->id, 'slug' => 'proj-'.Str::lower(Str::random(6)), 'name' => 'Test project',
            'environment' => 'staging', 'status' => 'active', 'daily_limit' => 100, 'rpm_limit' => 50, 'queue_limit' => 2,
        ]);
        foreach ($tasks as $t) {
            $project->tasks()->attach($this->task($t)->id);
        }

        return $project;
    }

    /** @return array{0: Project, 1: string} project + plaintext key */
    protected function projectWithKey(array $attrs = []): array
    {
        $project = $this->project($attrs);
        [, $secret] = app(KeyService::class)->issue($project, 'test');

        return [$project, $secret];
    }

    // ---------- public API ----------

    protected function submit(string $key, array $overrides = [], array $headers = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key] + $headers)->postJson('/v1/jobs', $overrides + [
            'task' => 'daily_plan', 'input' => 'Plan my study day', 'max_tokens' => 200, 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    // ---------- management API ----------

    protected function assertion(array $roles = ['owner'], array $projects = [], array $override = []): string
    {
        $now = time();
        $header = Base32::base64url(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'cms-1']));
        $payload = Base32::base64url(json_encode($override + [
            'iss' => 'unihub-cms', 'aud' => 'unihub-gateway-mgmt', 'iat' => $now, 'exp' => $now + 60,
            'jti' => (string) Str::uuid(), 'sub' => 'user-'.implode('-', $roles), 'name' => 'Test User',
            'roles' => $roles, 'projects' => array_keys($this->projectRoles($roles, $projects)),
            'project_roles' => (object) $this->projectRoles($roles, $projects), 'mfa' => true,
        ]));

        return $header.'.'.$payload.'.'.Base32::base64url(sodium_crypto_sign_detached($header.'.'.$payload, $this->cmsSecretKey));
    }

    /**
     * $projects is either a list (each gets the actor's project-scoped role) or an explicit
     * {uuid: role} map for mixed-role actors.
     */
    protected function projectRoles(array $roles, array $projects): array
    {
        if (! array_is_list($projects)) {
            return $projects;
        }
        $scoped = array_values(array_intersect($roles, ['project_manager', 'auditor']));

        return $scoped === [] ? [] : array_fill_keys($projects, $scoped[0]);
    }

    protected function mgmt(string $method, string $uri, array $data = [], array $roles = ['owner'], array $projects = [], array $headers = []): TestResponse
    {
        $headers += [
            'Authorization' => 'Bearer '.$this->mgmtToken,
            'X-Actor-Assertion' => $this->assertion($roles, $projects),
        ];
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && ! array_key_exists('Idempotency-Key', $headers)) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return $this->withHeaders($headers)->json($method, '/internal/v1'.$uri, $data);
    }
}
