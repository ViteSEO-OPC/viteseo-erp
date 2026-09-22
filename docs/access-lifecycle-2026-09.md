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

## 4 · The browser holds no token

### What was wrong

A successful sign-in answered with a Sanctum token in the JSON body, and the
frontend kept it in `localStorage["token"]`. Anything that runs on the page can
read localStorage -- a compromised npm dependency, an injected script -- and so can
anyone who opens DevTools. The token then worked from any machine for 30 days,
used or not. Every sign-in minted a new one: 68 on live, 33 still valid.

### What changed

- The browser signs in with an **httpOnly session cookie** (Sanctum SPA mode,
  `statefulApi()`). The login response carries no token, and a sign-in that does
  not come from the app's own origin is refused (403) before the password is checked.
- `/api/sanctum/csrf-cookie` -- moved under `/api`, because nginx sends only `/api/*`
  to Laravel; the default `/sanctum/` would land on Next.js and 404.
- `/broadcasting/auth` starts its own session; it is not in the `api` group.
- Frontend: `withCredentials` + `withXSRFToken` on both axios clients; the two `fetch`
  callers (realtime auth, keepalive note save) send the cookie and `X-XSRF-TOKEN`;
  the sign-in probe on load is quiet, so `/forgot-password` is not bounced; 419 is
  treated as 401; the old `localStorage["token"]` is deleted on every load.
- Machine clients (n8n, the Zerem scraper) keep bearer tokens.
- CORS offers credentials **only to origins named in `CORS_ALLOWED_ORIGINS`**.

### Why it was built this way

- nginx serves the frontend and the API from one origin, so the cookie needs no
  cross-site arrangement -- and the `sessions` table already records the user,
  address, browser and last activity of every session, which is the device list in §6.
- **No token fallback for scripts.** A stolen password would otherwise buy a 30-day
  credential that appears in no device list and ignores the idle timeout. In the
  retained access logs every non-browser sign-in on live was an ops check, and all
  of them failed (422).
- **CORS credentials follow from naming origins, never from a flag.** With `'*'` the
  CORS package answers credentialed requests by echoing whatever Origin asked, and
  Lax cookies ride same-site requests -- so n8n, erp or portfolio on `viteseo.ph`
  could read a signed-in user's API responses.
- **"Not visible in inspect" means not readable by script.** The person at the
  keyboard can still see their own cookie in DevTools → Application → Cookies; no
  system can prevent that. `XSRF-TOKEN` is readable on purpose -- it is not a
  credential, only proof a request came from a page on this site.
- **Keep the Referer.** Chrome sends no `Origin` on same-origin GETs, so Sanctum
  recognises the browser by `Referer`. A `Referrer-Policy: no-referrer` anywhere
  (nginx, Next) would turn every GET into a 401. There is none today.

---

## 5 · How long a session lives

| Rule | Where |
|---|---|
| **2 days without use** ends it | `SESSION_LIFETIME` (default now 2880). Live's `.env` says `120` -- **remove that line** or the idle timeout is two hours |
| **30 days** ends it however busy | `EndSessionsPastTheirCeiling` -- the ceiling tokens had; sessions have none by default |
| Changing the password keeps **this** device, under a new id, and ends every other | `AuthController::changePassword` |

The password hash Sanctum's `AuthenticateSession` checks is stored at sign-in.
Sanctum itself stores it only on a session's *second* request, so a session that
signed in and never came back would have survived a password change. And because
this device continues under a new session id, a copy of its cookie -- what someone
who stole it holds -- dies with the old password too.

---

## 6 · Every signed-in device, and ending any of them

"One device only", read literally, signs the HR officer's payroll screen out the
moment they check something on their phone. Chosen instead: see every session,
end any of them.

- `GET /auth/sessions`, `DELETE /auth/sessions/{key}`, `DELETE /auth/sessions` (all others).
- The key is the **SHA-256 of the session id**. The id itself is the cookie's
  content -- a credential -- and never leaves the server.
- A "Signed-in devices" card on the TeamOra, Zerem and Sprintly profile pages.

---

## 7 · Losing access reaches the page that is already open

Refusing the next request was not enough: a page left open went on showing what
its user could no longer see. `AccessLifecycle` now hears every change to what
someone may use -- a role or permission (Spatie's events, switched on for this), a
CRM suspension, the account's status, its deletion -- and:

- **Nothing left** (the launcher's own rule: no `hr.`/`crm.`/`projects.`/`accounting.`/
  `kiosk.` permission, `crm.` not counted while CRM-suspended, `hr_employee` always
  counts) → every session and token ends **on the server**, then `AccessChanged`.
- **Something left** → `AccessChanged` only. The browser re-reads who it is, and
  `RequireModule` walks it out of the system it lost.

### Why it is judged after the request, not on the spot

Every `TeamController` swaps a module's presets by removing one role and then
assigning the next. Judged between the two, a person whose only role was being
swapped looks as if they have nothing left -- and would be signed out for being
promoted. So the judgement is deferred to the end of the request, command or job,
named per person so a burst of changes is judged once, and `always` so it runs even
if the request then failed after the change landed.

### How the page hears it

CRM and Projects hold a socket and listen on `user.{id}`. HRIS and Accounting hold
none, by design; they re-check when a tab comes back to the front -- **not on a
timer**, which would keep an unattended session alive past its idle timeout -- and
are refused on their next request regardless. The broadcast is queued: sessions
and tokens are already gone by then, so a slow Reverb cannot fail a separation.

Kiosk has no `RequireModule`: no role on live carries a `kiosk.*` permission, not
even `super_admin`, so a gate would lock everyone out of it.

---

## 8 · Every token carries its own expiry

The guard used to age tokens by `created_at` against `sanctum.expiration` (30 days)
while `expires_at` sat NULL -- **alongside** `expires_at`, not instead of it, whatever
the stock comment in `config/sanctum.php` says. With browsers on sessions, the
tokens left are machine credentials, for which 30 days is a silent outage a month.

- `sanctum.expiration` is off; each token's own `expires_at` is the only rule.
- Migration `2026_09_10_010000` first writes into every existing row the expiry the
  old rule implied (`created_at + 30 days`) -- nothing dead comes back, nothing alive
  dies early -- and deletes every `web` token.
- A token minted without an expiry gets 30 days. Machine tokens get 90:
  `MachineTokens::issue()`, `n8n:token`, and `tokens:issue {email} {name} --days= --rotate`.
- **The scraper's token (#137) still expires 2026-09-16 under the old rule.** After
  this deploy: `php8.4 artisan tokens:issue scraper-service@crm.local scraper --rotate`,
  then put the new value in `/var/lib/vss-scraper/.env`.

### Found by the tests

Three singletons carry state from one request to the next inside a single test
process -- the auth guard, the session store (whose load *merges* into what it
holds), and `auth.driver`, which is the `Guard::class` the database session driver
asks whose session it is writing. Each is reset for every simulated browser
request. Under PHP-FPM every request starts clean; this is a test concern only.

---

## Deploying this branch

Two deploys are recorded here. The first (§1–§3) had no migrations and is live.
**The second (§4–§8) has one, and changes how everyone signs in.**

**Before:** back up the database, `personal_access_tokens` included, and tell staff
that **everyone will be signed out once** and simply signs back in.

1. `.env` — **delete `SESSION_LIFETIME=120`** (the default is now two days; left in,
   the idle timeout is two hours) and **add `SESSION_SECURE_COOKIE=true`**. Leave
   `CORS_ALLOWED_ORIGINS` unset in production.
2. `git pull` → **`npm run build` first** → `php8.4 artisan migrate --force` (token
   expiry; deletes the `web` tokens) → `optimize:clear` → `permission:cache-reset` →
   reload `php8.4-fpm` → restart `vss-queue` and `vss-reverb` (`AccessChanged` is
   queued and broadcast through them) → `pm2 restart`.

   **Build before migrating.** The migration deletes the tokens the old frontend is
   still sending, and the old frontend cannot sign anyone in against the new
   backend. Built first, the new frontend replaces it seconds after the migration
   instead of minutes.
3. Re-mint the scraper's token (§8) and put it in `/var/lib/vss-scraper/.env`.
4. Check from outside: `/api/sanctum/csrf-cookie` answers 204 and sets `XSRF-TOKEN`;
   a sign-in without an `Origin` is 403; a real sign-in sets an `HttpOnly; Secure;
   SameSite=Lax` cookie and returns no token; `/broadcasting/auth` without the
   `X-XSRF-TOKEN` header is 419.

**Rollback** is the previous commit and a rebuild, and is safe: the old frontend
finds no token, its users sign in once more and get new ones. The migration is not
undone -- deleted browser tokens stay deleted, and the explicit `expires_at` it wrote
is honoured by the old code too.

**Local development** needs `CORS_ALLOWED_ORIGINS=http://localhost:3000` in the
backend `.env` (now in `.env.example`); without it the Next dev server's cookie never
reaches `:8000`.

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
