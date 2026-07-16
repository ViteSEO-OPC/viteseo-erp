# Pod Sync Report — July 2026

**Branch:** `pod-sync-2026-07` in `viteseo-platform2.0` (safe copy — the original `viteseo-platform` is untouched)
**Method:** per-file 3-way merge (`git merge-file`) using the old snapshots (`hris/`, `PMS/`, `CRM/`) as base, `pod-latest/*` as theirs, and the platform as ours. One commit per module so any step can be rolled back independently.

| Commit | Module | Files | Scope |
|---|---|---|---|
| `0c0f489` | HRIS → Teamora | 79 | +3793 / −788 |
| `f2a7451` | PMS → Sprintly | 83 | +1263 / −169 |
| `571866f` | CRM → Zerem | 244 | +10078 / −1344 |

Change checklists used for the merge: `hris-changes.md`, `pms-changes.md`, `crm-changes.md` (this folder).

---

## HRIS (Teamora)

Simplest merge — paths map 1:1 onto `backend/app/Modules/Hr` and `frontend/src/{app/teamora, modules/hr}`.

**Brought in:** leave backdating (new migration + LeaveService/LeaveTypeController logic), payroll-inquiry resolution attachments (migration + PayrollService), new frontend components (`status-filter`, `rejection-reason-dialog`, `attendance-summary-tiles`, `leave-type-legend`), reworked time-off / requests / attendance pages, ERD + audit docs into `docs/`.

**Conflicts & resolutions:**
- `Modules/Hr/Services/NotificationService.php` — platform had widened the role check to `hasAnyRole(['hr_officer','super_admin'])`; pod added pagination params to `forHr()`. **Combined both.** All notification `href`s re-pointed to `/teamora/...`.
- `frontend/src/core/nav.ts` — pod reordered the employee nav. **Took pod's order**, kept platform's `/teamora/` prefixes.
- `employee-overview.tsx`, `time-off/page.tsx` — pod deleted the "View all" links. **Took the deletions.**
- All employee pages under pod's `app/(employee)/*` remapped to `app/teamora/*` per the existing route transform.

## PMS (Sprintly)

**Brought in:** the whole Workload feature (`WorkloadController`, `WorkloadService`, `config/workload.php`, 2 migrations), `ChecksDeadlineDates`, `SafeBroadcast`, `TaskCompleted` event, confetti celebration lib, and pod's full frontend components restructure (flat files split into `dashboard/`, `tasks/`, `team/`, ... folders) with route references rewritten `/projects/...` → `/sprintly/...`.

**Conflicts & resolutions:**
- `backend/app/Models/User.php` (shared) — platform's refactored version kept; applied pod's semantic changes by hand: **`capacity_points` removed** (also from `Projects/Resources/UserResource.php`), **`phone` added** (see CRM).
- `remove_capacity_points_from_users_table` migration originally timestamped `2026_07_14`, which would run *before* the platform migration that adds the column. **Moved to `database/migrations/` and re-timestamped `2026_07_16_000000`** so fresh migrations run in order: create users → add projects fields → add crm fields → remove capacity_points → add phone. Verified statically.
- `Projects/Routes/api.php` — platform's `UserDirectoryController` routes + pod's new `WorkloadController` routes both kept.
- `config/cors.php` — kept platform's `allowed_origins: ['*']` (unified dev environment).
- `composer.json` dev script — took pod's `queue:listen --tries=3`.

## CRM (Zerem) — largest, full transform required

Every backend file went through the established transform: `apps/api/app/X` → `Modules/Crm/X`, namespaces `App\...` → `App\Modules\Crm\...`, tables prefixed `crm_`, roles mapped to `crm_admin` / `super_admin` / `crm_bd`, permissions namespaced `crm.*`. Frontend files mapped `apps/web/src` → `modules/crm` + `app/zerem`, imports rewritten to `@/modules/crm/*`, nav routes to `/zerem/*` (API client paths deliberately left untouched).

**Brought in:**
- **Email templates** (Purpose+Tone): model + controller + `crm_email_templates` migration + `EmailTemplateSeeder` (wired into `DatabaseSeeder`).
- **Send-Later + delivery monitoring**: `crm_email_events`, `crm_reminder_deliveries`, `ScheduledEmailService`, `DeliveryMonitorController`, `SendScheduledEmailsJob` / `SendDueRemindersJob` on the scheduler.
- **Notification Center**: Laravel `notifications` table (intentionally *not* prefixed — framework convention, shared), `NotificationController`, `notifications:prune-read` command, bell + dropdown frontend.
- **n8n integration**: signed webhook emitter + `DispatchN8nWebhookJob`, 7 domain events + 9 listeners wired in `CrmServiceProvider`, `n8n:token` command for the scoped service account, `config/n8n.php` + `config/automation.php`, `n8n/workflows/` (5 RC3 workflows + docs) at repo root.
- **Lead qualification & outcomes**: qualify endpoint (`crm.leads.qualify`), Won/Lost with deal creation (`crm.deals.create`), archive management page replacing lead-archive / recycle-bin / restore-archived-leads.
- **Profile self-service**: `GET`+`PATCH /api/v1/crm/me`, phone on users (root migration `2026_07_16_050000`), BD Settings page.
- **Dark mode**: pod's reference-matched dark ramp ported into the scoped `crm-theme.css`; `dark:` variants across ~90 components.
- Pod's outreach test suite ported to `backend/tests/Feature/Outreach` (webhook URLs updated to `/api/v1/crm/webhooks/gmail`).

**Conflicts & resolutions (key ones):**
- `Modules/Crm/Routes/api.php` — rebuilt: pod's login/logout/register routes **dropped** (platform shared auth owns those), `GET/PATCH /me` integrated under `auth:sanctum`, everything inside the platform's `v1/crm` prefix, permissions `crm.*`, role middleware `super_admin|crm_admin`.
- `auth-provider.tsx` — kept the platform's bridge to shared auth; added `updateUser` passthrough (new method on `core/auth-context.tsx`) so profile saves update the topbar immediately.
- `use-dashboard-data.ts` — accepted pod's removal of the Copilot AI summary (their comment explains why).
- Sidebar nav-item styling — kept platform's unified theme; pod's dark-mode styling arrived instead via the theme CSS port.
- `RolesAndPermissionsSeeder` — new `crm.*` permissions added; `crm.outreach.automation.send` is automation-only (granted to no human role, only to the n8n service-account token).
- One tooling bug caught and fixed mid-merge: the first transform pass wrongly prefixed **API** paths with `/zerem/` (e.g. `apiClient.delete('/zerem/leads/…')`). The CRM frontend merge was reverted, the transform made context-aware (only `href`/`router.push`/`pathname` strings), and re-run.

## Skipped / deferred (deliberate)

- **Pod login/register pages & auth endpoints** (all three pods) — the platform's shared login is the single entry point. Pod dark-mode tweaks to their own login pages not ported.
- **`larastan`, `pestphp/pest`, `puppeteer-core`, `@tanstack/react-query-devtools`** — pod-side dev tooling not used by any merged code; not added to the platform manifests.
- **`laravel/reverb` / `pusher-php-server`** — already absent from the platform by design; the merged `SafeBroadcast` wrapper degrades gracefully (`.env.example` ships `BROADCAST_CONNECTION=log`).

## Dependencies

No new runtime packages were required — everything the merged code imports already exists in the platform's `composer.json` / `package.json` (e.g. `tw-animate-css` was already present). Frontend `node_modules` installed fresh in 2.0.

## Verification

| Check | Result |
|---|---|
| `npm run build` (Next.js production build) | **Pass** — all 80+ routes compile, incl. new `/zerem/profile`, `/zerem/settings/archive` |
| Production server smoke test (`next start`, curl) | **Pass** — `/`, `/login`, `/teamora`, `/sprintly`, `/zerem/dashboard`, `/zerem/settings/archive`, `/teamora/time-off` all HTTP 200 |
| Static import sweep | **Pass** — every `use App\...` import in `app/`, `database/`, `tests/` resolves to an existing file |
| Table-prefix consistency | **Pass** — all 13 CRM migrations create `crm_*` tables (+ shared `notifications`); every CRM model's `$table` matches |
| Migration ordering (users table) | **Pass** — verified statically (see PMS section) |
| Merge-marker / stale-namespace sweep | **Pass** — no conflict markers, no `App\Http\...` leftovers, no un-mapped `@/features/...` imports |

**Not run on this machine (no PHP/Composer/Docker installed):** `composer install`, `php artisan migrate` against a test DB, `php artisan route:list`, and the ported Pest/PHPUnit outreach tests. These should be the first thing run on a machine with PHP 8.3+:

```bash
cd viteseo-platform2.0/backend
composer install
php artisan migrate:fresh --seed --env=testing   # fresh test DB, NOT viteseo_platform
php artisan route:list | grep v1/crm
php artisan test tests/Feature/Outreach
```

## Rollback

Each module is one commit on `pod-sync-2026-07`; `git revert <hash>` undoes a single module. The original `viteseo-platform/` and both snapshot sets (`hris/`, `PMS/`, `CRM/`, `pod-latest/`) are untouched.
