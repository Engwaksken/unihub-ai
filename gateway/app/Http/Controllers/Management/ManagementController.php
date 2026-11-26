<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Management\Actor;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

abstract class ManagementController extends Controller
{
    protected function actor(Request $request): Actor
    {
        return $request->attributes->get('actor');
    }

    protected function audit(Request $request, string $action, string $targetType, ?string $targetId, ?string $projectId = null, array $metadata = []): void
    {
        $actor = $this->actor($request);
        app(AuditLogger::class)->record($actor->ref(), $action, $targetType, $targetId, $projectId, $metadata, $actor->name);
    }

    protected function validate(array $data, array $rules): array
    {
        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            throw ApiException::validation('Invalid request', $v->errors()->toArray());
        }

        return $v->validated();
    }

    protected function uuid(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw ApiException::notFound();
        }

        return strtolower($id);
    }

    /** Cursor pagination, newest first (UUIDv7 ids are time-ordered). */
    protected function page(Request $request, Builder $query, callable $map, string $orderColumn = 'id'): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $paginator = $query->orderByDesc($orderColumn)->cursorPaginate($limit, ['*'], 'cursor', $request->query('cursor'));

        return new JsonResponse([
            'data' => array_map($map, $paginator->items()),
            'next_cursor' => $paginator->nextCursor()?->encode(),
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC range, default last 7 days. */
    protected function range(Request $request): array
    {
        $this->validate($request->query(), ['from' => 'nullable|date', 'to' => 'nullable|date']);
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to'))->utc() : CarbonImmutable::now('UTC');
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from'))->utc() : $to->subDays(7);
        if ($from > $to || $from->diffInDays($to) > 366) {
            throw ApiException::validation('Invalid date range', ['from' => ['must be before to and within 366 days']]);
        }

        return [$from, $to];
    }

    protected function data(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }
}
