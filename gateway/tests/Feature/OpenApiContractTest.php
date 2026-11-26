<?php

namespace Tests\Feature;

use App\Models\Job;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Tests\TestCase;

/**
 * Contract tests: real gateway responses must validate against the OpenAPI files in
 * docs/api (PRD §06 "Maintain OpenAPI 3 specification"). If this fails, either the
 * code or the spec drifted — fix whichever is wrong, never just the test.
 */
class OpenApiContractTest extends TestCase
{
    private function assertMatchesSpec(string $spec, string $method, string $path, TestResponse $response): void
    {
        $validator = (new ValidatorBuilder)->fromYamlFile(base_path('../docs/api/'.$spec))->getResponseValidator();
        $f = new Psr17Factory;
        $psr = (new PsrHttpFactory($f, $f, $f, $f))->createResponse($response->baseResponse);
        $validator->validate(new OperationAddress($path, strtolower($method)), $psr);
        $this->addToAssertionCount(1);
    }

    private function pub(string $method, string $path, TestResponse $r): void
    {
        $this->assertMatchesSpec('public-openapi.yaml', $method, $path, $r);
    }

    public function test_public_api_responses_match_spec(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'Plan: 08:00 notes'], 'prompt_eval_count' => 20, 'eval_count' => 9])]);
        [, $key] = $this->projectWithKey(['queue_limit' => 1]);

        $this->pub('GET', '/healthz', $this->getJson('/healthz'));

        $accepted = $this->submit($key, ['idempotency_key' => 'contract-0001'])->assertStatus(202);
        $this->pub('POST', '/v1/jobs', $accepted);
        $this->pub('POST', '/v1/jobs', $this->submit($key)->assertStatus(429));                       // QUEUE_FULL + Retry-After
        $this->pub('POST', '/v1/jobs', $this->submit($key, ['idempotency_key' => 'contract-0001', 'input' => 'changed'])->assertStatus(409));
        $this->pub('POST', '/v1/jobs', $this->submit($key, ['max_tokens' => 9999])->assertStatus(422));
        $this->pub('POST', '/v1/jobs', $this->submit('uhk_test_bad')->assertStatus(401));
        $this->pub('POST', '/v1/jobs', $this->submit($key, ['task' => 'nope'])->assertStatus(403));

        $id = $accepted->json('job_id');
        $this->pub('GET', '/v1/jobs/{id}', $this->withToken($key)->getJson("/v1/jobs/{$id}")->assertOk()); // queued
        Artisan::call('gateway:work', ['--stop-when-empty' => true]);
        $this->pub('GET', '/v1/jobs/{id}', $this->withToken($key)->getJson("/v1/jobs/{$id}")->assertOk()); // completed + output
        $this->pub('GET', '/v1/jobs/{id}', $this->withToken($key)->getJson('/v1/jobs/00000000-0000-0000-0000-000000000000')->assertStatus(404));
        $this->pub('DELETE', '/v1/jobs/{id}', $this->withToken($key)->deleteJson("/v1/jobs/{$id}")->assertStatus(409));

        $queued = $this->submit($key)->json('job_id');
        $this->pub('DELETE', '/v1/jobs/{id}', $this->withToken($key)->deleteJson("/v1/jobs/{$queued}")->assertOk());
    }

    public function test_failed_job_shape_matches_spec(): void
    {
        Http::fake(['*' => Http::response(['error' => 'x'], 400)]);
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');
        Artisan::call('gateway:work', ['--stop-when-empty' => true]);
        $this->assertSame(Job::FAILED, Job::find($id)->state);
        $this->pub('GET', '/v1/jobs/{id}', $this->withToken($key)->getJson("/v1/jobs/{$id}")->assertOk()->assertJsonPath('error.code', 'INFERENCE_FAILED'));
    }

    public function test_management_project_responses_match_spec(): void
    {
        $this->task('daily_plan');
        $org = $this->mgmt('POST', '/organizations', ['name' => 'Contract Org'])->json('data.id');
        $created = $this->mgmt('POST', '/projects', ['organization_id' => $org, 'slug' => 'contract-proj', 'name' => 'Contract', 'environment' => 'staging', 'allowed_tasks' => ['daily_plan']])->assertStatus(201);
        $spec = 'management-openapi.yaml';
        $this->assertMatchesSpec($spec, 'POST', '/projects', $created);
        $id = $created->json('data.id');
        $this->assertMatchesSpec($spec, 'GET', '/projects/{id}', $this->mgmt('GET', "/projects/{$id}")->assertOk());
        $this->assertMatchesSpec($spec, 'GET', '/projects', $this->mgmt('GET', '/projects')->assertOk());
        $this->assertMatchesSpec($spec, 'POST', '/projects/{id}/keys', $this->mgmt('POST', "/projects/{$id}/keys", ['label' => 'contract'])->assertStatus(201));
        $this->assertMatchesSpec($spec, 'POST', '/projects', $this->mgmt('POST', '/projects', ['organization_id' => $org, 'slug' => 'x', 'name' => 'X', 'environment' => 'staging'])->assertStatus(422));
    }
}
