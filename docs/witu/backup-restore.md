# WITU AI: backup and recovery

## What is backed up, and where

| Backup | When | Contents | Location | Kept |
|---|---|---|---|---|
| Nightly (`witu-backup`, root cron 02:30) | daily | `witu_ai` DB dump, `storage/app` (uploads), `.env`, legacy FastAPI `gateway.db` | `/var/backups/witu-ai/nightly/` | 14 days |
| Pre-deploy (`witu-deploy-cms`) | every deploy | DB dump, code tarball, `storage/app`, `.env`, previous commit | `/var/backups/witu-ai/cms-<ts>/` | last 10 |
| Pre-restore safety dump | every DB restore | DB dump of the state being replaced | `/var/backups/witu-ai/pre-restore-<ts>.sql.gz` | manual |
| Apache config (`apply-apache.sh`) | each apply | all of `/etc/apache2` | `/var/backups/witu-ai/apache-<ts>.tar.gz` | manual |
| Legacy gateway (`backup-legacy-gateway.sh`) | before any gateway change | FastAPI source, `.env`, unit, SQLite (online backup) | `/var/backups/witu-ai/legacy-gateway-<ts>/` | manual |

`/var/backups/witu-ai` is root-only (0700) and contains secrets (`.env`).

**Off-server copy (weekly, mandatory):**
1. In MobaXterm SFTP, download the newest `nightly/witu_ai-*.sql.gz`, `storage-app-*.tar.gz` and `env-*` to encrypted storage on the WITU side, for example a BitLocker drive.
2. Check that the dump is intact: `gzip -t` on the server, or 7-Zip "Test" on Windows.

A backup that lives only on the VPS disk is lost with the VPS.

## Restore procedures

**List what exists:**
```bash
sudo witu-rollback-cms
```

**Bad deploy, code only** (no data changes involved):
```bash
sudo witu-rollback-cms /var/backups/witu-ai/cms-<ts>
```

**Bad deploy that migrated the DB:**
```bash
sudo witu-rollback-cms /var/backups/witu-ai/cms-<ts> --with-db
```
Data written since that deploy is lost. Export anything you need first: the command makes a safety dump automatically, so it can be inspected later.

**Data mistake** (wrong deletion): restore the night before, preferably into a scratch DB first (`database-access.md` §4), and copy back only the affected rows. A full restore is:
```bash
sudo witu-rollback-cms --db-only /var/backups/witu-ai/nightly/witu_ai-<ts>.sql.gz
```

**Uploads lost:**
```bash
sudo tar -C /var/www/witu-ai -xzf /var/backups/witu-ai/nightly/storage-app-<ts>.tar.gz && sudo chown -R witu-cms:witu-cms /var/www/witu-ai/storage/app
```

**Lost `.env`:** copy `env-<ts>` back to `/var/www/witu-ai/.env`, then fix its owner and mode: `witu-deploy:witu-cms`, 640. Then run:
```bash
sudo -u witu-cms php8.4 /var/www/witu-ai/artisan config:cache && sudo systemctl reload php8.4-fpm
```
**Never generate a new `APP_KEY`.** The old key is needed to read 2FA secrets.

**Whole server lost:**
1. Provision Debian 12.
2. Run runbook steps 0–6 and 10.
3. Restore `.env` from the off-server copy.
4. Deploy the same commit (step 8).
5. Restore the DB with `witu-rollback-cms --db-only`, then restore `storage/app`.

## Restore drill (quarterly, and once before go-live)

```bash
F=$(ls -1t /var/backups/witu-ai/nightly/witu_ai-*.sql.gz | head -1)
sudo mariadb -e "CREATE DATABASE witu_ai_drill"
gunzip -c "$F" | sudo mariadb witu_ai_drill
sudo mariadb -N -e "SELECT COUNT(*) FROM witu_ai_drill.users; SELECT COUNT(*) FROM witu_ai_drill.audit_events;"
sudo mariadb -N -e "SELECT COUNT(*) FROM witu_ai.users; SELECT COUNT(*) FROM witu_ai.audit_events;"
sudo mariadb -e "DROP DATABASE witu_ai_drill"
```

**Expected:** the drill counts equal, or are slightly below, the live counts. Record the date and result in `docs/witu/deployment-report.md`.
