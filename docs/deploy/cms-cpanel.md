# Installing the CMS bundle on cPanel shared hosting

The bundle `unihub-cms-<sha>.zip` already contains:
- production `vendor/`
- compiled `public/build/`

**You do not need Composer, Node or npm on the server.** You only need:
- PHP **8.3 or 8.4** with `sodium`, `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` and `intl` (cPanel → *Select PHP Version* → Extensions)
- a MariaDB database
- HTTPS

The AI gateway does **not** run here; it runs on the VPS (ADR 0001).

## Recommended: deploy over SSH
With SSH access, run everything from your own machine (Git Bash on Windows). There are no temporary cron jobs, and each deploy is atomic with a one-command rollback.

1. **Preflight** (read-only). Paste the output into `docs/preflight-results.md`:
   ```bash
   ssh <user>@<cpanel-host> 'bash -s' < infra/preflight/cpanel-preflight.sh
   ```
   It reports:
   - which PHP 8.3/8.4 binaries exist and which required extensions are missing
   - whether `composer`, `git` and `mysqldump` are available
   - outbound HTTPS and the egress IP (needed for the gateway allowlist)

   Pick the PHP binary it lists, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`.
2. **Database:** in cPanel → MySQL Databases, create the DB and user.
3. **Deploy.** Download the bundle from the GitHub Actions `cms-bundle` artifact, or build it with `bash infra/scripts/build-cms-bundle.sh`, then run:
   ```bash
   bash infra/scripts/deploy-cms-ssh.sh <user>@<cpanel-host> dist/unihub-cms-<sha>.zip /opt/cpanel/ea-php83/root/usr/bin/php
   ```
   - The **first run** creates `~/unihub-cms/shared/.env` with a generated `APP_KEY`, then stops. Edit that file over SSH (APP_URL, DB_*, MAIL_*, GATEWAY_*, `CMS_ASSERTION_SECRET_KEY_PATH=$HOME/unihub-cms/secrets/cms_assertion.key`) and run the same command again.
   - **Every run:** the script checks the PHP version and extensions, backs up the DB with `mysqldump` if available, migrates, caches the config, and switches `~/unihub-cms/current` to the new release.
4. **Document root:** in cPanel → Domains, set it to `~/unihub-cms/current/public`. This is a one-time step.
5. **First-install commands:** the script prints them at the end. They are `cms:assertion-keypair`, `cms:system-user`, `cms:bootstrap-owner` and the scheduler cron line.
6. **Rollback:**
   ```bash
   bash infra/scripts/deploy-cms-ssh.sh <user>@<cpanel-host> --rollback <php-binary>
   ```

The manual File Manager procedure below is for accounts **without** SSH.

## First install (without SSH)
1. **Upload and extract.** In cPanel → File Manager, upload the zip to your home directory, e.g. `/home/<account>/`, and **Extract**. This creates `/home/<account>/cms/`. Check its hash first against `unihub-cms-<sha>.zip.sha256`.
2. **Document root.** In cPanel → Domains, set the (sub)domain's document root to `/home/<account>/cms/public`.
   - If the host won't allow that, use a symlink: `public_html` → `cms/public`.
   - **Never** serve the `cms/` folder itself.
3. **Database.** In cPanel → MySQL Databases, create a database and a user, grant ALL on that database, and note the credentials.
4. **`.env`.** Copy `cms/.env.example` to `cms/.env` and set:
   - `APP_URL=https://<your-domain>`
   - `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (DB_HOST is usually `localhost`)
   - `MAIL_*`, using the cPanel e-mail account's SMTP settings
   - `GATEWAY_MGMT_URL`, plus `GATEWAY_MGMT_TOKEN` (from `php artisan gateway:secrets mgmt-token` on the VPS)
   - `CMS_ASSERTION_SECRET_KEY_PATH=/home/<account>/secrets/cms_assertion.key`. Keep this path **outside** `cms/` and `public_html`.
   
   File permissions:
   - `.env`: 600
   - `storage/` and `bootstrap/cache/`: writable by your account (755 or 775)
5. **One-time commands.** With Terminal or SSH, run these from `/home/<account>/cms`. Use the host's PHP binary, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`:
   ```bash
   php artisan key:generate --force
   php artisan migrate --force
   php artisan cms:assertion-keypair /home/<account>/secrets/cms_assertion.key   # prints the PUBLIC key
   php artisan cms:system-user
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   php artisan cms:bootstrap-owner owner@yourdomain "Owner Name"                 # prints a one-time link (24 h)
   ```
   - **Gateway public key:** send the public key printed by `cms:assertion-keypair` to the VPS admin, who runs `php artisan gateway:secrets cms-key --public-key=<that value>`.
   - **No Terminal or SSH?** Add each command as a **temporary** cPanel cron entry, for example:
     ```
     cd /home/<account>/cms && php artisan migrate --force >> /home/<account>/cms-setup.log 2>&1
     ```
     Let it run once, read the log, then **delete the entry**. The bootstrap-owner link and the public key appear in that log, so delete the log afterwards.
6. **Scheduler cron.** In cPanel → Cron Jobs, add this permanent entry, every minute:
   ```
   * * * * * cd /home/<account>/cms && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   It runs the alert e-mail notifier.
7. **Go-live checks:**
   - Open the bootstrap link, set a password and enrol 2FA.
   - Under Settings, set the alert recipients.
   - The Server & alerts page should show live data. A "Gateway unreachable" error means the VPS allowlist, the token or outbound HTTPS needs fixing (see `docs/preflight.md`).

## Updating to a new bundle
1. Back up the database (cPanel → Backup, or phpMyAdmin export) and keep the current `cms/` folder, e.g. renamed to `cms-previous/`.
2. Extract the new zip to `cms-new/`, then copy over the old `.env` and the `storage/` contents.
3. Swap the folders: `cms` → `cms-previous`, then `cms-new` → `cms`.
4. Run `php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache`, via Terminal or a temporary cron entry.
5. **Rollback:** swap the folders back. If a migration changed the schema, also restore the database backup.

## Never upload
- `node_modules/`
- a `.env` containing real secrets into `public_html`
- the assertion secret key anywhere under the web root
