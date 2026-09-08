# Access lifecycle — September 2026

Branch: `fix/access-lifecycle` · Base: `main @ 1b22f59`
Full suite at the last commit: **3,162 tests, 9,379 assertions, 0 failures.**

Three things a person's access should follow — being hired, being someone, and
leaving — and none of them worked. This is what changed and why.

---

## 1 · Leaving now closes the account

**Commits:** `e20bb20` (failing tests) · `06114ac` (fix)

### What was wrong

`EmployeeService::separate()` soft-deleted the `hr_employees` row and stopped
there. The `users` row it hangs off kept its password, its org membership, every
module role and any token already in a browser. The person left the HR lists and
nothing else: they could still sign in, still open Projects, still own CRM leads,
and still occupy a paid seat.

Nothing announced it. The separation succeeded and every HR screen agreed the
person was gone — which is the shape of failure that is hardest to notice.

### What changed

| File | Change |
|---|---|
| `app/Models/User.php` | `STATUS_ACTIVE` / `STATUS_INACTIVE` + `isActive()` |
| `app/Http/Middleware/EnsureAccountIsActive.php` | **new** — refuses every api request from a closed account |
| `bootstrap/app.php` | appends that middleware to the whole `api` group |
| `app/Http/Controllers/AuthController.php` | `validate()` → check state → `login()` |
| `app/Support/Seats.php` | stops counting closed accounts |
| `app/Modules/Hr/Services/EmployeeService.php` | `closeAccount()` / `openAccount()`, wired into separate / reinstate / rehire |
| `app/Modules/Hr/Console/Commands/ApplySeparations.php` | **new** — `hr:apply-separations`, daily 00:20 |

### Why it was built this way

**Refuse, do not dismantle.** Nothing is removed — no role, no token, no
membership — so reinstatement and rehire have nothing to put back. That is the
same bargain `CrmAccessService` already makes for its own scoped suspension
(*"suspension never removed a role, never moved a lead and never revoked a
token"*). Removing access would mean remembering what to restore, and that is a
second table and a new class of bug.

**`users.status` was already there for this.** The column arrived with the CRM's
additive users migration for *"reversible user deactivate"* and was never wired to
anything; CRM solved its own case with a scoped table instead. No migration was
needed. ⚠️ It is NOT the `status` field `Crm\Resources\UserResource` exposes —
that one is computed from `isSuspended()` and is CRM-scoped.

**No token revocation.** The middleware runs on every api request, so a token
issued before the separation is already inert. Revoking would break the promise
that reinstatement returns the person to the session they never lost.

**Timing follows the kind of ending.** A termination closes the account inside
`separate()`; a resignation is worked out over a notice period, so the scheduled
sweep closes it on the last working day. The command loops
`CurrentOrg::eachOrg()` — the console has no authenticated user, and unpinned it
would report success having read nothing at all.

**The `org_user` row deliberately STAYS.** Rehire needs it, and `OrgUserRule`
validates every person-picker (project member, task assignee, lead owner) against
it — deleting it would unpick the person from the work they actually did. Only
the seat COUNT changes.

**Tasks and leads are untouched.** The assignee stays named, so history still says
who did the work.

### One thing found by the tests

`Auth::attempt()` does two things in one call: it checks the password AND fires
the `Login` event, which a listener writes to the audit trail as "signed in". A
closed account refused after that call would have left a permanent record saying
it had signed in. Login is now `Auth::validate()` → account state → `Auth::login()`,
so only a real sign-in is recorded. This was not on the plan; a failing test found it.

---

## 2 · A profile is one person, not two

**Commit:** `fd2deb6`

### What was wrong

`hr_employees` holds what HR maintains. `users` holds what CRM and Projects read,
because those modules have no employee row to read from — a client may not have
availed HR at all. Name and email already crossed that seam. Phone and photo did
not, and on the live installation the gap was total:

```
hr_employees   13 rows — 13 with a phone, 13 with a photo
users          17 rows —  0 with a phone,  2 with an avatar
```

Both avatars had been uploaded separately inside CRM. So someone set their photo
in HR and stayed a blank circle in the other three systems.

### What changed

`EmployeeService`: `create()` and `updateIdentity()` now carry the phone;
`setPhoto()` / `removePhoto()` mirror the path onto `users.avatar` via a new
`mirrorAvatar()`.

### Why it is safe to share a path

HR and CRM write to the **same private disk** (`local` → `storage/app/private`),
so `ProfilePhotoService::url()` serves an HR path unchanged. Its `owns()` claims
only `crm/profile-photos/`, so the CRM's cleanup can never unlink an HR file — and
`mirrorAvatar()` only ever writes an HR path or null, so it cannot clobber a CRM
upload with something that module would then refuse to clean up. Removing the
photo clears the avatar too, rather than leaving it pointing at a deleted file.

---

## 3 · A new hire can start work the same day

**Commit:** `f15ffbc`

### What was wrong

Hiring assigned `hr_employee` and stopped. `department_id` was recorded and never
read by an access check, so someone hired to work on projects had a login and
nowhere to go until an admin noticed and granted it from another module's Team
screen.

### What changed

| File | Change |
|---|---|
| `app/Support/GrantableRoles.php` | **new** — the closed list, the tier order, the narrow statuses |
| `app/Modules/Hr/Http/Requests/StoreEmployeeRequest.php` | validates `grant_roles` |
| `app/Modules/Hr/Services/EmployeeService.php` | assigns them at create |
| `frontend/src/modules/hr/components/grant-access-section.tsx` | **new** — the form section |
| `frontend/src/modules/hr/components/employee-form-dialog.tsx` | wires it in |
| `frontend/src/modules/hr/types/employee.ts` | `grant_roles?: string[]` |

### Why the grant is on the form, not a department mapping

A department → role mapping was the obvious design and was rejected on evidence.
`hr_departments` carries `org_id`: **departments belong to each tenant**, so no
mapping can ship — every client would have to configure one before it helped
anyone, and re-configure it whenever a department changed. Putting the grant on
the form HR is already filling in needs no migration, no per-org setup, and works
for every client immediately.

### Why the list is closed

This endpoint is reachable by `hr_officer`, who has no business minting a platform
owner or another module's admin. Three checks: the role is on the list, the org
actually availed that module, and the person's employment lets them hold that tier.

### Why a trainee's tier is not a choice

`ojt`, `part_time` and `project_based` may only ever hold the narrowest role a
module offers, whatever the form asks for. Everything above it can delete a
project, approve a task or rewrite a ledger. The order in `GrantableRoles::BY_MODULE`
is load-bearing: element zero is the trainee tier.

### Why it only ever ADDS

Taking access away stays in each module's Team screen, where the admin doing it
can see what they are removing — the same rule `CrmAccessService` keeps. A
department change grants nothing and revokes nothing: moving desks is not usually
moving jobs, and silently losing access to a project you still hold is worse than
a second click.

---

## Deploying this branch

**No migrations.** No dependency changes. `git pull` → `npm run build` →
`pm2 restart` → `php8.4 artisan optimize:clear`. Rollback is
`git reset --hard 1b22f59` and rebuild — the database is untouched by it.

`hr:apply-separations` runs from `HrServiceProvider`'s schedule, so it needs
`php artisan schedule:run` on cron, which this host already has.

---

## Investigated and deliberately NOT changed

**`GET /api/v1/connections` has no role middleware.** Initially recorded as a gap.
It is not: `frontend/src/core/components/sidebar.tsx` calls `useConnections()` for
**every** logged-in user, to hide bridge links whose connection an admin switched
off. Gating it would 403 the sidebar for everyone. The route comment already said
so (*"read is any org member; the toggle is org-admin-gated inside the
controller"*), and the toggle IS guarded (`acc_admin` or `super_admin`, 403). The
payload is the reading tenant's own matrix — no credentials, no other tenant's data.

**`contributionBaseFor()` has no `min(earnings, basic)` cap.** Real, and only half
the company's stated policy is in code. It is dormant: for August every employee's
monthly earnings sat **below** their basic (closest gap −₱2,999), so the cap would
never fire and no contribution is currently wrong. It becomes live the moment
someone earns roughly ₱3,000 of premiums in one month. Deferred deliberately, and
it should land as a settings toggle rather than a hardcoded `min()` — it is company
policy, not law.

---

## Live changes made outside this branch

Recorded here because they are not visible in the diff.

| Change | Why |
|---|---|
| `main` merged `staging`, then `multi-tenancy`, then the accounting frontend | Release |
| `system.viteseo.ph` cut over to `vsp-release9` | 47 migrations; `org_id` on 122 tables; payroll totals identical before and after |
| Pay-schedule anchor patch committed (`8f5ee53`) | It had been running on live as an **uncommitted working-tree change** since 2026-09-01, present in no branch |
| 12 accounting permissions + `acc_admin` / `acc_accountant` / `acc_viewer` seeded by hand | They exist only in `RolesAndPermissionsSeeder`, which must never be run whole on live — it `syncPermissions()` on seven existing roles and would flatten them |
| Accounting access set to: `super_admin` ×3, `acc_admin` (Cristina, Shandy), `acc_viewer` (Edgar) | Money is sensitive; `journal.reverse` and `accounts.delete` rewrite recorded history |
| Two test accounts deleted (`edgar.dioniso@viteseo.ph`, `ddd dd ddd dd`) | A typo duplicate and a July test fixture. Gross unchanged either side |
| MySQL grant on `viteseo_platform_%_test` for the `teamora` user | HR feature tests run against a real MySQL database, one per run; without it 54 of them error before starting. Scoped to that pattern only |

### An anomaly worth knowing

A DIRECT user permission (`model_has_permissions`) granted on 2026-09-08 and
verified present twice had disappeared by the same afternoon, with nothing in
`hr_audit_logs` — that table does not cover permission changes — and no action in
the session accounting for it. **Prefer roles over direct user permissions on this
installation** until someone can explain it.
