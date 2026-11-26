# Work plan & task register

Owners are `TBD` until the PM assigns them. Status values: `todo`, `in-progress`, `blocked`, `review`, `done`. "Evidence" means a link to a PR, test report or document. A task without evidence is not `done`.

## Gates
| Gate | Exit criteria | Approver |
|---|---|---|
| G0 (end P0) | ADR 0001 approved. Both OpenAPI drafts approved. Threat model reviewed. Preflight run on VPS and cPanel, with results in `docs/preflight-results.md`. Blockers B-1..B-3 resolved. | PM, IT owner |
| G1 (end P1) | Both apps scaffolded. CI green. Staff auth with MFA works. Org/project pages work against the gateway management API (staging). | PM |
| G2 (end P2) | Contract and integration tests green. Burst/idempotency/recovery acceptance tests pass on staging VPS. | PM, IT owner |
| G3 (end P3) | Every menu, form and action works. Accessibility check passed. Scoped exports verified. | PM |
| G4 (end P4) | Results for 30 pilot prompts reported. My Digital Diary owner signs off. | Pilot owner |
| G5 (end P5) | Security tests, backup restore and rollback drill done. Handover package signed. | PM, IT owner, pilot owner |

## Blockers / open questions
| ID | Question | Needed by | Status |
|---|---|---|---|
| B-1 | Upgrade local dev PHP to 8.3+ with `sodium` enabled | P1 | **resolved**: winget PHP 8.4.27 with sodium is already installed; call it by full path (README) |
| B-2 | cPanel PHP version (needs ≥ 8.3 for Laravel 13), MariaDB version, cron and SMTP availability, outbound HTTPS, egress IP | P1 | open |
| B-3 | VPS: root/sudo, OS, does the provider allow AI workloads, **what Frappe services already run and their RAM use**, AVX2 | P2 | open |
| Q-04 | DNS: confirm `ai.kemmytech.com` and the proposed `mgmt-ai.kemmytech.com` | P2 | open |
| Q-05 | Is mTLS on the management vhost required, or is an IP allowlist enough? | P2 | open |
| Q-06 | Git hosting and CI provider (GitHub Actions assumed) | P1 | **resolved**: GitHub `origin` set up; `.github/workflows/ci.yml` |
| Q-07 | Off-host backup target and retention policy for backups | P5 | open |
| Q-08 | Designated IT owner and alert email recipients | P3 | open |
| Q-09 | Add the companion *Central AI Technical Documentation v1.0* to `/docs/reference/` | P0 | open |
| Q-10 | Who reviews data protection (counsel) before non-pilot orgs are onboarded | P5 | open |

## Task register

### P0 — Discovery
| ID | Task | Pri | Depends | Status | Evidence |
|---|---|---|---|---|---|
| P0-1 | Repository audit (empty, so a new monorepo was created) | P0 | — | done | `README.md` |
| P0-2 | ADR 0001 architecture | P0 | — | approved 2026-11-26 ("yes proceed") | `docs/adr/0001-platform-architecture.md` |
| P0-3 | Threat model | P0 | P0-2 | review | `docs/threat-model.md` |
| P0-4 | Public API OpenAPI 3 draft | P0 | P0-2 | approved; implemented | `docs/api/public-openapi.yaml` |
| P0-5 | Management API OpenAPI 3 draft and permission matrix | P0 | P0-2 | approved; wire format fixed in `docs/api/management-auth.md` | `docs/api/management-openapi.yaml`, `docs/permissions.md` |
| P0-6 | Server preflight script and checklist | P0 | — | review | `infra/preflight/server-preflight.sh`, `docs/preflight.md` |
| P0-7 | Run preflight on VPS and cPanel, record results | P0 | P0-6, B-2, B-3 | blocked (needs access) | |
| P0-8 | Staging plan (staging VPS or staging vhost and DB, synthetic data, `uhk_test_` keys) | P0 | P0-7 | todo | |

### Progress snapshot (2026-11-26, end of day)
- **Gateway:** P1-1, P1-2, P1-5 (gateway side), P2-1..P2-8. 64 PHPUnit tests, including OpenAPI contract tests. Pint and Larastan level 5 are clean. `composer ci` passes.
- **CMS:** P1-3, P1-4, P1-5 (CMS side), P1-6. 209 PHPUnit tests. Pint and Larastan level 5 are clean. `composer ci` passes.
- **Contract fix:** the per-project `project_roles` claim is now enforced on both sides (CMS finding #1).
- **CI:** `.github/workflows/ci.yml` is written. It has **not run on GitHub yet** (Q-06: no remote).
- **Infra (P2-9):** written, not deployed (B-3).
- **Not verified:**
  - real Ollama (not installed locally)
  - concurrency under PHP-FPM on the VPS
  - the CMS running in a browser: blocked by the permission classifier; covered by feature tests only
  - a live CMS→gateway round trip over HTTP

### P1 — Foundation
| ID | Task | Pri | Depends | Status |
|---|---|---|---|---|
| P1-1 | Scaffold `/gateway` (Laravel 13 API-only, SQLite WAL config, surface middleware, error envelope, request-id) | P0 | G0 | done (gateway tests) |
| P1-2 | Gateway migrations for all PRD §05 entities, indexes and audit immutability trigger; ERD generated | P0 | P1-1 | done (`docs/erd.md`) |
| P1-3 | Scaffold `/cms` (Laravel 13, Livewire, Fortify with mandatory TOTP, database sessions, layout with burgundy/gold theme and Poppins) | P0 | G0 | done (cms tests) |
| P1-4 | CMS roles, memberships, policies, `cms:bootstrap-owner`, invitations | P0 | P1-3 | done (cms tests) |
| P1-5 | Management auth: Ed25519 assertion signer (CMS) and verifier with nonce store (gateway), mgmt token | P0 | P1-1, P1-3 | done both sides; `project_roles` claim added |
| P1-6 | Management API: organizations and projects; CMS Organizations/Projects pages | P0 | P1-5 | done (tests with faked gateway; live round trip not yet run) |
| P1-7 | CI: lint, Pint, PHPStan, Pest for both apps, `composer audit`, Vite build artefact | P0 | P1-1, P1-3 | workflow written and `composer ci` passes locally; not yet run on GitHub (Q-06) |
| P1-8 | Seed fixtures (synthetic orgs, projects, tasks; My Digital Diary staging) | P1 | P1-2 | done (both apps; CMS `SEED_PROJECT_UUIDS` must match gateway staging) |

### P2 — Gateway
| ID | Task | Pri | Depends | Status |
|---|---|---|---|---|
| P2-1 | Key lifecycle (issue/reveal-once, rotate with overlap, revoke, last_used) | P0 | P1-6 | done (gateway tests) |
| P2-2 | Models and task versions (immutable published versions), project task allowlist | P0 | P1-2 | done (gateway tests) |
| P2-3 | Admission service: idempotency, budget, queue caps, quota counters in one transaction | P0 | P2-1, P2-2 | done (gateway tests) |
| P2-4 | `/v1/jobs` POST/GET/DELETE and `/healthz` | P0 | P2-3 | done (gateway tests) |
| P2-5 | Payload encryption and purge scheduler | P0 | P1-2 | done (gateway tests) |
| P2-6 | Worker: flock, fair claim, Ollama client, deadline, retry policy, lease recovery, heartbeat | P0 | P2-3 | done (gateway tests) |
| P2-7 | Telemetry sampler and alert rules | P1 | P1-2 | done (gateway tests) |
| P2-8 | Contract tests (OpenAPI), integration tests with fake Ollama, burst/idempotency/recovery acceptance tests | P0 | P2-4, P2-6 | done, including OpenAPI contract tests (`OpenApiContractTest`) |
| P2-9 | infra: systemd units, nginx vhosts, ufw, Ollama install per companion guide (staging) | P0 | B-3, G0 | written, not deployed (needs B-3) |

### P3 — Dashboard
| ID | Task | Pri | Depends | Status |
|---|---|---|---|---|
| P3-1 | Overview (cards, filters, trends, stale indicator) | P0 | P2-7 | todo |
| P3-2 | Project detail tabs: keys, jobs, usage, settings | P0 | P2-1 | todo |
| P3-3 | Models & tasks pages | P0 | P2-2 | todo |
| P3-4 | Jobs & queue (filter, cancel, retry with audit) | P0 | P2-6 | todo |
| P3-5 | Usage & reports, scoped CSV export, cost estimate | P0 | P2-6 | todo |
| P3-6 | Server & alerts, acknowledge, CMS email notifier, gateway-unreachable alert | P0 | P2-7 | todo |
| P3-7 | Users & audit (invite, roles, session revoke, merged audit and export) | P0 | P1-4 | todo |
| P3-8 | Settings (gateway policy and CMS settings, validated and audited) | P1 | P1-6 | todo |
| P3-9 | Accessibility and responsive pass (keyboard, contrast, empty/error/loading states) | P0 | P3-1..8 | todo |

### P4 — Pilot
| ID | Task | Pri | Depends | Status |
|---|---|---|---|---|
| P4-1 | My Digital Diary backend client (config/services.php, submit and poll, ownership, 429/503 handling) | P0 | G2 | kit done: `integrations/laravel-central-ai` (8 tests, plus a live round trip against the local gateway); **not applied to the diary repo** (no access; staging only after approval) |
| P4-2 | Flutter status UX (queued/running/expired/offline), with no key in the build | P0 | P4-1 | UX spec done (`docs/integration/my-digital-diary.md`); Flutter implementation is the diary team's |
| P4-3 | Benchmark harness and 30 representative prompts, token-estimate calibration | P0 | P2-9 | harness and 30 synthetic prompts done (`tests/benchmark`); dry run against the fake model passed; **real run needs VPS and Ollama** |
| P4-4 | Benchmark report: p50/p95, success rate, RAM peak, limitations | P0 | P4-3 | todo |

### P5 — Release
| ID | Task | Pri | Depends | Status |
|---|---|---|---|---|
| P5-1 | Security tests (authz matrix, ID guessing, log canary scan, port scan, TLS) | P0 | G3 | todo |
| P5-2 | Backup, restore and rollback scripts and drills | P0 | Q-07 | todo |
| P5-3 | Admin guide, runbooks (incident, lease reconciliation, key rotation), handover package | P0 | G4 | todo |
| P5-4 | Phased onboarding plan for BOQ, Nsales, Construction Store, Church, SACCO, School | P1 | G4, Q-10 | todo |

**Estimate:** the PRD indicates 3–6 weeks. A firm estimate comes after G0, because blockers B-2 and B-3 drive most of the risk.
