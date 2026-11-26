# Unihub AI Gateway

The gateway is a Laravel 13 API-only app (PHP ≥ 8.4) with three surfaces:

| Surface | Host | Routes |
|---|---|---|
| Public job API | `ai.kemmytech.com` | `GET /healthz`, `POST/GET/DELETE /v1/jobs[/{id}]` (contract: `docs/api/public-openapi.yaml`) |
| Management API | `mgmt-ai.kemmytech.com` (allowlisted) | `/internal/v1/*` (contract: `docs/api/management-openapi.yaml` + `docs/api/management-auth.md`) |
| Worker | systemd | `php artisan gateway:work`: the single inference worker that calls Ollama on loopback |

The architecture is in `docs/adr/0001-platform-architecture.md`. The code map:

| Concern | Where |
|---|---|
| Atomic admission (idempotency, policy, token budget, queue caps, quotas) | `app/Services/AdmissionService.php` |
| State machine, fair claim, leases, retry policy, recovery | `app/Services/JobQueue.php` |
| Ollama call (fixed loopback URL, `stream:false`, `num_ctx` 2048, `num_thread` 2) | `app/Services/Ollama/OllamaClient.php` |
| Key format, HMAC digest, constant-time verify | `app/Services/KeyService.php` |
| Payload encryption (XChaCha20-Poly1305, keys outside DB) | `app/Services/PayloadCipher.php`, `app/Services/Secrets.php` |
| Ed25519 actor assertion and permission matrix | `app/Management/*` |
| Telemetry and alert rules | `app/Services/Telemetry.php` |
| Surface separation, body limits, request IDs, error envelope | `app/Http/Middleware/*`, `bootstrap/app.php` |

## Local development (Windows)
```bash
P="/c/Users/<you>/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe"
cp .env.example .env    # then set APP_ENV=local, APP_DEBUG=true, DB_DATABASE=<abs path>/database/database.sqlite,
                        # GATEWAY_SECRETS_PATH=<abs path>/storage/secrets, GATEWAY_ALLOW_ANY_SURFACE=true,
                        # GATEWAY_WORKER_LOCK=<abs path>/storage/framework/worker.lock
"$P" artisan key:generate
"$P" artisan migrate --seed
"$P" artisan gateway:secrets init
"$P" artisan gateway:key:issue my-digital-diary-staging          # prints the key once
"$P" -S 127.0.0.1:11999 ../tests/fakes/fake-ollama.php &          # or a real Ollama: OLLAMA_URL=http://127.0.0.1:11434
"$P" artisan serve
"$P" artisan gateway:work
"$P" artisan test                                                  # 59 tests
```

## Artisan commands
| Command | Purpose |
|---|---|
| `gateway:work [--once] [--stop-when-empty] [--max-time=3600]` | Single worker. Holds an exclusive flock, and recovers orphaned leases when it starts. |
| `gateway:sample` | Health sample and alert evaluation. Scheduled every 30 s. |
| `gateway:purge` | Retention: payloads at expiry, metadata after 90 days, nonces. Scheduled every 15 min. |
| `gateway:secrets init` | Creates the key pepper and the first payload key. Idempotent. |
| `gateway:secrets mgmt-token [--replace]` | Issues the CMS service token, shown once. Keeps the previous token valid for overlap unless `--replace` is given. |
| `gateway:secrets cms-key --public-key=<b64>` | Installs the CMS Ed25519 public key |
| `gateway:secrets rotate-payload-key` | New payload key for new rows. Old keys stay until their rows expire. |
| `gateway:key:issue <slug>` | Bootstrap or break-glass key issue, audited. The normal path is the CMS. |

## Production layout
- `/etc/unihub-gateway/secrets/`, mode 0700, owned by `unihub-gw`
- DB at `/var/lib/unihub-gateway/gateway.sqlite` (WAL)
- Releases under `/opt/unihub-gateway/releases/`
- Units and configs in `infra/systemd`, `infra/nginx` and `infra/scripts` (deploy, rollback, backup, firewall)

The worker, Ollama and the scheduler run as systemd services. **Nothing here runs on cPanel.**

## Known limitations (MVP)
- **Token estimate:** a `bytes/3` heuristic. Calibrate against the Qwen tokenizer in the P4 benchmark.
- **Concurrency:** behaviour under concurrent HTTP load is verified by `tests/load/burst.sh` on the staging VPS. The Windows dev server is single-threaded.
- **Timeouts:** inference timeouts are not retried. Transient errors (connection refused, Ollama 5xx, worker lost) are retried once, after 5 s.
- **Payloads:** no payload viewing in the management API, by design (PRD §03).
