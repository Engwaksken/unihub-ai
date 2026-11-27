<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\KeyIndexController;
use App\Http\Controllers\ModelController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectKeyController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TwoFactorSetupController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\UserController;
use App\Support\Features;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// There is intentionally no registration route: staff accounts are created by
// invitation (owners) or `php artisan cms:bootstrap-owner`.

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route(Features::enabled('overview') && Gate::allows('overview.view') ? 'overview' : 'projects.index');
})->name('home');

// WITU deployment brief URL names (docs/witu/audit.md §Routing). Plain redirects to the
// existing modules, so access control stays on the target routes.
Route::redirect('/dashboard', '/');
Route::redirect('/api-keys', '/keys');

// Single-use, expiring set-password links (invitations and bootstrap owner).
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:30,1')->name('invitations.show');
Route::post('/invitations/{token}', [InvitationController::class, 'accept'])
    ->middleware('throttle:10,1')->name('invitations.accept');

Route::middleware('auth')->group(function () {
    // Reachable before MFA enrolment; everything else requires it.
    Route::get('/security/two-factor', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');

    Route::middleware('mfa')->group(function () {
        Route::get('/account', [AccountController::class, 'show'])->name('account.show');
        Route::get('/account/recovery-codes', [AccountController::class, 'recoveryCodes'])
            ->middleware('password.confirm')->name('account.recovery-codes');

        // Overview
        Route::get('/overview', [OverviewController::class, 'index'])
            ->middleware(['feature:overview', 'can:overview.view'])->name('overview');

        // Organizations
        Route::get('/organizations', [OrganizationController::class, 'index'])
            ->middleware('can:organization.view')->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])
            ->middleware('can:organization.manage')->name('organizations.store');
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])
            ->whereUuid('organization')->middleware('can:organization.manage')->name('organizations.update');
        Route::patch('/organizations/{organization}/status', [OrganizationController::class, 'updateStatus'])
            ->whereUuid('organization')->middleware('can:organization.manage')->name('organizations.status');

        // Projects
        Route::get('/projects', [ProjectController::class, 'index'])
            ->middleware('can:project.view')->name('projects.index');
        Route::get('/projects/create', [ProjectController::class, 'create'])
            ->middleware('can:project.create')->name('projects.create');
        Route::post('/projects', [ProjectController::class, 'store'])
            ->middleware('can:project.create')->name('projects.store');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])
            ->whereUuid('project')->name('projects.show');
        Route::get('/projects/{project}/settings', [ProjectController::class, 'settings'])
            ->whereUuid('project')->name('projects.settings');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])
            ->whereUuid('project')->name('projects.update');
        Route::patch('/projects/{project}/status', [ProjectController::class, 'updateStatus'])
            ->whereUuid('project')->name('projects.status');

        // Project tabs (project scope is checked in the controllers: out of scope = 404)
        Route::middleware('feature:project_keys_tab')->group(function () {
            Route::get('/projects/{project}/keys', [ProjectKeyController::class, 'index'])
                ->whereUuid('project')->name('projects.keys');
            Route::post('/projects/{project}/keys', [ProjectKeyController::class, 'store'])
                ->whereUuid('project')->name('projects.keys.store');
            Route::post('/projects/{project}/keys/{key}/revoke', [ProjectKeyController::class, 'revoke'])
                ->whereUuid('project')->whereUuid('key')->name('projects.keys.revoke');
        });
        Route::get('/projects/{project}/jobs', [JobController::class, 'forProject'])
            ->whereUuid('project')->middleware('feature:project_jobs_tab')->name('projects.jobs');
        Route::get('/projects/{project}/usage', [UsageController::class, 'forProject'])
            ->whereUuid('project')->middleware('feature:project_usage_tab')->name('projects.usage');

        // API keys across the user's projects
        Route::get('/keys', [KeyIndexController::class, 'index'])
            ->middleware(['feature:api_keys', 'can:key.view'])->name('keys.index');

        // Models & tasks
        Route::middleware('feature:models_tasks')->group(function () {
            Route::get('/models', [ModelController::class, 'index'])->middleware('can:model.view')->name('models.index');
            Route::post('/models/refresh', [ModelController::class, 'refresh'])->middleware('can:model.enable')->name('models.refresh');
            Route::patch('/models/{model}', [ModelController::class, 'update'])
                ->whereUuid('model')->middleware('can:model.enable')->name('models.update');
            Route::get('/tasks', [TaskController::class, 'index'])->middleware('can:task.view')->name('tasks.index');
            Route::get('/tasks/versions/create', [TaskController::class, 'create'])->middleware('can:task.manage')->name('tasks.create');
            Route::post('/tasks', [TaskController::class, 'store'])->middleware('can:task.manage')->name('tasks.store');
            Route::post('/tasks/{key}/versions/{version}/publish', [TaskController::class, 'publish'])
                ->whereNumber('version')->middleware('can:task.manage')->name('tasks.publish');
        });

        // Jobs & queue
        Route::middleware('feature:jobs')->group(function () {
            Route::get('/jobs', [JobController::class, 'index'])->middleware('can:job.view')->name('jobs.index');
            Route::post('/jobs/{job}/cancel', [JobController::class, 'cancel'])
                ->whereUuid('job')->middleware('can:job.manage')->name('jobs.cancel');
            Route::post('/jobs/{job}/retry', [JobController::class, 'retry'])
                ->whereUuid('job')->middleware('can:job.manage')->name('jobs.retry');
        });

        // Usage & reports
        Route::middleware(['feature:usage', 'can:usage.view'])->group(function () {
            Route::get('/usage', [UsageController::class, 'index'])->name('usage.index');
            Route::get('/usage/export', [UsageController::class, 'export'])->name('usage.export');
        });

        // Server & alerts
        Route::middleware('feature:health')->group(function () {
            Route::get('/health', [HealthController::class, 'index'])->middleware('can:health.view')->name('health.index');
            Route::post('/alerts/{alert}/ack', [HealthController::class, 'acknowledge'])
                ->whereUuid('alert')->middleware('can:alert.acknowledge')->name('alerts.ack');
            Route::put('/admission', [HealthController::class, 'admission'])
                ->middleware('can:admission.manage')->name('admission.update');
        });

        // Settings
        Route::middleware('feature:settings')->group(function () {
            Route::get('/settings', [SettingsController::class, 'index'])->middleware('can:settings.cms')->name('settings.index');
            Route::patch('/settings/gateway', [SettingsController::class, 'updatePolicy'])
                ->middleware('can:settings.policy')->name('settings.policy');
            Route::patch('/settings/portal', [SettingsController::class, 'updateCms'])
                ->middleware('can:settings.cms')->name('settings.cms');
            Route::post('/settings/test-mail', [SettingsController::class, 'testMail'])
                ->middleware(['can:settings.cms', 'throttle:5,1'])->name('settings.test-mail');
        });

        // Users & audit
        Route::middleware('can:staff.manage')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/invite', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->whereUuid('user')->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->whereUuid('user')->name('users.update');
            Route::post('/users/{user}/disable', [UserController::class, 'disable'])->whereUuid('user')->name('users.disable');
            Route::post('/users/{user}/enable', [UserController::class, 'enable'])->whereUuid('user')->name('users.enable');
            Route::delete('/users/{user}/sessions', [UserController::class, 'revokeSessions'])->whereUuid('user')->name('users.sessions.revoke');
            Route::post('/users/{user}/invitation', [UserController::class, 'resendInvitation'])->whereUuid('user')->name('users.invitation.resend');
        });

        Route::get('/audit', [AuditController::class, 'index'])
            ->middleware('can:audit.view')->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])
            ->middleware('can:audit.view')->name('audit.export');
    });
});
