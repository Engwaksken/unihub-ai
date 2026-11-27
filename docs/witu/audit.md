# WITU AI: codebase audit against the production deployment brief

- **Date:** 2026-11-26
- **Scope:** repository `Engwaksken/unihub-ai`, branch `main` at `b372a3d`, audited against "WITU AI Platform – Production Deployment and Integration Instructions".
- **Not yet audited:** the running FastAPI gateway at `/opt/witu-ai-gateway`. Its source is not in the repository. It is captured by `infra/witu/backup-legacy-gateway.sh` for review (§5).

**Status key**
- **Done:** implemented and covered by automated tests in CI.
- **Partial:** exists, but falls short of the brief.
- **Missing:** not implemented.
- **Ops:** a server task that this kit scripts.

## 1. Version compatibility (Phase Two)

| Component | Requirement (from the lock files) | Server today | Decision |
|---|---|---|---|
| CMS (`cms/`) | `php ^8.3`, Laravel 13.17+, Livewire 4, Fortify. `composer.lock` resolved with `platform.php = 8.3.0` (Symfony 7.4 LTS). CI tests on 8.3 and 8.4. | PHP 8.2.26: **incompatible** | Install **PHP 8.4 alongside 8.2** from packages.sury.org (`infra/witu/install-php.sh`). Nothing is removed, and the default `php` CLI is unchanged. |
| Gateway v2 (`gateway/`) | `php >= 8.4`, SQLite (WAL) | n/a | Served by the same php8.4-fpm in phase B. |
| Frontend | Vite 7 / Node 22, **build-time only** | Node not needed | Built by CI (the `cms-bundle` artifact). The VPS never runs npm. |
| MariaDB | 10.6+ (uses `mariadb` driver, utf8mb4) | 10.11.6: OK | Dedicated `witu_ai` DB and user (`setup-mariadb.sh`). |
| Web server | The repository shipped nginx configs | Apache 2.4 | New Apache vhost plus a PHP-FPM pool (`infra/witu/apache`, `infra/witu/php`). |

`composer check-platform-reqs --no-dev --lock` runs on every deploy. A mismatch aborts the deploy before the live site is touched. Composer platform requirements are never bypassed.

## 2. What already exists and works (no duplication planned)

| Area | Status | Where |
|---|---|---|
| Staff login, mandatory TOTP 2FA, recovery codes, password confirm, invitation-only accounts | Done | `cms/app/Http/Controllers/{TwoFactorSetup,Invitation,Account}Controller`, Fortify |
| Role-based access (owner, ops_admin, project_manager, auditor), project-scoped roles | Done | `cms/app/Authorization/Permissions.php`, `docs/permissions.md` |
| Organization isolation (projects scoped to an org; users only see assigned projects) | Done | gateway `project_roles` assertion claim, CMS policies, tests |
| Organizations and projects: register, assign org, quotas, activate/suspend | Done (UI + gateway API) | `cms/.../OrganizationController`, `ProjectController`; gateway `Management/*` |
| API keys: generated server-side, shown once, HMAC-SHA256 + pepper storage, expiry, revocation, rotation with overlap (`rotates_key_id`) | Done | gateway `KeyService`, `KeyController`; CMS `ProjectKeyController` |
| Project model permissions, per-project rpm/daily quotas, queue caps, persistent rate limiting (DB counters, not in-memory) | Done | gateway `AdmissionService`, `quota_counters` |
| Token accounting, request monitoring, error states, queue management (cancel/retry) | Done | gateway `jobs` table, `Reporting`; CMS `JobController`, `UsageController` |
| Dashboard from real data (no hard-coded numbers) | Done | CMS `OverviewController` → gateway `/summary` |
| Server monitoring: CPU, RAM, disk, heartbeat, alerts with e-mail (swap not yet sampled) | Done / Partial | gateway `Telemetry`, `gateway:sample`; CMS `HealthController`, `cms:notify-alerts` |
| Audit log: admin actions, key create/revoke, auth failures, settings changes | Done | CMS `audit_events`, gateway `audit_log`, merged view |
| Settings: operational limits, alert thresholds, display timezone | Done | gateway `Settings::RULES`; CMS `SettingsController` |
| CSRF, encrypted secure sessions, no prompt/response storage by default, payload encryption | Done | tests in `cms/tests/Feature/Security`, `gateway/tests` |
| Laravel/PHP client package for apps | Done | `integrations/laravel-central-ai` |
| API documentation (OpenAPI public and management, contract-tested) | Done | `docs/api/*.yaml`, `gateway/tests/Feature/OpenApiContractTest.php` |

## 3. Gaps against the WITU brief

| # | Requirement | Status | Plan |
|---|---|---|---|
| G1 | CMS on the Debian VPS: Apache, PHP-FPM, MariaDB, deploy/rollback/backup | **Ops (this change)** | `infra/witu/*`, `docs/witu/runbook.md` |
| G2 | The CMS manages the gateway. The CMS speaks the gateway v2 management API, which the running FastAPI gateway does not have. | Missing on server | **Phase B:** install the repository's gateway v2 beside FastAPI on localhost (ADR 0002). |
| G3 | OpenAI-compatible public API `/v1/models` and `/v1/chat/completions` (what apps use today on FastAPI) | **Missing in gateway v2**, which exposes the async `/v1/jobs` | **Phase C:** add synchronous OpenAI-compatible endpoints in gateway v2 that use the same admission queue, then cut Apache `/v1` over. Needs the FastAPI source first (§5). |
| G4 | AI playground (`/playground`) | Missing | Phase C. A management-API endpoint runs a prompt through the queue under a dedicated *playground* project, so the CMS never holds an app key. |
| G5 | PDF and Excel usage exports | Partial: CSV only | Phase C (XLSX via PhpSpreadsheet or OpenSpout, PDF via dompdf), after a memory check. |
| G6 | Branding: logo, favicon, appearance | Partial: text branding only | Phase C. Uploads go to `storage/app`, and are covered by the backups. |
| G7 | Daily, weekly and monthly reports filtered by org, project, key and model | Partial: day/project/model grouping with custom ranges; no per-key or org filter | Phase C (gateway `/usage` filters). |
| G8 | URL names `/dashboard`, `/api-keys` | Done in this change: redirects to `/` → Overview and to `/keys` | `cms/routes/web.php`, `UrlAliasTest` |
| G9 | `/health` → FastAPI | **Conflict:** `/health` is the CMS *Server & alerts* page | FastAPI health is published at **`/v1/health`** instead (ADR 0002 §Routing). |
| G10 | Inference settings from the brief (30 s queue wait, 8 waiting, 256 max output) | Ops | Set them in CMS → Settings once gateway v2 is connected. Defaults today are 120 s and 8; max output is already 256. |
| G11 | Gateway gaps reported earlier: `error_transient` in job metadata, `tz` on `/summary`, `GET /jobs/{id}` for management | **Done** (2026-11-27) | Gateway: `Job::toMetadata`, `ReportController` (`tz` on `/summary` and `/usage`), `JobAdminController::show` (unknown and out-of-scope ids are both 404). CMS: Retry is offered only when `error_transient` is not `false`; Overview sends the display zone. Tested both sides. |
| G12 | Queue worker for the CMS | Not required: no `ShouldQueue` classes | Only the scheduler cron is installed. |
| G14 | Swap usage and per-service status (Ollama, FastAPI, MariaDB) on the monitoring page | **Done** (2026-11-27); live once gateway v2 runs (phase B) | Gateway: `Telemetry` samples SwapTotal/SwapFree; `ServiceProbe` runs `systemctl is-active -- <unit>` (argv, no shell, unit names validated) for `GATEWAY_MONITORED_SERVICES`. New alerts `swap_high` (setting `alert_swap_pct`, default 50) and `service_down` (critical; `unknown` never fires). Additive migration `2026_11_27_000001`. CMS: Swap metric, swap in history, Services table, swap threshold in Settings. On WITU set `GATEWAY_MONITORED_SERVICES=ollama,mariadb,apache2,php8.4-fpm,<FastAPI unit>`. |
| G13 | phpMyAdmin | Optional | `docs/witu/database-access.md`. The recommended path is an SSH tunnel plus a desktop client. A localhost-only phpMyAdmin is documented as an alternative. |

## 4. Migrations and seeders reviewed (Phase Three)

The CMS migrations are additive and create tables only. None of them touch existing data:
- `users` and `password_reset_tokens`
- `sessions`
- `cache` and `jobs`
- the 2FA columns
- `staff_access` tables (project memberships, invitations)
- `audit_events`
- `cms_settings` and notifier state

Roles are defined in code (`Permissions.php`), not seeded. The production-safe initialisation is done by commands, not seeders:
- `cms:system-user`: the non-login service identity used for gateway calls.
- `cms:bootstrap-owner`: the first administrator, set up through a one-time 24 h link.

`DatabaseSeeder` and `SyntheticStaffSeeder` are **for local development only and must not be run in production**. The deploy script never seeds. It shows the pending migration SQL (`migrate --pretend`) and only migrates when re-run with `--migrate`.

## 5. Legacy FastAPI gateway: what must be learned before phase C

Run `infra/witu/preflight.sh` and `infra/witu/backup-legacy-gateway.sh`, then download `source.tar.gz` and answer these:
1. **Key format and hashing.** Can existing app keys be imported into gateway v2, or must they be reissued?
2. **Request and response shapes** of `/v1/chat/completions` (streaming? `usage` block?) and `/v1/models`, so existing apps keep working unchanged after cut-over.
3. **Which apps hold keys today,** and their request volumes (the `gateway.db` usage tables).
4. **Concurrency and limit settings,** and how it calls Ollama (`keep_alive`, `num_ctx`, parallelism).

Until that is done, FastAPI keeps serving `/v1/*`, exactly as today.
