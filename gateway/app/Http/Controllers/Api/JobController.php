<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\BaseModel;
use App\Models\Job;
use App\Services\AdmissionService;
use App\Services\JobQueue;
use App\Services\PayloadCipher;
use App\Support\ApiException;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Public job API (PRD §06, docs/api/public-openapi.yaml). */
class JobController extends Controller
{
    public function store(Request $request, AdmissionService $admission): JsonResponse
    {
        $data = $request->json()->all();
        $allowed = ['task', 'input', 'max_tokens', 'idempotency_key'];
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw ApiException::validation('Unknown fields', array_fill_keys(array_values($unknown), ['not allowed']));
        }

        $v = Validator::make($data, [
            'task' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'input' => ['required', 'string', 'min:1', 'max:16000'],
            'max_tokens' => ['sometimes', 'integer', 'min:1', 'max:256'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        if ($v->fails()) {
            throw ApiException::validation('Invalid request', $v->errors()->toArray());
        }
        $req = $v->validated();
        $req['max_tokens'] ??= 200;
        if (! mb_check_encoding($req['input'], 'UTF-8')) {
            throw ApiException::validation('Invalid request', ['input' => ['must be valid UTF-8']]);
        }

        [$job] = $admission->submit($this->key($request), $req);

        return new JsonResponse(['job_id' => $job->id, 'status' => $job->state, 'request_id' => RequestId::current()], 202);
    }

    public function show(Request $request, string $id, PayloadCipher $cipher): JsonResponse
    {
        $job = $this->ownJob($request, $id);
        $job->load('taskVersion.task', 'model');

        $body = [
            'job_id' => $job->id,
            'status' => $job->state,
            'task' => $job->taskVersion->task->key,
            'task_version' => $job->taskVersion->version,
            'model' => $job->model->tag,
            'tokens_in' => $job->tokens_in,
            'tokens_out' => $job->tokens_out,
            'created_at' => BaseModel::iso($job->created_at),
            'started_at' => BaseModel::iso($job->started_at),
            'completed_at' => BaseModel::iso($job->finished_at),
            'expires_at' => BaseModel::iso($job->expires_at),
            'request_id' => RequestId::current(),
        ];

        if ($job->state === Job::COMPLETED) {
            $payload = $job->payload()->first();
            $body['output'] = ($payload?->output_ct !== null && ($job->expires_at === null || $job->expires_at->isFuture()))
                ? $cipher->decrypt($job->id, 'output', $payload->output_ct, $payload->key_id)
                : null;
        }
        if (in_array($job->state, [Job::FAILED, Job::EXPIRED], true) && $job->error_code !== null) {
            $body['error'] = ['code' => $job->error_code, 'message' => $job->error_message ?? 'Job failed'];
        }

        return new JsonResponse($body);
    }

    public function destroy(Request $request, string $id, JobQueue $queue): JsonResponse
    {
        $job = $this->ownJob($request, $id);
        $result = $queue->cancel($job);
        if ($result === 'invalid_state') {
            throw ApiException::invalidState('Only queued jobs can be cancelled');
        }

        return new JsonResponse(['job_id' => $job->id, 'status' => Job::CANCELLED, 'request_id' => RequestId::current()]);
    }

    private function key(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }

    /** Unknown and other-project jobs are indistinguishable (404). */
    private function ownJob(Request $request, string $id): Job
    {
        if (! Str::isUuid($id)) {
            throw ApiException::notFound();
        }

        return Job::where('id', strtolower($id))->where('project_id', $this->key($request)->project_id)->first()
            ?? throw ApiException::notFound();
    }
}
