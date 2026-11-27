# WITU AI: technical maintenance

## Services on the VPS

| Service | Unit | Listens on | Logs |
|---|---|---|---|
| Apache (public HTTPS) | `apache2` | `*:80`, `*:443` | `/var/log/apache2/witu-ai-{access,error}.log` |
| PHP 8.4 FPM: pool `witu-cms` (and `witu-gateway` in phase B) | `php8.4-fpm` | `/run/php/witu-cms.sock` | `/var/log/witu-ai/cms-php-error.log`, `cms-fpm-slow.log` |
| Laravel CMS | (runs inside FPM) | — | `/var/www/witu-ai/storage/logs/laravel-YYYY-MM-DD.log` (14 days) |
| CMS scheduler | cron `/etc/cron.d/witu-ai` | — | `journalctl -u cron` |
| MariaDB 10.11 | `mariadb` | `127.0.0.1:3306` | `/var/log/mysql/mariadb-slow.log` (queries > 1 s) |
| Ollama | `ollama` | `127.0.0.1:11434` | `journalctl -u ollama` |
| Legacy FastAPI gateway | `witu-ai-gateway` | `127.0.0.1:8000` | `journalctl -u witu-ai-gateway` |
| PHP 8.2 | (packages only, not used by WITU) | — | — |

**Quick status:**
```bash
systemctl is-active apache2 php8.4-fpm mariadb ollama witu-ai-gateway
```
**Restart one service:** `sudo systemctl restart <unit>`. **Restart PHP after editing `.env`:**
```bash
sudo -u witu-cms php8.4 /var/www/witu-ai/artisan config:cache && sudo systemctl reload php8.4-fpm
```

## Permissions

The model is enforced on every deploy by `fix_permissions` in `infra/witu/lib.sh`.

| Path | Owner | Mode | Why |
|---|---|---|---|
| `/var/www/witu-ai` (code) | `witu-deploy:witu-cms` | dirs 750, files 640 | PHP can read the code but cannot change it |
| `storage/`, `bootstrap/cache/` | `witu-cms:witu-cms` | 2770 / 660 | the only writable paths |
| `.env` | `witu-deploy:witu-cms` | 640 | secrets, not readable by Apache |
| `public/` | as above, plus world-readable static files | | Apache serves CSS, JS and images directly |
| `/etc/witu-ai/secrets/cms_assertion.key` | `witu-cms` | 600 | the CMS's signing key for gateway calls |
| `/var/backups/witu-ai` | `root` | 700 | backups contain secrets |

**Never `chmod -R 777`.** If something is unwritable, re-run a deploy or fix ownership as above.

## Performance settings

Starting values for 2 vCPU / 4 GB. **Tune them from measurements, not guesses.**

| Setting | Value | Where |
|---|---|---|
| Concurrent generations | 1 | gateway v2 single worker; `OLLAMA_NUM_PARALLEL=1` |
| Loaded models | 1 | `OLLAMA_MAX_LOADED_MODELS=1` (in the `ollama.service` override) |
| Context length | 2048 | `num_ctx` in gateway requests |
| Max waiting requests | 8 | CMS → Settings → `global_queue_limit` (phase B) |
| Queue wait timeout | 30 s | CMS → Settings → `queue_wait_max_seconds` (default is 120: change it in phase B) |
| Max output tokens | 256 cap; 128 as the per-task default the brief asks for | Settings → `max_output_tokens` (cap); each task version's output limit (CMS → Tasks) |
| CMS PHP workers | ≤ 6, ondemand | `/etc/php/8.4/fpm/pool.d/witu-cms.conf` |
| OPcache | 96 MB, no timestamp checks | `/etc/php/8.4/fpm/conf.d/99-witu.ini` |
| MariaDB buffer pool | 256 MB, 40 connections | `/etc/mysql/mariadb.conf.d/60-witu.cnf` |
| Request bodies | CMS 8 MB, `/v1` 64 KB | Apache vhost `LimitRequestBody` |
| Proxy timeout `/v1` | 120 s | Apache vhost |

**What to watch:** `free -m`, specifically that swap use does not climb. Also watch `uptime`: a load average above 2 means saturation. To see who is using memory:
```bash
ps -eo rss,comm --sort=-rss | head
```

If MemAvailable drops below about 600 MB while Ollama generates:
1. Lower `pm.max_children` first.
2. Then lower the MariaDB buffer pool.
3. Never raise Ollama parallelism.

## Security checklist (re-check monthly)

- `sudo ss -ltnp | grep -vE '127.0.0.1|::1'` shows only 22, 80 and 443.
- `sudo certbot renew --dry-run` succeeds, and `systemctl list-timers certbot.timer` shows the next run.
- `sudo apt list --upgradable`: apply Debian security updates. For PHP updates, run `sudo systemctl reload php8.4-fpm` afterwards.
- CMS → Audit: review failed logins and key creations/revocations.
- There are no Laravel `ERROR` lines you can't explain:
  ```bash
  sudo grep -c ERROR /var/www/witu-ai/storage/logs/laravel-$(date +%F).log
  ```
- `APP_DEBUG=false`. Check with `sudo grep ^APP_DEBUG /var/www/witu-ai/.env`.
- The off-server backup copy is less than 7 days old (`backup-restore.md`).

## Logs never contain prompts or AI output

The CMS does not log request bodies. The gateway stores only metadata, and payloads are encrypted with a TTL. The Apache access log records paths only, never bodies. **Do not raise `LOG_LEVEL` to `debug` in production.**
