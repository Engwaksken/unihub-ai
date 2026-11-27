# WITU AI: production runbook, phase A (CMS live on ai.witulibrary.org)

Every command below is typed into a **MobaXterm SSH session** on the VPS, as your sudo-capable admin user. Files move through MobaXterm's **SFTP side panel**, using drag and drop.

**Stop at each 🛑 checkpoint and compare the output with "Expected".** If it differs, do not continue: paste the output for review.

**These steps leave alone:**
- the FastAPI gateway (`witu-ai-gateway.service`) and Ollama
- DNS and the SSL certificate
- other databases

Background: `docs/adr/0002-witu-single-vps.md`. Gap list: `docs/witu/audit.md`.

---

## Step 0: Get the deployment code onto the server

```bash
cd /var/www/witu-ai-source
sudo git status --short          # must print nothing (no local edits)
sudo git fetch origin
sudo git checkout main && sudo git pull --ff-only
git log --oneline -1
```

**Expected:** the last line shows the merged "WITU VPS deployment kit" commit (or newer).

## Step 1: Preflight (read-only) 🛑

```bash
sudo bash /var/www/witu-ai-source/infra/witu/preflight.sh | tee ~/witu-preflight.txt
```

**Expected highlights:**
- **Listening sockets:**
  - `127.0.0.1:8000` (FastAPI)
  - `127.0.0.1:11434` (Ollama)
  - `127.0.0.1:3306` (MariaDB)
  - `*:80` and `*:443` (Apache)
  - `*:22` (SSH)

  **Anything else on `0.0.0.0`/`*` is a finding.**
- **Legacy gateway HTTP surface:** `/health 200`, and `/v1/models 401` or `403` (no key sent).
- **Laravel:** `artisan: present`, `vendor: missing`, `public/build: missing`.
- **Free disk:** ≥ 3 GB. **MemAvailable:** ≥ 1 GB with the model loaded.

Download `~/witu-preflight.txt` via SFTP and send it for review **before step 3**.

## Step 2: Back up the running FastAPI gateway (no restart)

```bash
sudo bash /var/www/witu-ai-source/infra/witu/backup-legacy-gateway.sh
```

**Expected:** `sqlite integrity: ok` and a listing of `source.tar.gz`, `gateway.db`, `requirements.freeze.txt`, `witu-ai-gateway.service.txt` and `SHA256SUMS` under `/var/backups/witu-ai/legacy-gateway-<timestamp>/`.

**Next:**
1. Copy `source.tar.gz` to your PC for the phase C comparison:
   ```bash
   sudo cp /var/backups/witu-ai/legacy-gateway-*/source.tar.gz ~/ && sudo chown $USER ~/source.tar.gz
   ```
2. Download it in the SFTP panel.
3. Delete the copy: `rm ~/source.tar.gz`.

It contains the FastAPI `.env`. **Do not put it in GitHub.**

## Step 3: PHP 8.4 + Composer + service accounts 🛑

```bash
sudo bash /var/www/witu-ai-source/infra/witu/install-php.sh
```

**Expected (last lines):**
```
default php CLI: 8.2.26   witu uses: /usr/bin/php8.4 (8.4.x)
all required extensions present
Composer version 2.x.x ...
srw-rw---- 1 www-data www-data 0 ... /run/php/witu-cms.sock
Done. Apache is unchanged; ...
```

Then give the source checkout to the deploy account:
```bash
sudo chown -R witu-deploy:witu-deploy /var/www/witu-ai-source
sudo -u witu-deploy git -C /var/www/witu-ai-source status --short   # expected: no output
```

## Step 4: MariaDB database and user

```bash
sudo bash /var/www/witu-ai-source/infra/witu/setup-mariadb.sh
sudo systemctl restart mariadb          # applies 60-witu.cnf (a few seconds; only the new CMS uses it)
sudo mariadb -e "SELECT @@bind_address, @@innodb_buffer_pool_size/1048576 AS pool_mb;"
```

**Expected:**
- `Created database witu_ai and user witu_ai_user (password saved to /root/witu-ai-db.credentials, not shown).`
- grants on `` `witu_ai`.* `` only
- bind `127.0.0.1`, pool_mb `256`

If `systemctl restart mariadb` fails:
1. Run `sudo journalctl -u mariadb -n 30`.
2. Remove `/etc/mysql/mariadb.conf.d/60-witu.cnf`.
3. Start MariaDB again.

## Step 5: Production `.env`

```bash
sudo install -m 640 -o witu-deploy -g witu-cms /var/www/witu-ai-source/infra/witu/env/cms.env.example /var/www/witu-ai/.env
# Database password from step 4 (never echoed):
sudo bash -c 'P=$(grep ^DB_PASSWORD= /root/witu-ai-db.credentials | cut -d= -f2-); sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$P|" /var/www/witu-ai/.env'
# APP_KEY: only if empty (never regenerate on a live system)
sudo grep -q '^APP_KEY=base64:' /var/www/witu-ai/.env || \
  sudo bash -c 'K=$(php8.4 -r "echo base64_encode(random_bytes(32));"); sed -i "s|^APP_KEY=.*|APP_KEY=base64:$K|" /var/www/witu-ai/.env'
sudo nano /var/www/witu-ai/.env      # fill MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD; leave GATEWAY_* for phase B
sudo grep -cE '^(APP_KEY=base64:|DB_PASSWORD=.+)' /var/www/witu-ai/.env
```

**Expected:** the last command prints `2`.

## Step 6: Install the ops tooling (deploy, rollback, backup, cron, logrotate)

```bash
sudo bash /var/www/witu-ai-source/infra/witu/install-ops.sh
```

**Expected:**
- `/etc/sudoers.d/witu-deploy: parsed OK`
- `logrotate config OK`
- `Installed: witu-deploy-cms, witu-rollback-cms, witu-backup ...`

## Step 7: Get the CI-built asset bundle for the commit you deploy

1. On GitHub, open **Actions → ci → the run for the commit at `main`**. The run must be green.
2. Download the artifact `unihub-cms-bundle-<sha>`.
3. Unzip it on your PC. You get `unihub-cms-<short-sha>.zip` and `.zip.sha256`.
4. In MobaXterm SFTP, upload **both files** to `/home/witu-deploy/incoming/`.
5. Fix their ownership:

```bash
sudo chown witu-deploy: /home/witu-deploy/incoming/*
ls -l /home/witu-deploy/incoming/
sudo -u witu-deploy git -C /var/www/witu-ai-source rev-parse --short origin/main   # must equal <short-sha>
```

## Step 8: First deploy 🛑

```bash
sudo witu-deploy-cms origin/main --assets /home/witu-deploy/incoming/unihub-cms-<short-sha>.zip
```

**Expected the first time:**
1. `Pending migrations found`, followed by the `CREATE TABLE` SQL for:
   - `users`, `password_reset_tokens`, `sessions`
   - `cache`, `jobs`
   - the staff-access tables (`project_memberships`, `invitations`, …)
   - `audit_events`, `cms_settings`
2. Then `The live site has NOT been changed.` and exit code 4.

Review the SQL. It only creates tables. Then approve:
```bash
sudo witu-deploy-cms origin/main --assets /home/witu-deploy/incoming/unihub-cms-<short-sha>.zip --migrate
```

**Expected (end):**
```
==> Health check
(Apache not yet pointed at /var/www/witu-ai/public — HTTP check skipped)
==> Deployed <sha>. Rollback: sudo witu-rollback-cms /var/backups/witu-ai/cms-<ts> [--with-db]
```

## Step 9: First-install identities

```bash
cd /var/www/witu-ai
sudo install -d -o witu-cms -g witu-cms -m 700 /etc/witu-ai /etc/witu-ai/secrets
sudo -u witu-cms php8.4 artisan cms:assertion-keypair /etc/witu-ai/secrets/cms_assertion.key   # prints the PUBLIC key: keep it for phase B
sudo -u witu-cms php8.4 artisan cms:system-user
sudo -u witu-cms php8.4 artisan config:cache
sudo -u witu-cms php8.4 artisan cms:bootstrap-owner <your-email@witulibrary.org> "Your Name"     # prints a one-time link, valid 24 h
```

**Expected:** a base64 public key, `System user ready`, and an `https://ai.witulibrary.org/invitations/<token>` link.

Clear the screen afterwards (`clear`). The link is a credential.

## Step 10: Point Apache at Laravel and proxy `/v1` to FastAPI 🛑

```bash
sudo bash /var/www/witu-ai-source/infra/witu/apply-apache.sh
```

**Expected:**
```
==> Backed up /etc/apache2 to /var/backups/witu-ai/apache-<ts>.tar.gz
Syntax OK
==> Smoke test
/            302
/login       200
/up          200
/v1/health   200
```

The script never changes the Apache MPM or disables mod_php, so other sites on the server (Koha, anything on PHP 8.2) keep working. It prints the MPM it found; `MPM unchanged: mpm_itk` or `mpm_prefork` is normal here.

**Instant undo:**
```bash
sudo bash /var/www/witu-ai-source/infra/witu/apply-apache.sh --restore /var/backups/witu-ai/apache-<ts>.tar.gz
```

Also check that existing apps still work through Apache. Use a real app key, typed directly in the terminal rather than pasted into chat or docs:
```bash
curl -s https://ai.witulibrary.org/v1/models -H "Authorization: Bearer <app key>" | head -c 300; echo
```

**Expected:** the same model list FastAPI returned before step 10.

## Step 11: Verify 🛑

| Check | Command or action | Expected |
|---|---|---|
| Certificate renewal still works | `sudo certbot renew --dry-run` | `Congratulations, all simulated renewals succeeded` |
| Nothing new is public | `sudo ss -ltnp \| grep -vE '127.0.0.1\|::1'` | only `:22`, `:80`, `:443` |
| PHP errors | `sudo tail -n 20 /var/log/witu-ai/cms-php-error.log` | empty or no new entries |
| Laravel log | `sudo ls /var/www/witu-ai/storage/logs/` | no errors after `tail` |
| Login | open the step 9 link, set a password, enrol 2FA (authenticator app), save the recovery codes | lands on Overview |
| Gateway pages | Overview / Projects | **"Gateway unreachable"**: correct until phase B, no invented numbers |
| Logout / login again | top-right menu | asks for the 2FA code |
| Memory with everything running | `free -m` and `sudo bash …/preflight.sh \| sed -n '/Memory per service/,/=====/p'` | swap use is not growing, and MemAvailable stays ≥ 800 MB |
| Scheduler | wait 2 minutes, then `sudo journalctl -u cron --since '-3 min' \| grep witu-cms` | cron entries for `witu-cms` |
| Nightly backup | `sudo witu-backup` | `nightly backup ok` |
| Restore test | `sudo witu-rollback-cms` (list), then the restore drill in `backup-restore.md` | |
| Reboot recovery (maintenance window) | `sudo reboot`, reconnect, `systemctl is-active apache2 php8.4-fpm mariadb ollama witu-ai-gateway` | all `active`; `/login` 200 |

Phase A is complete when every row passes.

---

# Phase B: gateway v2 beside FastAPI (management pages go live)

Gateway v2 is installed on **127.0.0.1:8081 only**. The CMS manages it there (organizations, projects, keys, models, tasks, jobs, usage, server health). FastAPI keeps serving the apps' `/v1` traffic unchanged until phase C. Both can call Ollama, which serialises generations.

New on the server: account `witu-gw`, code in `/opt/witu-gateway`, SQLite in `/var/lib/witu-gateway`, secrets in `/etc/witu-gateway/secrets`, PHP-FPM pool `witu-gateway`, units `witu-gateway-worker` and `witu-gateway-scheduler`, Apache site `witu-gateway-internal` (loopback). The nightly backup includes the gateway database and secrets.

## Step B1: Update the ops scripts

```bash
sudo -u witu-deploy git -C /var/www/witu-ai-source fetch origin
sudo -u witu-deploy git -C /var/www/witu-ai-source checkout --detach origin/main
sudo bash /var/www/witu-ai-source/infra/witu/install-ops.sh
```

**Expected:** `Installed: witu-deploy-cms, witu-rollback-cms, witu-backup, witu-deploy-gateway …`

## Step B2: Prepare the gateway (one time) 🛑

```bash
sudo bash /var/www/witu-ai-source/infra/witu/install-gateway.sh
```

**Expected:** the new directories listed, `witu-gateway.sock`, two units enabled, `Syntax OK`, `listening on 127.0.0.1:8081 (loopback only)`, `Done.` Apache is reloaded gracefully; the public sites stay up. If the configtest fails, Apache's previous configuration is restored automatically.

## Step B3: First gateway deploy 🛑

```bash
sudo witu-deploy-gateway origin/main
```

The first run prints the gateway's `CREATE TABLE` SQL (organizations, projects, api_keys, models, tasks, jobs, usage_events, health_samples, alerts, audit_events, settings …) and stops with `The live gateway has NOT been changed.` They all go into the new, empty SQLite file. Approve:

```bash
sudo witu-deploy-gateway origin/main --migrate
```

**Expected (end):** `GET http://127.0.0.1:8081/internal/v1/summary without credentials -> 401 (expected 401)`, both units `active`, `Deployed gateway <sha>`.

## Step B4: Connect the CMS 🛑

```bash
sudo bash /var/www/witu-ai-source/infra/witu/connect-gateway.sh
```

It creates the gateway's secrets, gives the gateway the CMS's public key (derived from the CMS key file; nothing is pasted), mints a management token and writes it straight into the CMS `.env` (never shown), then tests the connection.

**Expected (end):** `OK: the gateway accepted the CMS token and signed assertion.` and `Ollama: idle` or `loaded`.

## Step B5: First use

1. **Models & tasks → Models → Refresh from Ollama.** `qwen2.5:0.5b` and `qwen2.5:1.5b` appear. Enable the one you will use.
2. **Settings → Gateway policy:** set the inference values from the brief: max queue wait 30 s, global queue 8, max output tokens 256.
3. **Organizations → New organization**, then **Projects → New project**.
4. **Server & alerts:** CPU, RAM, swap, disk and the services table fill in within a minute.

Apps keep using FastAPI's `/v1` until phase C, so keys created here are for gateway v2 only.

## Gateway releases

```bash
sudo witu-deploy-gateway origin/main            # add --migrate after reviewing any SQL it prints
```

Rollback: the deploy restores the previous code automatically if its health check fails. Each deploy leaves `/var/backups/witu-ai/gateway-<ts>/` with `code.tar.gz` and an online SQLite copy `gateway.sqlite`.

---

# Phase C: apps use gateway v2 (OpenAI-compatible, keys from the CMS)

Gateway v2 answers OpenAI-style requests at **`https://ai.witulibrary.org/gw/v1`** (`/models`, `/chat/completions`, plus `/jobs`). FastAPI keeps `/v1` until every app has moved; then `/v1` is switched over (step C6).

How a request runs: the project key is checked, the project must be allowed the task **`chat`**, whose published system template is always sent first, then the app's messages. Quotas, the single worker queue and usage reports apply as for any job. The request waits for the answer up to the **Chat API wait** setting (default 85 s).

## Step C1: Deploy

```bash
witu-deploy-cms origin/main
sudo -u witu-deploy git -C /var/www/witu-ai-source checkout --detach origin/main
bash /var/www/witu-ai-source/infra/witu/install-gateway.sh        # adds the public PHP pool and the loopback site on 127.0.0.1:8082
witu-deploy-gateway origin/main                                   # prints one ALTER TABLE (jobs.input_kind); then:
witu-deploy-gateway origin/main --migrate
bash /var/www/witu-ai-source/infra/witu/apply-apache.sh            # adds /gw/v1 to ai.witulibrary.org (Apache restarts, 1-2 s)
```

**Expected:** `listening on 127.0.0.1:8082 (loopback only)`; the Apache smoke test shows `/gw/v1/models 401` (gateway reachable, no key sent).

## Step C2: The `chat` task (CMS → Models & tasks → Tasks)

**New task or version**: key `chat`, model `qwen2.5:1.5b`, output cap `256`, format Text, and a system template such as:

> You are the WITU AI assistant. Answer clearly and briefly. If you are not sure, say so.

Then **Publish** the version. Apps add their own system prompt after this one.

## Step C3: The app's project and key

1. **Projects → (project) → Settings:** add `chat` to *Allowed tasks*. Check its quotas (requests per day and per minute).
2. **API keys → Issue key.** Copy it once, straight into the app's settings.

## Step C4: Point the app at it (Elevate Her)

Elevate Her → AI settings: **Provider** *Custom (OpenAI-compatible)*, **Endpoint** `https://ai.witulibrary.org/gw/v1`, **Model** `qwen2.5:1.5b`, **API key** from step C3. **Test connection** should answer with a one-sentence tip.

## Step C5: Check

```bash
curl -s https://ai.witulibrary.org/gw/v1/models -H "Authorization: Bearer <key>"     # type the key here, never paste it into chat or docs
```

CMS **Jobs** shows the request (kind `chat`), **Usage** counts it per project, key and model.

## Step C6: Final cut-over of `/v1` (later, when FastAPI has no users)

Point `/v1/` in `infra/witu/apache/ai.witulibrary.org-le-ssl.conf` at `http://127.0.0.1:8082/v1/`, run `apply-apache.sh`, and stop FastAPI (`systemctl disable --now witu-ai-gateway`), keeping it installed for 14 days as a rollback.

---

## Routine deploys (after phase A)

### One-time: let the server download CI bundles itself

GitHub only serves CI artifacts to an authenticated caller, even for a public repository. Create a token that can do nothing else:

1. GitHub → **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**.
2. Name `witu-vps-deploy`, expiry 1 year (put a reminder in the calendar), **Repository access: Only select repositories → `unihub-ai`**.
3. **Permissions → Repository → Actions: Read-only.** Leave everything else at "No access". Generate, and copy the token.
4. On the server, store it root-only. `read -s` keeps it off the screen and out of the shell history:
   ```bash
   sudo install -d -m 700 /etc/witu-ai
   read -rsp "GitHub token: " T && printf '%s\n' "$T" | sudo tee /etc/witu-ai/github-token >/dev/null && unset T; echo
   sudo chmod 600 /etc/witu-ai/github-token && sudo chown root:root /etc/witu-ai/github-token
   ```

Without the token the deploy still works with `--assets <zip>` (SFTP upload, step 7).

### Each release

1. Merge a PR to `main` and wait for CI to pass on GitHub.
2. Refresh the ops scripts. This step only matters when the PR changed `infra/witu/`; review that diff first:
   ```bash
   sudo -u witu-deploy git -C /var/www/witu-ai-source fetch origin
   sudo -u witu-deploy git -C /var/www/witu-ai-source checkout --detach origin/main
   sudo bash /var/www/witu-ai-source/infra/witu/install-ops.sh
   ```
3. Deploy. The script pulls `main`, downloads the CI bundle built for exactly that commit (refusing if that CI run did not succeed), and deploys. The first run prints any pending migration SQL and stops; re-run it with `--migrate` once you have reviewed the SQL:
   ```bash
   sudo witu-deploy-cms origin/main
   ```
   - If the health check fails, the script puts the previous code back automatically.
   - Manual rollback: `sudo witu-rollback-cms` lists the backups. Then run `sudo witu-rollback-cms /var/backups/witu-ai/cms-<ts>`, and add `--with-db` only if the bad release changed data.

Git on the server always runs as `witu-deploy`, which owns the checkout. Running git as root there fails with "dubious ownership". `witu-deploy` can run `sudo witu-deploy-cms …` and `sudo witu-rollback-cms …`, and those are the only root commands it is allowed.
