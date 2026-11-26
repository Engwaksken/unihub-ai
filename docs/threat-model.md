# Threat model (P0 draft)

Scope: public job API, management API, worker/Ollama, CMS portal, backups. Method: STRIDE per trust boundary. Status: **draft for review**.

## Assets
Project API keys; management credential and Ed25519 signing key; key pepper and payload keys; job prompts/outputs (may contain personal data from diaries, SACCO, school, church users); staff accounts/MFA; quota integrity (= cost and availability); audit trail.

## Trust boundaries
1. Internet → `ai.kemmytech.com` (project backends)
2. cPanel CMS → `mgmt-ai.kemmytech.com` (management)
3. Staff browser → CMS
4. Gateway worker → Ollama (loopback)
5. Hosts → backup storage

## Threats and controls

| # | Boundary | STRIDE | Threat | Control | Verified by |
|---|---|---|---|---|---|
| T1 | 1 | S | Stolen/guessed project key | 256-bit secret, HMAC+pepper digest, expiry, revoke, rotation overlap, last_used | Contract tests 401 for invalid/expired/revoked |
| T2 | 1 | I | Project A reads B's job by UUID guess | Project derived from key only; every query `where project_id = auth project`; 404 not 403 | Cross-project tests (AC "Access") |
| T3 | 1 | D | Burst / quota exhaustion starves others | Atomic admission, per-project queue cap 2, global 8, rpm/day quotas, round-robin claim, nginx `limit_req` + 32 KiB `client_max_body_size` | Burst 2/5/10 load test |
| T4 | 1 | T | Replay changes body under same idempotency key | `input_hash` (SHA-256 of canonical task+input+max_tokens) → 409 | Idempotency tests |
| T5 | 1/4 | E | Prompt injection makes model "do" something | No tools, no URLs, no shell; output treated as untrusted & escaped; consumers must validate structured output; AI never authoritative for loans/postings/BOQ prices | Code review; integration guide |
| T6 | 4 | S/E | Ollama exposed publicly → free compute / model pulls | Ollama bound to 127.0.0.1; ufw deny 11434; fixed endpoint in config | External port scan in deploy checklist |
| T7 | 2 | S | Attacker calls management API | Separate vhost, IP allowlist, bearer mgmt token, signed short-lived actor assertion w/ nonce, surface check in app | Tests: missing/expired/replayed/wrong-aud assertion → 401 |
| T8 | 2 | E | Compromised PM account escalates own quota or reads other projects | Policies in CMS **and** gateway; PM cannot PATCH quotas; scope from assertion `projects` claim | Policy tests both sides |
| T9 | 3 | S | Staff credential stuffing | Mandatory TOTP MFA, login throttle, no public registration, session revocation | Feature tests |
| T10 | 3 | T | CSRF / XSS in portal | Laravel CSRF, Blade escaping (`{{ }}` only, lint ban on `{!! !!}` for AI/user data), CSP header | Tests + CSP report |
| T11 | all | I | Prompts/secrets leak via logs, audit, exports, error pages | Payloads never logged; log processor redacts `Authorization`, `input`, `output`; `APP_DEBUG=false`; error envelope only; audit stores field names + safe diffs only | Log-scan test in CI (grep fixtures for seeded canary prompt) |
| T12 | 5 | I | Backup theft reveals prompts | Payloads encrypted at rest with keys outside DB/backup; backups encrypted; retention purge | Restore drill |
| T13 | all | R | Admin denies making a change | Append-only audit (no update/delete routes; DB trigger blocks UPDATE/DELETE on audit_events in gateway) with actor, request_id | Test |
| T14 | 1 | D | Large/slow requests, slowloris | nginx timeouts, body cap, PHP-FPM small pool, no streaming | Config review |
| T15 | 4 | D | Worker overlap after timeout → OOM | Single flock worker, 1 loaded model, abort on timeout + `/api/ps` check | Recovery test (AC "Recovery") |
| T16 | supply chain | T | Malicious dependency/model | Pinned composer/npm lockfiles, `composer audit` in CI, model tag+digest pinned & checked in `models` table | CI |

## Data protection notes
Uganda Data Protection and Privacy Act 2019 applies (personal data of diary users, SACCO members, students). Counsel review required before onboarding WITU, Hive Colab, Nsimbi, SACCO or School data (PRD §04). No cross-project prompt history, no training on prompts.
