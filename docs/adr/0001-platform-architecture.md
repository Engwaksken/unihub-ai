# ADR 0001 — Unihub AI Platform architecture

- **Status:** Proposed. Needs PM approval before P1 starts.
- **Date:** 2026-11-26
- **Inputs:** *Unihub AI Management Dashboard — PRD & implementation spec v1.0* and *Unihub Central AI Technical Documentation v1.0* (companion; not yet in the repository, see Q-09)
- **Evidence gathered:** The repository was empty at discovery. Local dev machine: Windows 11, PHP 8.2.12 (XAMPP; `sodium` extension **not** loaded), Composer 2.8.12, Node 22.22, git 2.55. Ollama is not installed. **No server, cPanel account or DNS was inspected.** Every hosting fact below is an assumption until `infra/preflight/server-preflight.sh` is run (see `docs/preflight.md`).

---

## 1. Context

Unihub wants one local LLM (Ollama, Qwen2.5 1.5B) on a small VPS (2 vCPU, 4 GB RAM, 80 GB NVMe) that several Unihub web and mobile backends can share. It also wants a staff dashboard to control who can use it, how much, and at what cost. The pilot client is My Digital Diary.

The hard constraints are:
- one inference at a time
- about 4 GB of RAM shared between the OS, the model (about 1.1–1.9 GB resident at Q4) and every other service
- the CMS must run on existing cPanel shared hosting
- no secrets in Flutter
- gateway state must have a single writer

## 2. Decisions

### D1 — Monorepo `unihub-ai-platform`
The existing working folder becomes the monorepo:

```
/cms        Laravel app: staff portal (cPanel + MariaDB)
/gateway    Laravel app: public job API + management API + worker (VPS + SQLite)
/infra      systemd units, nginx vhosts, firewall, deploy/rollback, preflight
/docs       ADRs, OpenAPI, ERD, threat model, runbooks, work plan
/tests      cross-app contract/integration/load tests (k6 or Pest), benchmark harness
```
Each app keeps its own `composer.json`, unit/feature tests and CI job. Contract tests in `/tests` run against both apps. *Alternative rejected:* two separate repositories. That adds coordination cost for one team, and the API contract would drift.

### D2 — Framework version: Laravel 13 on PHP 8.3+
- Laravel 13 is the target because the spec asks for it. It needs PHP ≥ 8.3.
- **Blocker B-1:** local PHP is 8.2.12. Developers must upgrade to PHP 8.3 or 8.4 and enable `sodium`.
- **Blocker B-2:** the cPanel PHP version is unknown. If cPanel cannot offer PHP 8.3+, the fallback is Laravel 12. Its security-support end date must be checked at discovery, and it is probably close. That fallback needs a recorded exception. The better fix is to ask the host for PHP 8.3.
- Exact versions are pinned in `composer.lock` at scaffold time.
- **Update 2026-11-26 (P1):**
  - Local PHP 8.4.27 (winget, with `sodium`) was already installed, which resolves B-1. Use it explicitly; `php` on PATH is still XAMPP 8.2.
  - The gateway was scaffolded on **Laravel 13.44.1** and **requires PHP ≥ 8.4**. Laravel issues `BEGIN IMMEDIATE` (`transaction_mode`) only on PHP ≥ 8.4; on 8.3 it silently falls back to DEFERRED, which would break the atomic admission in D7. We control the VPS, so PHP 8.4 comes from the distro or the ondrej/sury PPA.
  - The CMS stays on ^8.3 for cPanel.

### D3 — Gateway implemented as a lean API-only Laravel app (not FastAPI/Go)
| Option | For | Against |
|---|---|---|
| **Laravel API-only (chosen)** | One language and one set of skills for the team. Policies, validation, tests and migrations are shared idioms. PHP-FPM with 2–3 workers needs about 60–120 MB. | Heavier than Go. PHP has no async, so the worker is a blocking loop, which suits our design anyway. |
| Python FastAPI | Good HTTP client, async | A second stack to staff and patch |
| Go | Lowest RAM, single binary | The team has no Go skills, and the PRD expects Laravel idioms |

The gateway **does not use Laravel's queue system for inference jobs**. That system cannot give us atomic quota admission, per-project round-robin, or lease semantics that we can reason about. Instead a dedicated `php artisan gateway:work` daemon claims jobs straight from the `jobs` table (D7).

### D4 — Gateway is the single source of truth for operational state
| Owner | Data |
|---|---|
| **Gateway (SQLite, VPS)** | organizations, projects, api_keys, models, tasks/task_versions, project_tasks, jobs (+ encrypted payloads), usage_events, quota_counters, gateway audit_events, health_samples, alerts, used assertion nonces |
| **CMS (MariaDB, cPanel)** | users, roles, project_memberships (by `gateway_project_uuid`), MFA secrets, sessions, password resets, invitations, CMS audit_events (logins, invites, role/membership changes), settings that are CMS-only (branding, notification recipients) |

- The CMS has no writable copy of projects, keys or quotas. It reads them through the management API and caches read-only aggregates for at most 30 s.
- The Audit page merges both audit streams by `request_id` and timestamp.
- Gateway-side policy settings (limits, retention, approved providers) live in a gateway `settings` table and are edited through the management API.

### D5 — Gateway persistence: SQLite in WAL mode
- Settings: `journal_mode=WAL`, `synchronous=NORMAL`, `busy_timeout=5000`, `foreign_keys=ON`.
- Every admission and every claim runs in a `BEGIN IMMEDIATE` transaction. That serialises writers and keeps quota counters exact without row locks.
- Expected write rate is under 10 per second, well inside SQLite's single-writer limits.
- Backup: nightly `sqlite3 .backup` (online-safe) plus encrypted off-host copy (Q-07).
- Migration path: if more than one node is ever needed, move to MariaDB or Postgres. The schema avoids SQLite-only features.
- **Exit check at P2:** load test with 10 concurrent submitters, with no `SQLITE_BUSY` reaching clients.

### D6 — Network topology
```
Internet ──TLS──▶ nginx :443 ai.kemmytech.com ──▶ php-fpm (gateway, public routes only: /v1/*, /healthz)
cPanel CMS ─TLS─▶ nginx :443 mgmt-ai.kemmytech.com (IP allowlist = cPanel egress IP) ──▶ php-fpm (gateway, /internal/v1/* only)
gateway:work (systemd) ──▶ http://127.0.0.1:11434 Ollama (loopback only; ufw denies 11434)
```
- Public and management routes are split by **separate nginx server blocks**. Each one passes a fixed `GATEWAY_SURFACE=public|internal` FastCGI param, and Laravel route groups refuse requests on the wrong surface. So `/internal/*` gives 404 on the public hostname even if nginx is misconfigured.
- A private network between cPanel and the VPS is unlikely. If the host offers one, we use it instead of the allowlisted HTTPS vhost.
- mTLS on the management vhost is optional hardening (Q-05).

### D7 — Job queue, scheduling and leases
- **States:** `queued → running → completed | failed`; `queued → cancelled | expired`. A `running` job never goes to `cancelled`; DELETE returns 409.
- **Admission** is one `BEGIN IMMEDIATE` transaction:
  1. idempotency lookup on `(project_id, idem_key)`. A match with the same `input_hash` returns the original job and consumes no quota. A different hash returns 409.
  2. project status and task allowlist check
  3. token budget check
  4. global queued count < 8, and project queued count < 2. Otherwise 429 `QUEUE_FULL`.
  5. minute and day `quota_counters` under their limits. Otherwise 429 `QUOTA_EXCEEDED`, with `Retry-After` set to the end of the window.
  6. insert the job, then upsert-increment both counters
- **Claim (fair):** pick the project with queued jobs whose `last_served_at` is oldest (ties broken by oldest job). Take that project's oldest job. Set `state=running`, `attempts+=1`, `lease_until = now + 150 s`, `worker_id`, and the project's `last_served_at`. All in one `BEGIN IMMEDIATE`.
- **Deadline:** the Ollama HTTP call has a hard 120 s timeout, and the lease (150 s) is longer than the deadline. That makes lease renewal unnecessary with one synchronous worker. Renewal stays in the design only if a later multi-worker setup needs it. Queue wait is capped at 120 s: a sweeper moves stale `queued` jobs to `expired`.
- **Single worker guarantee:** the daemon holds an exclusive `flock` on `/run/unihub-gateway/worker.lock`. Only the lock holder may recover expired leases. Holding the lock proves the previous worker process has exited.
- **Recovery:** a `running` job whose lease has expired goes back to `queued` if `attempts < 2` and the last error was transient (timeout, connection refused, Ollama 5xx). Otherwise it becomes `failed` with `WORKER_LOST`. That allows at most one retry, with a 5 s backoff. Validation and policy failures are never retried.
- **Completion:** in one transaction, update the job (encrypted output, `state`, timings) and insert the `usage_event`. `usage_events.job_id` is UNIQUE, so a duplicate completion fails the transaction.
- **Overlap avoidance:** after a timeout the worker closes the connection, which makes Ollama abort generation. It then probes `/api/ps` before claiming the next job. P4 benchmark verifies this behaviour on the installed Ollama version.

### D8 — Inference call
`POST http://127.0.0.1:11434/api/chat` with `stream:false`, `options:{num_ctx:2048, num_predict:min(max_tokens, task.output_cap, 256), num_thread:2}`, `keep_alive` set to keep one model loaded.
- The system prompt comes from the published task version. User input is sent as a single `user` message. No tools are offered.
- `prompt_eval_count` and `eval_count` are stored as `tokens_in` and `tokens_out` when Ollama returns them, otherwise null.
- **Input budget:** estimated input tokens are `ceil(bytes/3)` plus the template token count. If that estimate plus `max_tokens` exceeds 2048, the request is rejected with 422 `TOKEN_BUDGET`. The `/3` heuristic is calibrated against the Qwen tokenizer in P4. Input is never silently truncated.
- The Ollama URL is a fixed config constant. The gateway never takes a URL, model pull or command from a request.

### D9 — API keys
- Format: `uhk_<env>_<prefix>_<secret>`, where `env` is `live` or `test`, `prefix` is 10 chars of Crockford base32 (UNIQUE, shown in the UI) and `secret` is base64url of 32 random bytes.
- Stored as `digest = HMAC-SHA256(pepper, full_key)`. The pepper is loaded from `/etc/unihub-gateway/secrets/key_pepper`, mode 0400, owned by the gateway user.
- Lookup uses the prefix, then `hash_equals` on the digest. Unknown prefixes still compute a dummy HMAC so response timing does not reveal them.
- The key is shown exactly once in the issue response. Rotation issues a new key and sets `expires_at` on the old one to now plus the overlap (default 7 days, max 30).
- `last_used_at` is written at most once a minute per key, to limit writes.

### D10 — Payload encryption
- Job input and output are stored in a separate `job_payloads` table. Each value is encrypted with `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`, using AAD = `job_uuid|field`, a random nonce, and a `key_id` column so keys can be rotated.
- Keys live in `/etc/unihub-gateway/secrets/payload_keys/` (outside the DB and outside `.env`).
- When `expires_at` passes, the purge job deletes the payload rows: result TTL 24 h, metadata 90 days.
- Backups contain ciphertext only. Losing the key file makes those backups unreadable, which is intended.

### D11 — CMS → gateway management authentication (two layers)
1. **Service credential:** `Authorization: Bearer <mgmt token>`, a separate secret stored at the gateway as an HMAC digest. Requests are accepted only from the allowlisted IP on the management vhost. It can be rotated with two digests valid during the overlap.
2. **Staff actor assertion:** an `X-Actor-Assertion` header carrying a compact JWS signed with **Ed25519**. The CMS holds the private key and the gateway holds only the public key. Claims:
   - `iss=unihub-cms`, `aud=unihub-gateway-mgmt`
   - `iat`, `exp` (≤ 60 s later), `jti` (nonce, single-use, kept for 5 minutes)
   - `sub` = CMS user uuid
   - `roles`, and `projects` = the assigned project uuids for project-manager and auditor roles
   - `mfa=true`

   The gateway **re-enforces** role and project scope on every endpoint, using the same permission matrix as the CMS (in shared `docs/permissions.md`, mirrored in tests on both sides). Gateway audit records `actor_ref = cms:<sub>` and the request id.

### D12 — CMS stack
- Laravel 13 with Blade and Livewire (current stable version, pinned at scaffold), MariaDB.
- Laravel Fortify handles login, password reset and TOTP two-factor auth. MFA is mandatory for all staff, enforced by middleware before any portal route.
- Login throttling, secure, HttpOnly and SameSite=Lax cookies, and database sessions so they can be revoked.
- `php artisan cms:bootstrap-owner` is a one-time command. It refuses to run if any owner already exists, and prints a single-use invite link. There is no public registration route.
- Fonts (Poppins) and Chart.js are self-hosted and built by Vite **in CI**. cPanel receives built assets, because Node on cPanel cannot be assumed.
- Scheduler: a cPanel cron runs `* * * * * php artisan schedule:run`.
- **Display time zone** is Africa/Kampala (UTC+3, no DST). Everything is stored in UTC.

### D13 — Telemetry and alerts
- A gateway systemd timer runs `gateway:sample` every 30 s. It records CPU (from `/proc/stat`), RAM, disk, queue depth, worker heartbeat (written by the worker each loop to `worker_heartbeats`), Ollama `/api/ps` status, and TLS certificate expiry (read from the cert file).
- The thresholds below open alerts, deduplicated per (type, open state):
  - RAM > 85 %
  - disk > 80 %
  - queue saturated
  - heartbeat missing > 60 s
  - TLS expiry < 14 days
- **Emails come from the CMS, not the gateway.** The gateway has no staff data. A CMS scheduled task polls `/internal/v1/alerts` and sends to the configured recipients over cPanel SMTP.
- If the gateway is unreachable for more than 2 minutes, the CMS raises its own local "gateway unreachable" alert.
- Metrics older than 120 s show as **stale** in the UI, and missing metrics show as **unknown**. They are never displayed as 0.

### D14 — Cost allocation
- Monthly hosting cost is a setting (default $21.88).
- A project's estimated share = that cost × the project's share of `inference_ms` for the period.
- The UI labels it "estimate".

## 3. Consequences / risks
- **R1 RAM:** if "Frappe Linux I" already runs Frappe/ERPNext (MariaDB + Redis + gunicorn, often 1.5–2.5 GB), Ollama will not fit alongside it. **The preflight must confirm this before anything is installed.** If services are co-located, we stop and escalate to the PM.
- **R2 CPU quality:** Ollama needs AVX/AVX2 for usable speed on 2 vCPU. Preflight checks `/proc/cpuinfo`. p95 ≤ 120 s is unproven until P4.
- **R3 cPanel egress:** the CMS needs outbound HTTPS to the VPS and a stable egress IP for the allowlist. Some shared hosts block this or share IPs, so mTLS becomes necessary.
- **R4 SQLite:** single node only. This is accepted for MVP (D5).
- **R5 Two audit stores:** the merged view depends on both being available. Each export is labelled with its source.

## 4. Out of scope for MVP (per PRD §02)
Self-service org onboarding, model pulls from the UI, multi-node, embeddings/OCR/speech, external provider fallback (the setting exists but is disabled and gated by approval).
