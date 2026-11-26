# WITU AI: database access via MobaXterm SSH tunnel (no public database or phpMyAdmin)

MariaDB listens on **127.0.0.1:3306 only** (`/etc/mysql/mariadb.conf.d/60-witu.cnf`). Nothing database-related is reachable from the internet. Administrators connect through an SSH tunnel opened by MobaXterm, so the connection is encrypted and authenticated by your SSH login.

## 1. Create a dedicated admin login (once)

The app user `witu_ai_user` has only the privileges the application needs. Use a separate login for manual work, scoped to the `witu_ai` database. It is **not** root, and it works **only from localhost**, which includes the tunnel:

```bash
sudo mariadb -e "CREATE USER 'witu_ai_admin'@'127.0.0.1' IDENTIFIED BY '$(head -c 24 /dev/urandom | base64 | tr -dc A-Za-z0-9 | tee /dev/tty)';
GRANT ALL PRIVILEGES ON \`witu_ai\`.* TO 'witu_ai_admin'@'127.0.0.1'; FLUSH PRIVILEGES;"
```

The password is printed once (via `/dev/tty`). Store it in your password manager, then run `clear`.

Root keeps unix-socket authentication (`sudo mariadb`). **Never** give root a password-based remote login.

## 2. Option A (recommended): desktop client through a tunnel

This puts no extra software on the VPS and uses no RAM there.

1. In MobaXterm, open **Tunneling → New SSH tunnel** and set:
   - **Local port forwarding**
   - **Forwarded port** (your PC): `13306`
   - **Remote server**: `127.0.0.1`, **Remote port**: `3306`
   - **SSH server**: `ai.witulibrary.org`, port `22`, with your SSH user (the same key as your normal session)
2. Start the tunnel (▶). Leave it running while you work.
3. In **HeidiSQL** (free) or DBeaver, create a connection with:
   - Host `127.0.0.1`, port **13306**
   - User `witu_ai_admin` and the password from step 1
   - Database `witu_ai`
4. Stop the tunnel when finished.

## 3. Option B: phpMyAdmin, localhost-only, through a tunnel

Do not install the Debian `phpmyadmin` package. It pulls in PHP 8.2 Apache modules that conflict with the event MPM and FPM setup (ADR 0002 W1).

### Install

1. Use the official release, check its checksum, and serve it on `127.0.0.1:8082` with its own small PHP-FPM pool. Take the current version number from <https://www.phpmyadmin.net/downloads/>:

   ```bash
   V=5.2.2   # current release
   cd /tmp && curl -fsSLO https://files.phpmyadmin.net/phpMyAdmin/$V/phpMyAdmin-$V-all-languages.tar.xz \
     && curl -fsSLO https://files.phpmyadmin.net/phpMyAdmin/$V/phpMyAdmin-$V-all-languages.tar.xz.sha256 \
     && sha256sum -c phpMyAdmin-$V-all-languages.tar.xz.sha256
   ```

   **Expected:** `phpMyAdmin-<V>-all-languages.tar.xz: OK`. If not, stop.

2. Unpack it, create its account and configuration, and give it its own FPM pool:

   ```bash
   sudo mkdir -p /opt/phpmyadmin && sudo tar -xJf phpMyAdmin-$V-all-languages.tar.xz -C /opt/phpmyadmin --strip-components=1
   id witu-pma >/dev/null 2>&1 || sudo useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin witu-pma
   sudo mkdir -p /opt/phpmyadmin/tmp && sudo chown -R root:witu-pma /opt/phpmyadmin && sudo chmod 770 /opt/phpmyadmin/tmp
   sudo bash -c 'cat > /opt/phpmyadmin/config.inc.php' <<EOF
   <?php
   \$cfg['blowfish_secret'] = '$(head -c 32 /dev/urandom | base64 | tr -dc A-Za-z0-9 | head -c 32)';
   \$cfg['Servers'][1]['host'] = '127.0.0.1';
   \$cfg['Servers'][1]['auth_type'] = 'cookie';
   \$cfg['Servers'][1]['AllowRoot'] = false;
   \$cfg['TempDir'] = '/opt/phpmyadmin/tmp';
   \$cfg['LoginCookieValidity'] = 1800;
   EOF
   sudo chown root:witu-pma /opt/phpmyadmin/config.inc.php && sudo chmod 640 /opt/phpmyadmin/config.inc.php

   sudo tee /etc/php/8.4/fpm/pool.d/witu-pma.conf >/dev/null <<'EOF'
   [witu-pma]
   user = witu-pma
   group = witu-pma
   listen = /run/php/witu-pma.sock
   listen.owner = www-data
   listen.group = www-data
   pm = ondemand
   pm.max_children = 2
   pm.process_idle_timeout = 20s
   php_admin_value[memory_limit] = 128M
   php_admin_value[upload_max_filesize] = 64M
   php_admin_value[post_max_size] = 64M
   EOF
   ```

3. Add an Apache site that listens on localhost only:

   ```bash
   sudo tee /etc/apache2/sites-available/witu-phpmyadmin.conf >/dev/null <<'EOF'
   # phpMyAdmin: localhost only, reached through an SSH tunnel. Never public.
   Listen 127.0.0.1:8082
   <VirtualHost 127.0.0.1:8082>
       DocumentRoot /opt/phpmyadmin
       <Directory /opt/phpmyadmin>
           Require local
           Options -Indexes
           AllowOverride None
       </Directory>
       <Directory /opt/phpmyadmin/tmp>
           Require all denied
       </Directory>
       <FilesMatch "\.php$">
           SetHandler "proxy:unix:/run/php/witu-pma.sock|fcgi://witu-pma"
       </FilesMatch>
   </VirtualHost>
   EOF
   ```

4. Enable it and test:

   ```bash
   sudo php-fpm8.4 -t && sudo systemctl reload php8.4-fpm
   sudo a2ensite witu-phpmyadmin && sudo apache2ctl configtest && sudo systemctl reload apache2
   sudo ss -ltn | grep 8082
   ```

   **Expected:** `127.0.0.1:8082` only. `*:8082` or `0.0.0.0:8082` would be wrong: disable the site and investigate.

### Use

1. In MobaXterm, create a tunnel with local port `18082` → remote `127.0.0.1:8082`.
2. Browse to `http://127.0.0.1:18082/` on your PC.
3. Log in as `witu_ai_admin`.

### Remove

```bash
sudo a2dissite witu-phpmyadmin && sudo systemctl reload apache2 && sudo rm /etc/php/8.4/fpm/pool.d/witu-pma.conf && sudo systemctl reload php8.4-fpm
```

## 4. Import and export

| Task | Command |
|---|---|
| Ad-hoc export (on the VPS) | `sudo mariadb-dump --single-transaction witu_ai \| gzip > ~/witu_ai-$(date +%F).sql.gz`, then download it via SFTP and delete it from the server |
| Import into a **scratch** DB for inspection | `sudo mariadb -e "CREATE DATABASE witu_ai_scratch"; gunzip -c file.sql.gz \| sudo mariadb witu_ai_scratch` (drop it afterwards) |
| Restore production | Always with `sudo witu-rollback-cms --db-only <file.sql.gz>`. It makes a safety dump first and uses maintenance mode. See `backup-restore.md`. |

Never edit production tables by hand while the CMS is live, unless a documented procedure calls for it. The audit log only sees changes made through the CMS.
