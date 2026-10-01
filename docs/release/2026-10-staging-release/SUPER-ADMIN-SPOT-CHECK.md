# Super-admin / org-selection spot-check (blocker D)

## What changed and why it matters
Commit `45bc5e50` (on staging, not in production `f70ae1aa`) moved `SetCurrentOrg` **before** route-model binding
(`SubstituteBindings`) in the middleware priority list (`backend/bootstrap/app.php`). Every `{lead}`,
`{conversation}`, `{discoveryRun}`, `{payslip}`, `{project}`, `{invoice}`… is now looked up in the org pinned for the
request. Before, the NERBY service user (no org membership) got 404 on every lead-bound callback; humans resolved the
same org on demand, so their behaviour does not change.

How the org is chosen (unchanged by the release):
- a person: their single membership (`org_user.user_id` is unique — one org per account; there is no org switcher,
  no `X-Org` header);
- a **super_admin with no membership** (all three in production): not pinned → the **provider org** (lowest org id,
  ViteSEO) for tenant routes; the platform admin pages (`/admin/orgs/{org}`…) bind the unscoped `Org` and reach every org;
- a service token: exactly the org in its `org:<id>` ability.

## Automated coverage (run in CI/sqlite; no production data)
`backend/tests/Feature/Platform/TheTenantIsPinnedBeforeBindingTest.php` (11 tests) — passes; when the ordering is
reverted, the order-sensitive tests FAIL (mutation-verified):
structure on CRM/HR/Accounting bound routes · crm_admin own lead 200 / foreign 404 with a stale foreign pin ·
crm_bd same · conversations · discovery runs · settings (niches: own binds, foreign 404 and unchanged) · service token
binds in its own org (stale pin, and nothing pinned — the exact production failure 45bc5e50 fixed) · orgless super_admin: provider
lead 200, foreign lead 404 on CRM routes, `admin/orgs/{other}/features` 200, crm_admin on that admin route 403 ·
orgless ordinary user refused · token bound to a nonexistent org → 404.
Plus existing cross-org suites (NearbyCrossOrgIsolation, Conversation*, LeadUnassign, EmailTemplateManagement,
NerbyServiceIdentitySecurity…).

## Manual spot-check (10 minutes, on DEMO before release and on PRODUCTION right after deploy)
Use your own super_admin login on the site being checked — never someone else's credentials, never a production
write beyond normal navigation. Record ✔/✘ and the URL.

| # | As | Action | Expected |
|---|---|---|---|
| 1 | super_admin | Log in; open Zerem → Leads | List loads (provider org's leads), no error toast |
| 2 | super_admin | Open any lead detail (`/zerem/leads/<id>`) | Detail loads with notes/activities |
| 3 | super_admin | Open Zerem → Inbox; open conversation 1 and 2 | Thread loads; reply box state unchanged (auto-reply settings untouched) |
| 4 | super_admin | Settings → Email templates; open one | Loads |
| 5 | super_admin | HRIS → Employees; open one employee; Payroll → open run 9 | Loads; amounts unchanged |
| 6 | super_admin | Projects → open a project | Loads |
| 7 | super_admin | Platform admin → Organizations → open the ViteSEO org's features page | Loads (admin surface reaches orgs) |
| 8 | crm_admin (a real admin, with their consent, or the demo QA operator on demo) | Open a lead and a conversation | Loads |
| 9 | crm_bd (demo QA operator on demo) | Open an assigned lead; try a lead id that belongs to another org (demo only) | Own 200; foreign "not found" |
| 10 | any | Browser devtools → no 404/500 on `/api/v1/crm/leads/<id>`, `/conversations/<id>` during the above | None |

Any ✘ on 1–7 after the production deploy = rollback trigger (release runbook §Rollback criteria).
