# Unihub AI — staff portal (CMS)

Laravel 13 staff portal for the Unihub Central AI platform (ADR 0001, D11/D12). It runs on cPanel shared hosting with MariaDB. It never stores projects, keys or quotas: those live in the gateway and are read and changed through the gateway management API (`docs/api/management-auth.md`).

**Built (P1 + P3):** staff authentication with mandatory TOTP MFA, roles and project memberships, the permission matrix, bootstrap-owner and invitations, the users page, the Ed25519 actor-assertion signer and `GatewayClient`, and every dashboard module:
- Overview
- Organizations
- Projects, with tabs for API keys, Jobs, Usage and Settings
- API keys
- Models & tasks
- Jobs & queue
- Usage & reports
- Server & alerts
- Users & audit (merged CMS and gateway audit)
- Settings
- the scheduled alert e-mail notifier

Each module has a feature flag in `config/cms.php` (`CMS_FEATURE_*`, all on by default). Turning one off hides the module and makes its routes return 404.

Pinned versions (see `composer.lock` / `package-lock.json`): laravel/framework 13.44.1, laravel/fortify 1.44.0, livewire/livewire 4.4.10, vite 8.3.8, chart.js 4.6.2, @fontsource/poppins 5.3.0. PHP ≥ 8.3 with `sodium`, `pdo_mysql`, `intl`, `mbstring`, `openssl` and `fileinfo`.

## Local setup

```bash
composer install
cp .env.example .env          # then for local http: APP_ENV=local, APP_DEBUG=true,
                              # SESSION_SECURE_COOKIE=false, DB_CONNECTION=sqlite (or a local MariaDB)
php artisan key:generate
php artisan migrate
npm ci && npm run build       # or `npm run dev`
php artisan cms:assertion-keypair ../.secrets/cms_assertion.key   # outside public/; prints the public key
php artisan cms:bootstrap-owner you@example.org "Your Name"       # prints a single-use set-password link
php artisan serve
```

`php artisan db:seed` creates **synthetic** staff only (`owner@`, `ops@`, `pm@`, `auditor@example.test`). It prints a random shared password and refuses to run in production. Set `SEED_PROJECT_UUIDS` to the gateway's staging fixture project UUIDs so the memberships point at real staging projects.

Tests: `php artisan test` (PHPUnit, in-memory SQLite, the gateway is faked with `Http::fake()`; stray HTTP calls fail the test).

CI (P1-7, CMS part): `composer ci` runs `pint --test`, PHPStan/Larastan level 5 (`phpstan.neon`), the test suite and `composer audit`. The pipeline should also run `npm ci && npm audit && npm run build` and publish `public/build` as the deploy artefact. The workflow file belongs at the repository root (CI provider still open, Q-06), so it is not part of this directory.

## How it works

### Authentication (Fortify)
- Login, logout, password reset and TOTP 2FA with recovery codes. There is **no registration route**.
- **MFA is mandatory.** The `mfa` middleware (`EnsureTwoFactorEnrolled`) sends any signed-in user without a *confirmed* TOTP secret to `/security/two-factor` before any portal route. Livewire update requests re-apply it as persistent middleware.
- Login is throttled at 5 per minute per email+IP and 20 per minute per IP. The 2FA challenge allows 5 attempts per minute.
- Disabled accounts and invited accounts that have not set a password cannot authenticate. The hash check always runs, so response timing does not reveal whether an account exists. `EnsureUserIsActive` signs out a disabled user on their next request.
- Database sessions (`SESSION_DRIVER=database`) make revocation work. "Revoke sessions" and "Disable" delete the user's rows in `sessions` and rotate the remember token. The production cookie is `Secure`, `HttpOnly`, `SameSite=Lax`, with an encrypted payload.
- Every response carries security headers, including a nonce-based CSP. Alpine (bundled with Livewire) needs `'unsafe-eval'`.

### Staff, roles and memberships
- Users have UUID primary keys. The UUID is the `sub` claim sent to the gateway.
- **Roles: a `user_roles` pivot `(user_id, role)` that is unique per pair, with the role validated against `App\Enums\Role`.** Why: the four roles are a fixed vocabulary that is part of the wire contract, so they live in code rather than an editable `roles` table. A pivot (rather than a JSON column) gives a real unique constraint, an index for "does an owner exist?" (bootstrap) and portable queries on MariaDB.
- `project_memberships (user_id, gateway_project_uuid, role)` is unique per `(user_id, gateway_project_uuid)`. A membership's role must be a project-scoped role (`project_manager` or `auditor`) that the user holds. Scope is checked per role: a user who is project manager of A and auditor of B can edit A's metadata but not B's.
- **`App\Authorization\Permissions`** is the single map from capability to roles and scope (`all` or `assigned`), plus whether the capability is project-scoped. It transcribes `docs/permissions.md`, and every capability is registered as a Gate ability with the same name. Single resources outside a user's scope return **404**.
- Staff invitations are owner-only. An invitation creates the account with an unusable random password and e-mails a single-use link that expires in 24 hours. Only a SHA-256 digest of the token is stored. Accepting the invitation sets the password, signs the user in and goes straight to 2FA enrolment.
- `php artisan cms:bootstrap-owner {email} {name}` refuses to run if any owner exists. If that link expires unused, `php artisan cms:reissue-invitation {email}` issues a new one. It works only for accounts that have never set a password.

### CMS audit trail
`audit_events` is append-only: the model throws on update and delete, and no route changes rows. Each row has `request_id`, actor, action, subject, project UUID, IP and user agent. Recorded actions:
- login success, failure and lockout; logout; password reset
- 2FA failure, enrolment and disable; recovery code regeneration
- invite, reissue and accept
- role changes; membership add, change and remove (one row per project)
- session revoke; user disable and enable

Metadata keys that look like secrets (password, token, secret, code, …) are dropped before insert. Project managers and auditors see only events tied to their assigned projects.

### Gateway client
- `App\Gateway\AssertionSigner` builds the compact JWS exactly as `management-auth.md` specifies:
  - EdDSA/Ed25519 via `sodium_crypto_sign_detached`, base64url without padding, `kid=cms-1`
  - `exp = iat + 60`, a fresh UUID `jti`
  - `roles`, plus `projects` taken from the user's memberships (`[]` when there are none)
  - `mfa: true`

  It refuses to sign for a disabled user or a user without confirmed MFA.
- `App\Gateway\GatewayClient` uses the Laravel HTTP client with connect timeout 5 s and timeout 15 s. Every request sends `Authorization: Bearer`, a freshly signed `X-Actor-Assertion`, `X-Request-Id` (the CMS request id, also written to the CMS audit trail) and `Accept: application/json`. POST, PATCH and PUT also send `Idempotency-Key`. Forms carry a per-render UUID key, so a double submit reuses it and a re-rendered form gets a new one.
- Requests are **not retried automatically**, because each assertion's `jti` is single-use.
- The client parses the `{data}`, `{data, next_cursor}` and `{error}` envelopes and maps errors to typed exceptions:

  | Gateway response | CMS behaviour |
  |---|---|
  | 422 | `GatewayValidationException`: field errors land on the form inputs with the same names |
  | 404 | 404 page |
  | 403 | 403 page |
  | 409 | form error |
  | 429 | form error |
  | 401 | "Gateway unreachable" page (misconfiguration) |
  | 5xx, connection failure, malformed body | "Gateway unreachable" page (HTTP 503) or an inline error state |

- Config lives in `config/services.php` → `gateway`, read from env `GATEWAY_MGMT_URL` (including `/internal/v1`), `GATEWAY_MGMT_TOKEN` and `CMS_ASSERTION_SECRET_KEY_PATH`.

### Dashboard modules (P3)
All data comes live from the gateway management API; nothing is copied into the CMS database.
- Missing or old telemetry renders as **Unknown** or **Stale**, never 0.
- Token counts the model did not report render as **n/a**.
- Every chart (Chart.js, bundled by Vite) has a data table next to it.

| Module | Gateway calls | Notes |
|---|---|---|
| Overview | `GET /summary` (cached 30 s per actor scope) | The date range is converted from Kampala days to UTC instants. The gateway's `requests_today` and trend days are UTC days, and the page says so. |
| Project → API keys | `GET/POST /projects/{id}/keys`, `POST /keys/{id}/revoke` | See "API key secrets" below. Rotate sends `rotates_key_id` and `overlap_days` (0–30). Revoke is behind a confirmation dialog. |
| API keys (menu) | `GET /projects`, then one `GET /projects/{id}/keys` per project | The gateway has no cross-project key listing, so the page goes through visible projects 10 at a time (at most 11 calls per page). Keys are managed on each project's tab. |
| Models & tasks | `GET /models`, `POST /models/refresh`, `PATCH /models/{id}`, `GET/POST /tasks`, `POST /tasks/{key}/versions/{v}/publish` | Enabling a model requires a licence. Templates are shown escaped in `<pre>`. Only drafts can be published, behind a confirmation dialog. There are no pull, delete or shell actions. |
| Jobs & queue (and project tab) | `GET /jobs`, `POST /jobs/{id}/cancel`, `POST /jobs/{id}/retry` | Metadata only. Cancelling a queued job needs confirmation. Retrying a failed job needs a reason; the gateway decides eligibility, and its 409 shows as a form error. |
| Usage & reports (and project tab) | `GET /usage` with `tz=` set to the display zone, `GET /usage?format=csv` | The CSV is the gateway's own file, streamed through. The `project_id` filter is checked against the user's scope (404 otherwise). Without a filter, the gateway scopes by the assertion. Cost is labelled **estimate**. |
| Server & alerts | `GET /health` (cached 30 s), `GET /alerts`, `POST /alerts/{id}/ack`, `PUT /admission` | Pausing or resuming admission needs a reason and confirmation. Owners and ops admins get admission state from `/settings`; auditors get it from `/summary`. The page also shows the CMS's own "gateway unreachable" alert. |
| Audit | CMS `audit_events` plus `GET /audit` (and `format=csv`) | Each row is labelled CMS or Gateway. "All sources" merges the newest 50 events of each; pick one source to page further. The CSV combines both sources, scoped to the user, with formula cells neutralised. |
| Settings | `GET/PATCH /settings` | See "Settings" below. |

**API key secrets.** A new key's secret is rendered **once**, in the response to the POST, with `Cache-Control: no-store`. It is never redirected, flashed, logged or stored. Re-submitting the form reuses its Idempotency-Key, so the gateway answers `IDEMPOTENCY_REPLAY_UNAVAILABLE` and the secret cannot be shown a second time.

**Settings.**
- Gateway policy settings: owners can edit them and ops admins can read them. Only changed keys are sent, and a gateway 422 appears on the matching field.
- Portal settings (portal name, display time zone, alert recipients) live in `cms_settings`. Owners and ops admins can edit them, and every change is audited.

### Alert e-mail notifier (scheduled)
**The system actor.** Run `php artisan cms:system-user` once per environment. It creates the account `alert-notifier@system.invalid`:
- Its only role is `auditor`, with no project memberships. Auditors have `health.view` on all projects, which is all `GET /alerts` needs, and no write capability.
- It is marked `is_system = true`. It has no password, cannot sign in (Fortify rejects it), cannot be invited, and owners cannot edit its access.
- Disabling it on the Users page stops the notifier.

**The scheduled job.** `cms:notify-alerts` runs every minute (`routes/console.php`, triggered by the cPanel cron). Each gateway call carries an assertion with these claims:

| Claim | Value |
|---|---|
| `sub` | the system user's uuid |
| `name` | `CMS alert notifier (system)` |
| `roles` | `["auditor"]` |
| `projects` | `[]` |
| `project_roles` | `{}` |
| `mfa` | `true` |
| `iss`, `aud`, `iat`, `exp`, `jti` | as for any staff assertion |

The signer allows `mfa: true` without a TOTP enrolment only for `is_system` users, because a system account has no interactive login to protect.

**On each run:**
- It polls `GET /alerts?status=open` and e-mails each new alert id once to each recipient, one message per recipient.
- Sent alerts are recorded in `alert_notifications` so they are not repeated. If a send fails, the record is released and the next run retries.
- If the poll keeps failing for 2 minutes, it records a local `gateway_unreachable` alert in `system_state`. It e-mails that alert once per outage, shows it on Server & alerts, and sends a recovery e-mail when the gateway answers again.

### UI
- Blade and Livewire 4. The project list is a Livewire component with search, status and organization filters and cursor paging.
- The layout is responsive with a sidebar menu that becomes off-canvas on mobile.
- Styling:
  - Poppins is self-hosted through `@fontsource`.
  - Burgundy `#660033` is the primary colour.
  - Gold `#D4AF37` is used only for borders and accents, never for text on white.
  - Focus outlines are visible.
- Pages handle keyboard use, give skip links, use native `<dialog>` confirmations for destructive actions, and have empty, loading and error states.
- All gateway data is rendered with `{{ }}`. The only raw output is the locally generated TOTP QR SVG.
- Times are stored in UTC and shown in `APP_DISPLAY_TIMEZONE` (Africa/Kampala).

## Deploying to cPanel

1. **Build in CI, not on cPanel.** Run `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`. Upload the result, including `vendor/` and `public/build/`. Do not upload `node_modules/`, `.env` or tests.
2. **Document root → `cms/public`.** In cPanel → Domains, point the (sub)domain at the `public` directory. If the host does not allow that, symlink `public_html` to `cms/public`. Never expose the project root.
3. **Configure `.env`** from `.env.example`:
   - `APP_ENV=production`, `APP_DEBUG=false`
   - MariaDB credentials and `DB_CONNECTION=mariadb`
   - SMTP settings
   - `SESSION_SECURE_COOKIE=true` (the site must be served over HTTPS)
   - gateway URL and token
4. **Generate the assertion key outside the web root:** `php artisan cms:assertion-keypair /home/<account>/secrets/cms_assertion.key`. Set `CMS_ASSERTION_SECRET_KEY_PATH` to that path. Install the printed public key on the gateway as `<GATEWAY_SECRETS_PATH>/cms_assertion.pub`.
5. **Prepare the app:** run `php artisan key:generate` (first deploy only), then `php artisan migrate --force` and `php artisan config:cache route:cache view:cache`.
6. **Create the first owner:** run `php artisan cms:bootstrap-owner owner@yourdomain "Name"` and open the printed link within 24 hours.
7. **System actor for scheduled jobs:** run `php artisan cms:system-user`, then set the alert recipients under Settings.
8. **Cron** (cPanel → Cron Jobs): `* * * * * cd /home/<account>/cms && php artisan schedule:run >> /dev/null 2>&1`. Use the PHP 8.3+ binary path the host gives, for example `/opt/cpanel/ea-php83/root/usr/bin/php`.
9. **Gateway allowlist:** the gateway's management vhost allowlists the cPanel egress IP (ADR D6). Check outbound HTTPS from cPanel before go-live (`docs/preflight.md`).

## Repository layout (CMS-specific)

| Path | Contents |
|---|---|
| `app/Authorization/Permissions.php` | capability → roles and scope (mirrors `docs/permissions.md`) |
| `app/Gateway/` | `AssertionSigner`, `GatewayClient`, DTOs, typed exceptions |
| `app/Services/StaffDirectory.php` | invitations, role and membership changes, disable, session revocation (all audited) |
| `app/Http/Middleware/` | MFA enforcement, active-user check, request id, security headers |
| `app/Livewire/ProjectIndex.php` | project list |
| `app/Console/Commands/` | `cms:bootstrap-owner`, `cms:reissue-invitation`, `cms:assertion-keypair` |
| `config/cms.php` | feature flags, navigation, project tabs, invitation lifetime |
| `app/Services/CmsSettings.php`, `app/Services/GatewayReachability.php` | portal settings; gateway reachability streak |
| `app/Console/Commands/NotifyAlerts.php`, `app/Console/Commands/CreateSystemUser.php` | scheduled alert notifier and its system actor |
| `tests/` | PHPUnit feature tests (MFA, invitations, permission matrix, assertion, gateway client, pages, CSRF, sessions) |
