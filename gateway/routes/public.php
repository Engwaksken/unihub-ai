<?php

use App\Http\Controllers\Api\JobController;
use App\Http\Middleware\AuthenticateProjectKey;
use Illuminate\Support\Facades\Route;

// Public surface: ai.kemmytech.com (PRD §06). Minimal liveness only.
Route::get('/healthz', fn () => response()->json(['status' => 'ok']));

Route::prefix('v1')->middleware([AuthenticateProjectKey::class, 'throttle:project-key'])->group(function () {
    Route::post('/jobs', [JobController::class, 'store']);
    Route::get('/jobs/{id}', [JobController::class, 'show']);
    Route::delete('/jobs/{id}', [JobController::class, 'destroy']);
});
