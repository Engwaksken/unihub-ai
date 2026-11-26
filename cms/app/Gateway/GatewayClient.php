<?php

namespace App\Gateway;

use App\Gateway\Data\Organization;
use App\Gateway\Data\Project;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayConflictException;
use App\Gateway\Exceptions\GatewayException;
use App\Gateway\Exceptions\GatewayForbiddenException;
use App\Gateway\Exceptions\GatewayNotFoundException;
use App\Gateway\Exceptions\GatewayRateLimitedException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\Exceptions\GatewayValidationException;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Client for the gateway management API (/internal/v1). Every request carries
 * the service bearer token, a freshly signed actor assertion for the acting
 * staff member, the CMS request id and, on mutations, an Idempotency-Key.
 *
 * Requests are never retried automatically: each assertion has a single-use
 * jti, and a retry of a mutation must be an explicit, user-visible action that
 * reuses the same Idempotency-Key.
 */
class GatewayClient
{
    private const MUTATING = ['POST', 'PUT', 'PATCH'];

    private ?User $actor = null;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly AssertionSigner $signer,
        private readonly ?string $baseUrl,
        private readonly ?string $token,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15,
    ) {}

    /**
     * Return a client that acts on behalf of the given staff member.
     */
    public function as(User $user): static
    {
        $clone = clone $this;
        $clone->actor = $user;

        return $clone;
    }

    // ------------------------------------------------------------------
    // Organizations
    // ------------------------------------------------------------------

    /**
     * @param  array{status?: string|null, q?: string|null}  $filters
     * @return GatewayPage<Organization>
     */
    public function listOrganizations(array $filters = [], ?string $cursor = null, int $limit = 25): GatewayPage
    {
        return $this->list('/organizations', $filters, $cursor, $limit)->map(fn (array $row) => Organization::fromArray($row));
    }

    public function getOrganization(string $id): Organization
    {
        return Organization::fromArray($this->data($this->request('GET', '/organizations/'.rawurlencode($id))));
    }

    public function createOrganization(string $name, ?string $idempotencyKey = null): Organization
    {
        return Organization::fromArray($this->data($this->request('POST', '/organizations', ['name' => $name], $idempotencyKey)));
    }

    /**
     * @param  array{name?: string, status?: string}  $changes
     */
    public function updateOrganization(string $id, array $changes, ?string $idempotencyKey = null): Organization
    {
        return Organization::fromArray($this->data($this->request('PATCH', '/organizations/'.rawurlencode($id), $changes, $idempotencyKey)));
    }

    // ------------------------------------------------------------------
    // Projects
    // ------------------------------------------------------------------

    /**
     * @param  array{organization_id?: string|null, status?: string|null, q?: string|null}  $filters
     * @return GatewayPage<Project>
     */
    public function listProjects(array $filters = [], ?string $cursor = null, int $limit = 25): GatewayPage
    {
        return $this->list('/projects', $filters, $cursor, $limit)->map(fn (array $row) => Project::fromArray($row));
    }

    public function getProject(string $id): Project
    {
        return Project::fromArray($this->data($this->request('GET', '/projects/'.rawurlencode($id))));
    }

    /**
     * @param  array<string, mixed>  $attributes  ProjectCreate body
     */
    public function createProject(array $attributes, ?string $idempotencyKey = null): Project
    {
        return Project::fromArray($this->data($this->request('POST', '/projects', $attributes, $idempotencyKey)));
    }

    /**
     * @param  array<string, mixed>  $changes  ProjectPatch body
     */
    public function updateProject(string $id, array $changes, ?string $idempotencyKey = null): Project
    {
        return Project::fromArray($this->data($this->request('PATCH', '/projects/'.rawurlencode($id), $changes, $idempotencyKey)));
    }

    /**
     * Mutation returning the `data` object of the response envelope.
     *
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     */
    public function mutateData(string $method, string $path, array $body, ?string $idempotencyKey = null): array
    {
        return $this->data($this->request($method, $path, $body, $idempotencyKey));
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return GatewayPage<array<mixed>>
     */
    public function list(string $path, array $filters = [], ?string $cursor = null, int $limit = 25): GatewayPage
    {
        $query = array_filter($filters, fn ($v) => $v !== null && $v !== '');
        $query['limit'] = max(1, min(100, $limit));
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $body = $this->request('GET', $path, $query);

        if (! array_key_exists('data', $body) || ! is_array($body['data']) || ! array_is_list($body['data'])) {
            throw new GatewayUnavailableException('The gateway returned an unexpected list response.', 200, 'INVALID_RESPONSE', [], RequestId::current());
        }

        $items = array_values(array_filter($body['data'], 'is_array'));
        $next = isset($body['next_cursor']) && is_string($body['next_cursor']) && $body['next_cursor'] !== '' ? $body['next_cursor'] : null;

        return new GatewayPage($items, $next);
    }

    /**
     * Send one request and return the decoded JSON envelope.
     *
     * @param  array<string, mixed>  $payload  query for GET, JSON body otherwise
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        $method = strtoupper($method);

        return $this->decode($this->send($method, $path, $payload, $idempotencyKey), RequestId::current(), $method, $path);
    }

    /**
     * GET a single-resource envelope and return its `data` object.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function getData(string $path, array $query = []): array
    {
        return $this->data($this->request('GET', $path, array_filter($query, fn ($v) => $v !== null && $v !== '')));
    }

    /**
     * Read-only aggregate cached for up to 30 s (ADR D4). The cache key
     * includes the actor's scope (roles + per-project roles), so users only
     * ever share entries with users who would get the same answer.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function cachedData(string $path, array $query = [], int $ttl = 30): array
    {
        $actor = $this->actor ?? throw new LogicException('GatewayClient::as($user) must be called before making requests.');
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');
        ksort($query);

        $scope = [
            'roles' => $actor->roleValues(),
            'project_roles' => $actor->memberships
                ->mapWithKeys(fn ($m) => [strtolower($m->gateway_project_uuid) => $m->role->value])
                ->sortKeys()->all(),
        ];
        $key = 'gateway:'.hash('sha256', json_encode([$path, $query, $scope], JSON_THROW_ON_ERROR));

        return Cache::remember($key, max(1, min(30, $ttl)), fn () => $this->getData($path, $query));
    }

    /**
     * Stream a non-JSON download (e.g. `format=csv`) straight from the gateway.
     * Error responses are decoded and mapped like any other call.
     *
     * @param  array<string, mixed>  $query
     */
    public function download(string $path, array $query = []): ResponseInterface
    {
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');
        $response = $this->send('GET', $path, $query, null, ['stream' => true], 'text/csv, application/json');

        if (! $response->successful()) {
            $this->decode($response, RequestId::current(), 'GET', $path); // throws
        }

        return $response->toPsrResponse();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options  Guzzle options
     */
    private function send(string $method, string $path, array $payload, ?string $idempotencyKey, array $options = [], string $accept = 'application/json'): Response
    {
        $actor = $this->actor ?? throw new LogicException('GatewayClient::as($user) must be called before making requests.');

        if ($this->baseUrl === null || $this->baseUrl === '' || $this->token === null || $this->token === '') {
            throw new GatewayUnavailableException('The gateway connection is not configured (GATEWAY_MGMT_URL / GATEWAY_MGMT_TOKEN).', null, 'NOT_CONFIGURED');
        }

        $requestId = RequestId::current();

        try {
            $headers = [
                'Authorization' => 'Bearer '.$this->token,
                'X-Actor-Assertion' => $this->signer->sign($actor),
                'X-Request-Id' => $requestId,
                'Accept' => $accept,
            ];
        } catch (LogicException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Gateway actor assertion could not be signed.', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            throw new GatewayUnavailableException('The CMS could not sign the request to the gateway (assertion key not configured).', null, 'SIGNING_FAILED', [], $requestId, $e);
        }

        if (in_array($method, self::MUTATING, true)) {
            $headers['Idempotency-Key'] = $idempotencyKey !== null && Str::isUuid($idempotencyKey)
                ? $idempotencyKey
                : (string) Str::uuid();
        }

        $pending = $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withHeaders($headers)
            ->withOptions($options);

        try {
            $response = match ($method) {
                'GET' => $pending->get($path, $payload),
                'POST' => $pending->asJson()->post($path, $payload),
                'PATCH' => $pending->asJson()->patch($path, $payload),
                'PUT' => $pending->asJson()->put($path, $payload),
                'DELETE' => $pending->delete($path, $payload),
                default => throw new LogicException("Unsupported method {$method}."),
            };
        } catch (ConnectionException $e) {
            Log::warning('Gateway unreachable.', ['request_id' => $requestId, 'method' => $method, 'path' => $path]);
            throw new GatewayUnavailableException('The gateway could not be reached.', null, 'UNREACHABLE', [], $requestId, $e);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $requestId, string $method, string $path): array
    {
        $status = $response->status();
        $json = $this->json($response);

        if ($response->successful()) {
            if ($json === null) {
                throw new GatewayUnavailableException('The gateway returned a response that is not JSON.', $status, 'INVALID_RESPONSE', [], $requestId);
            }

            return $json;
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : "Gateway error (HTTP {$status}).";
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        $gatewayRequestId = is_string($json['request_id'] ?? null) ? $json['request_id'] : $requestId;

        Log::warning('Gateway request failed.', [
            'request_id' => $requestId, 'method' => $method, 'path' => $path, 'status' => $status, 'code' => $code,
        ]);

        $class = match (true) {
            $status === 422 || $code === 'VALIDATION_FAILED' => GatewayValidationException::class,
            $status === 404 || $code === 'NOT_FOUND' => GatewayNotFoundException::class,
            $status === 403 || $code === 'FORBIDDEN' => GatewayForbiddenException::class,
            $status === 409 => GatewayConflictException::class,
            $status === 401 || $code === 'UNAUTHENTICATED' => GatewayAuthenticationException::class,
            $status === 429 => GatewayRateLimitedException::class,
            $status >= 500 => GatewayUnavailableException::class,
            default => GatewayException::class,
        };

        throw new $class($message, $status, $code, $details, $gatewayRequestId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(Response $response): ?array
    {
        try {
            $decoded = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function data(array $body): array
    {
        if (! isset($body['data']) || ! is_array($body['data'])) {
            throw new GatewayUnavailableException('The gateway returned an unexpected response.', null, 'INVALID_RESPONSE', [], RequestId::current());
        }

        return $body['data'];
    }
}
