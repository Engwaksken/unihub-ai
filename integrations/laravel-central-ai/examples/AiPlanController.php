<?php

// EXAMPLE for the My Digital Diary backend (copy into app/Http/Controllers and adapt).
// Routes (behind the app's normal auth, e.g. Sanctum, used by the Flutter app):
//   Route::middleware(['auth:sanctum', 'throttle:20,1'])->group(function () {
//       Route::post('/ai/daily-plan', [AiPlanController::class, 'store']);
//       Route::get('/ai/requests/{id}', [AiPlanController::class, 'show']);
//       Route::delete('/ai/requests/{id}', [AiPlanController::class, 'destroy']);
//   });

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Unihub\CentralAi\AiRequestService;

class AiPlanController extends Controller
{
    public function __construct(private readonly AiRequestService $ai) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['input' => 'required|string|min:1|max:4000']);
        $r = $this->ai->create($request->user()->id, 'daily_plan', $data['input'], 200);

        return response()->json(AiRequestService::present($r), 202);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $r = $this->ai->findForUser($id, $request->user()->id) ?? abort(404);

        return response()->json(AiRequestService::present($this->ai->refresh($r)));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $r = $this->ai->findForUser($id, $request->user()->id) ?? abort(404);

        return response()->json(AiRequestService::present($this->ai->cancel($r)));
    }
}
