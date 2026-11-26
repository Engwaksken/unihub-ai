# Unihub AI — staff portal (CMS)

Laravel 13 staff portal for the Unihub Central AI platform (ADR 0001, D11/D12). It runs on cPanel shared hosting with MariaDB. It never stores projects, keys or quotas: those live in the gateway and are read and changed through the gateway management API (`docs/api/management-auth.md`).

**P1 scope built here:** staff authentication with mandatory TOTP MFA, roles and project memberships, the permission matrix, the bootstrap-owner command, invitations, the users page, the CMS audit trail, the Ed25519 actor-assertion signer, `GatewayClient`, and the Organizations and Projects pages. Other modules (Overview, API keys, Models & tasks, Jobs, Usage, Server & alerts, Settings, and the project tabs other than Settings) stay hidden behind feature flags until they are built.

Pinned versions (see `composer.lock` / `package-lock.json`): laravel/framework 13.44.1, laravel/fortify 1.44.0, livewire/livewire 4.4.10, vite 8.3.8, @fontsource/poppins 5.3.0. PHP ≥ 8.3 with `sodium`, `pdo_mysql`, `intl`, `mbstring`, `openssl` and `fileinfo`.

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
7. **Cron** (cPanel → Cron Jobs): `* * * * * cd /home/<account>/cms && php artisan schedule:run >> /dev/null 2>&1`. Use the PHP 8.3+ binary path the host gives, for example `/opt/cpanel/ea-php83/root/usr/bin/php`.
8. **Gateway allowlist:** the gateway's management vhost allowlists the cPanel egress IP (ADR D6). Check outbound HTTPS from cPanel before go-live (`docs/preflight.md`).

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
| `tests/` | PHPUnit feature tests (MFA, invitations, permission matrix, assertion, gateway client, pages, CSRF, sessions) |
