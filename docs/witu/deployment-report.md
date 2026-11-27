# WITU AI: deployment completion report

> **Status: NOT DEPLOYED.** Phase A has not yet been executed on the server.
>
> This report is filled in from real command output while working through `runbook.md`. Nothing is marked as passing until it has been observed on `ai.witulibrary.org`.

## Phase A: CMS live

| # | Check | Result | Evidence / date |
|---|---|---|---|
| A1 | Preflight reviewed (ports, memory, PHP, legacy gateway) | ☐ | |
| A2 | Legacy FastAPI gateway backed up (`sqlite integrity: ok`) | ☐ | |
| A3 | PHP 8.4 FPM installed beside 8.2; default CLI unchanged | ☐ | |
| A4 | `witu_ai` DB and least-privilege user; MariaDB bound to 127.0.0.1 | ☐ | |
| A5 | `.env` in place (APP_KEY, DB, mail), 640 | ☐ | |
| A6 | First deploy: migration SQL reviewed, migrated, health OK | ☐ | commit: |
| A7 | Apache switched; `/` 302, `/login` 200, `/up` 200, `/v1/health` 200 | ☐ | |
| A8 | Existing app call to `/v1/models` through Apache unchanged | ☐ | |
| A9 | Owner login + 2FA + logout/login | ☐ | |
| A10 | Gateway-backed pages show "Gateway unreachable" (no invented data) | ☐ | |
| A11 | `certbot renew --dry-run` succeeds | ☐ | |
| A12 | Only 22/80/443 listen publicly | ☐ | |
| A13 | Nightly backup runs; restore drill passes | ☐ | |
| A14 | Reboot: all services come back | ☐ | |
| A15 | Memory with Ollama generating: MemAvailable ≥ 800 MB, no swap growth | ☐ | |
| A16 | Mobile layout check (phone browser) on login and Overview | ☐ | |

## Phase B: gateway v2 connected (not started)

This phase runs after the FastAPI source has been reviewed (`audit.md` §5).

## Phase C: OpenAI-compatible API on gateway v2, playground, exports, `/v1` cut-over (not started)

## Open issues

- Audit gaps G2–G7 (`audit.md` §3). G11 and G14 are implemented in code and go live with phase B.
