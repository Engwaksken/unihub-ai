# Permission matrix (enforced in CMS policies AND gateway management API)

Scope legend: **All** = every organization/project; **Assigned** = projects in the actor's `project_memberships` (carried in the assertion `projects` claim); — = denied.

| Capability | Owner | Ops admin | Project manager | Auditor |
|---|---|---|---|---|
| View overview / summary | All | All | Assigned | Assigned |
| Create / archive organization | All | — | — | — |
| Create project | All | All | — | — |
| Edit project metadata (name, contact, environment) | All | All | Assigned | — |
| Change project status (enable/disable/archive) | All | All | — | — |
| Change quotas / queue limits | All | — | — (cannot raise own) | — |
| Change task/model allowlist for a project | All | All | — | — |
| Issue / rotate / revoke API key | All | — (no secret access) | Assigned | — |
| View key metadata (prefix, expiry, last used) | All | All | Assigned | Assigned |
| View models; enable approved model tag | All | All | view only | view only |
| Create task / publish new task version | All | All | — | — |
| View job metadata (never payloads by default) | All | All | Assigned | Assigned |
| Cancel queued job / retry eligible failed job | All | All | Assigned | — |
| Delete finished jobs (metadata and payloads; usage is kept) | All | All | — | — |
| View usage & reports; CSV export | All | All | Assigned | Assigned |
| View health; acknowledge alerts | All | All | — | view only |
| Pause/resume admission (incident) | All | All | — | — |
| Invite staff; assign roles/memberships; revoke sessions | All | — | — | — |
| View / export audit | All | All | Assigned (project events only) | Assigned |
| Edit settings: limits, retention, providers | All | — | — | — |
| Edit settings: branding, timezone, notification recipients | All | All | — | — |
| Approve external provider | All (requires recorded org consent) | — | — | — |

Capability identifiers: the gateway uses `app/Management/Capabilities.php` (`org.view`, `project.view`, `project.edit_meta`, …), and the CMS uses `cms/app/Authorization/Permissions.php`.
- The CMS may be **stricter** than the gateway. For example, the CMS Organizations page is limited to owners and ops admins, while the gateway also lets project managers and auditors see the organizations of their assigned projects.
- The CMS must never be looser than the gateway.
- A user who holds several project-scoped roles is evaluated per project (`project_roles` claim, `docs/api/management-auth.md`).

Rules:
- MFA is required for all staff (and blocking for Owner).
- Payload (prompt/output) viewing is **off for everyone** in MVP. Adding it later needs a new ADR, a per-access audit trail and project-owner consent.
- Denied or out-of-scope single resources return **404** (management API and portal), so other projects' IDs are not revealed.
