<?php

use App\Http\Controllers\Management\JobAdminController;
use App\Http\Controllers\Management\KeyController;
use App\Http\Controllers\Management\ModelController;
use App\Http\Controllers\Management\OpsController;
use App\Http\Controllers\Management\OrganizationController;
use App\Http\Controllers\Management\ProjectController;
use App\Http\Controllers\Management\ReportController;
use App\Http\Controllers\Management\TaskController;
use Illuminate\Support\Facades\Route;

// Management surface: mgmt-ai.kemmytech.com/internal/v1 (PRD §07). Auth applied in bootstrap/app.php.

Route::get('/summary', [ReportController::class, 'summary']);
Route::get('/usage', [ReportController::class, 'usage']);

Route::get('/organizations', [OrganizationController::class, 'index']);
Route::post('/organizations', [OrganizationController::class, 'store']);
Route::get('/organizations/{id}', [OrganizationController::class, 'show']);
Route::patch('/organizations/{id}', [OrganizationController::class, 'update']);

Route::get('/projects', [ProjectController::class, 'index']);
Route::post('/projects', [ProjectController::class, 'store']);
Route::get('/projects/{id}', [ProjectController::class, 'show']);
Route::patch('/projects/{id}', [ProjectController::class, 'update']);

Route::get('/projects/{id}/keys', [KeyController::class, 'index']);
Route::post('/projects/{id}/keys', [KeyController::class, 'store']);
Route::post('/keys/{id}/revoke', [KeyController::class, 'revoke']);

Route::get('/models', [ModelController::class, 'index']);
Route::post('/models/refresh', [ModelController::class, 'refresh']);
Route::patch('/models/{id}', [ModelController::class, 'update']);

Route::get('/tasks', [TaskController::class, 'index']);
Route::post('/tasks', [TaskController::class, 'store']);
Route::post('/tasks/{key}/versions/{version}/publish', [TaskController::class, 'publish'])->whereNumber('version');

Route::get('/jobs', [JobAdminController::class, 'index']);
Route::get('/jobs/{id}', [JobAdminController::class, 'show']);
Route::post('/jobs/{id}/cancel', [JobAdminController::class, 'cancel']);
Route::post('/jobs/{id}/retry', [JobAdminController::class, 'retry']);

Route::get('/health', [OpsController::class, 'health']);
Route::get('/alerts', [OpsController::class, 'alerts']);
Route::post('/alerts/{id}/ack', [OpsController::class, 'ackAlert']);
Route::put('/admission', [OpsController::class, 'admission']);
Route::get('/settings', [OpsController::class, 'settings']);
Route::patch('/settings', [OpsController::class, 'updateSettings']);
Route::get('/audit', [OpsController::class, 'auditLog']);
