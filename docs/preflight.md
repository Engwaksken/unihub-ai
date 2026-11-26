# Preflight checklist (P0-7)

The PRD says to **stop if the provider prohibits installation**. Nothing gets installed until this checklist has been recorded in `docs/preflight-results.md` and the PM has approved it.

## VPS (gateway and Ollama host)
1. Get the provider's written confirmation, or its AUP clause, that persistent services and CPU-heavy AI workloads are allowed.
2. Run `infra/preflight/server-preflight.sh`. The script is read-only.
3. Stop and escalate if any of these is true:
   - Frappe/ERPNext, MariaDB, Redis or other heavy services are running and leave less than **2.3 GB** available RAM.
   - The CPU has no AVX2.
   - There is no root/sudo access.
   - Free disk is under 20 GB.
   - The host is OpenVZ/LXC without systemd.
4. Record the OS, PHP availability (8.3+ from the distro or ondrej/sury PPA), and the free ports 80 and 443.

## cPanel (CMS host)
Use cPanel → *Select PHP Version*, *MySQL Databases*, *Cron Jobs* and *Terminal* (if enabled) to record:
- PHP versions available (≥ 8.3 needed). Extensions: `sodium`, `pdo_mysql`, `intl`, `mbstring`, `openssl`, `fileinfo`.
- MariaDB version (≥ 10.6 preferred).
- Whether you can set the document root to `cms/public`, or must use a symlink.
- Whether cron runs every minute.
- SMTP sender for alerts.
- **Outbound HTTPS works:** `curl -sI https://example.com` from Terminal, or a test PHP script.
- **Egress IP:** `curl -s https://ifconfig.me` from the cPanel Terminal. Check with the host whether this IP is shared.
- SSH/git deploy access, or upload-only.

## DNS / TLS
- Who controls `kemmytech.com` DNS. Planned A records: `ai.` and `mgmt-ai.` → VPS IP.
- Certificates come from Let's Encrypt via certbot on the VPS.
