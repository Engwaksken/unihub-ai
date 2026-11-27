# ADR 0002: WITU AI on one Debian VPS (Apache, PHP 8.4 beside 8.2, phased gateway cut-over)

- **Status:** Accepted, 2026-11-26
- **Supersedes:** in ADR 0001, the hosting parts of D1/D2 (CMS on cPanel, gateway on a separate nginx VPS) and the hostnames. The application architecture is unchanged.

## Context

WITU runs everything on a single Mochahost VPS:
- Debian 12, 2 vCPU, 4 GB RAM plus 2 GB swap, 80 GB NVMe
- Apache 2.4 with Let's Encrypt, MariaDB 10.11.6 and PHP 8.2.26
- Ollama, with `qwen2.5:1.5b` at about 5.3 tok/s and `qwen2.5:0.5b`
- an initial FastAPI gateway (`/opt/witu-ai-gateway`, `127.0.0.1:8000`) that apps already call via `/v1/models` and `/v1/chat/completions`

The CMS source is cloned to `/var/www/witu-ai-source` and the CMS files are copied to `/var/www/witu-ai`. The domain is `ai.witulibrary.org`.

The repository's CMS needs PHP ≥ 8.3. Its gateway (v2) needs PHP ≥ 8.4 and offers the management API the CMS depends on. That API covers projects, keys, quotas, usage, health, audit and settings, authenticated by a management token and an Ed25519 actor assertion. FastAPI has none of this.

## Decisions

### W1. One PHP 8.4 FPM, installed beside 8.2
- PHP 8.4 comes from packages.sury.org, Debian's standard PHP backport repository.
- PHP 8.2 packages stay installed, and the default `php` CLI keeps pointing where it did.
- Apache keeps its current MPM and modules. The CMS vhost reaches PHP 8.4 FPM through `proxy_fcgi`, which works under any MPM, so co-hosted sites (Koha on `mpm_itk`, anything on mod_php 8.2) are unaffected. Switching to `mpm_event` would break Koha, so the kit never does it.
- Each app gets its own pool, so no shared `www` pool runs:
  - `witu-cms`: ondemand, at most 6 workers
  - `witu-gateway`: phase B
- OPcache runs with `validate_timestamps=0`, and deploys reload FPM.

### W2. The CMS stays in place at `/var/www/witu-ai`
As instructed, there are no release subdirectories. Deploys:
1. build a staging copy in `/var/www/.witu-ai-stage` (git checkout of `cms/`, `composer install --no-dev`, CI-built `public/build`)
2. back up the database and the current code
3. switch maintenance mode on and rsync the staging copy over
4. migrate, only after the migration SQL has been reviewed and approved with `--migrate`
5. rebuild the caches and run a health check

Any failure restores the previous code automatically. A database restore is a separate, explicit step, because restoring loses data written after the backup.

### W3. Accounts and permissions
- `witu-deploy` owns the code and the source checkout. It may run exactly two sudo commands, `witu-deploy-cms` and `witu-rollback-cms`, and both are root-owned copies it cannot edit.
- `witu-cms` runs PHP. It can read the code through its group but can write only `storage/` and `bootstrap/cache/`.
- Apache can traverse the app root and read `public/` only.
- `.env` is mode 640 `witu-deploy:witu-cms`.

### W4. Routing on `ai.witulibrary.org`

| Path | Served by |
|---|---|
| `/v1/health` | FastAPI `GET /health` |
| `/v1/*` | FastAPI `127.0.0.1:8000` (until the phase C cut-over) |
| everything else | Laravel CMS (`/`, `/login`, `/overview`, `/projects`, `/keys`, `/usage`, `/health`, `/settings`, …) |

- `/health` is **not** proxied: the brief's routing table conflicts with the existing CMS *Server & alerts* page at that path, and the brief asks for conflicts to be resolved after checking the real routes.
- `/dashboard` and `/api-keys` are redirects inside Laravel.
- The `/v1` request body is capped at 64 KB, and the proxy timeout is 120 s.

### W5. Gateway: phased replacement, never a big bang
- **Phase A (now):** CMS live. FastAPI is untouched and keeps serving apps. Gateway-backed CMS pages honestly show "Gateway unreachable".
- **Phase B:** install gateway v2 at `/opt/witu-gateway`, beside FastAPI, with:
  - SQLite at `/var/lib/witu-gateway`
  - an internal-surface Apache vhost on **127.0.0.1:8081 only** (`SetEnv GATEWAY_SURFACE internal`), with FPM pool `witu-gateway`
  - worker and scheduler as systemd units

  The CMS connects through `GATEWAY_MGMT_URL=http://127.0.0.1:8081/internal/v1`. This is loopback only, so no TLS is needed, and the token plus the signed assertion still apply. The management modules go live.
- **Phase C:**
  - Gateway v2 gains OpenAI-compatible `/v1/models` and `/v1/chat/completions`, matched to FastAPI's observed request and response shapes, plus the playground and exports.
  - The new endpoints are tested in parallel on a non-public path, using the same keys after import, or keys reissued if FastAPI's hashing can't be imported.
  - Then Apache's `/v1` switches to the v2 public surface.
  - FastAPI is stopped but kept installed for a 14-day rollback window, during which rollback is one config change plus `systemctl start`.
- During phase B both gateways can call Ollama. Ollama runs with `OLLAMA_MAX_LOADED_MODELS=1` and `OLLAMA_NUM_PARALLEL=1`, so generations are serialised either way.

### W6. Frontend assets are never built on the VPS
CI's `cms-bundle` artifact (`unihub-cms-<sha>.zip`) is uploaded over MobaXterm SFTP. The deploy refuses a bundle whose commit doesn't match the deployed ref.

### W7. Database access
- MariaDB binds to `127.0.0.1`.
- Administrators reach it through a MobaXterm SSH tunnel, using a desktop client such as HeidiSQL, or an optional phpMyAdmin bound to `127.0.0.1:8082`, also reached only through the tunnel.
- No public phpMyAdmin, no remote root.

### W8. Memory budget (target steady state, phase B)

| Component | Budget |
|---|---|
| Ollama with `qwen2.5:1.5b` loaded | ~1.6 GB |
| MariaDB (256 MB buffer pool) | ~0.4 GB |
| php8.4-fpm, CMS (≤ 6 workers) plus gateway (≤ 4) | ≤ 0.6 GB |
| Apache (existing MPM, shared with Koha) | ~0.2 GB (measured 193 MB) |
| FastAPI (until phase C) | ~0.1 GB |
| OS and page cache | rest |

Measure with the preflight's per-service RSS table after each phase, and lower the pool sizes before Ollama is starved.

## Consequences
- **No downtime for the running AI service in phases A and B.** Phase C changes the `/v1` backend in one reversible Apache edit.
- **Two gateways run side by side for a while.** They share Ollama, so the monitoring page must show FastAPI's status too (audit G14).
- **The ADR 0001 nginx and systemd files remain** for a future split onto separate hosts. `infra/witu/` is the source of truth for this server.
