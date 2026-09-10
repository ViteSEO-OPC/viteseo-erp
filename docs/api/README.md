# Vite SEO Platform — API Reference

Per-module endpoint reference for the three pods (HRIS, CRM, PMS) on the unified
Laravel platform. Generated from the actual route files, controllers, Form
Requests, and policies — not hand-invented. If a route is missing here, it does
not exist in code.

## Base URL

```
/api/v1
```

Every module mounts under this prefix:

| System | Prefix | Doc |
|---|---|---|
| Shared authentication | `/api/v1/auth` | [auth.md](./auth.md) |
| HRIS (HR module) | `/api/v1/hr` | [hris.md](./hris.md) |
| CRM (Zerem module) | `/api/v1/crm` | [crm.md](./crm.md) |
| PMS (Projects module) | `/api/v1/projects` | [pms.md](./pms.md) |

## Authentication

Auth is **Laravel Sanctum**, in two shapes (details in [auth.md](./auth.md)):

- **The app (browser):** a session held in an httpOnly cookie. Fetch
  `/api/sanctum/csrf-cookie`, then `POST /api/v1/auth/login` with `email` +
  `password`; send cookies (`withCredentials`) and `X-XSRF-TOKEN` from then on.
  No token is returned to the browser.
- **Servers and automations:** a named, expiring token minted on the host with
  `php8.4 artisan tokens:issue <email> <name>`, sent as:

```
Authorization: Bearer <token>
```

All endpoints require `auth:sanctum` **except** `POST /api/v1/auth/login` and the
public CRM Gmail webhook (`POST /api/v1/crm/webhooks/gmail`, authenticated by a
Google-signed OIDC token inside the controller instead of Sanctum).

## Response envelope

Success responses use API Resources and always wrap payloads in `data`:

```json
{ "data": { ... } }
```

Paginated list endpoints add Laravel's `meta` + `links`:

```json
{
  "data": [ ... ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 }
}
```

Errors use Laravel's standard shape:

```json
{ "message": "The given data was invalid.", "errors": { "field": ["reason"] } }
```

Common status codes: `401` (not signed in / session or token expired), `419`
(missing `X-XSRF-TOKEN`), `403` (authenticated but lacks the
role/permission/ownership), `404` (not found or soft-deleted), `422` (validation).

## Roles (per-system)

Seven Spatie roles (guard `web`), seeded in
`backend/database/seeders/RolesAndPermissionsSeeder.php`. Roles are explicit per
module so a person can hold a high role in one system and a low one in another
(e.g. `crm_admin` + `hr_employee`). There is intentionally **no** `hr_admin` /
`crm_super_admin` / `pm_admin` — `super_admin` sits at the top of every module.

| Role | System | Scope |
|---|---|---|
| `super_admin` | All | CEO / platform-wide, all access. Bypasses CRM policies via `Gate::before`. |
| `hr_officer` | HRIS | HR management — every `hr.*` permission. |
| `hr_employee` | HRIS | HRIS self-service (view own payslip, file leave, clock in, …). |
| `crm_admin` | CRM | CRM manager — all `crm.*` except deleting user accounts. |
| `crm_bd` | CRM | Business Development — leads/notes/follow-ups/planner/dashboard on **own** records; no export/delete/reports/user-mgmt. |
| `pm_apm` | PMS | Projects management — full `projects.*`. |
| `pm_member` | PMS | Projects member — read + contribute (comments/links create) only. |

### How guards appear in the routes

- **HRIS** gates management routes with `role:hr_officer|super_admin` middleware;
  self-service routes are open to any authenticated user and scope to the caller
  server-side (identity resolved from `$request->user()->employee`).
- **CRM** authorizes via Policies (`->can(...)`) backed by `crm.*` permissions,
  plus explicit `permission:` / `role:` middleware on some routes. BDs are
  additionally bounded to leads visible to them (`Lead::isVisibleTo`).
- **PMS** authorizes via Policies backed by `projects.*` permissions; "manager"
  actions (archive/restore, and create/update/delete on core resources) require
  the `pm_apm` or `super_admin` role.

## Notes

- **HR** tables are `hr_*`, **PMS** `pm_*`, **CRM** `crm_*`/`leads`; shared tables
  (`users`, `roles`, `permissions`) are unprefixed.
- Money is `decimal(12,2)`. Datetimes stored UTC; HR day-boundaries computed in
  Asia/Manila. HR records are soft-deleted.
- "My X" endpoints never trust a client-sent `employee_id`; ownership comes from
  the signed-in user.
</content>
</invoke>
