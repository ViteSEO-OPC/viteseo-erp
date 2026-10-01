# Release readiness — staging → main → production (October 2026)

Production stays on hold until the HR/payroll owner signs off. Everything here is documentation, a rehearsed
backup/restore tool, host files to install AT release time, and the guard/tests that close blockers C and D.

| Blocker | Closed by | Status |
|---|---|---|
| A. HR/payroll owner sign-off | [HR-PAYROLL-SIGNOFF.md](HR-PAYROLL-SIGNOFF.md) | **Waiting for the owner** (human decision) |
| B. Lead-data rollback needs a DB restore | [LEAD-DATA-ROLLBACK-RUNBOOK.md](LEAD-DATA-ROLLBACK-RUNBOOK.md) + [db-backup-restore.sh](db-backup-restore.sh) | Runbook written; restore rehearsed on a throwaway copy (identical) |
| C. RolesAndPermissionsSeeder must never be rerun | `App\Support\ReseedGuard` in `RolesAndPermissionsSeeder`, `DatabaseSeeder`, `platform:assign-roles` (+ live db names protected in every environment); `DB::prohibitDestructiveCommands` on production; runbook rule | Guarded + 7 tests (mutation-verified); nothing runs a seeder automatically |
| D. Super-admin / org-selection order | `tests/Feature/Platform/TheTenantIsPinnedBeforeBindingTest.php` + [SUPER-ADMIN-SPOT-CHECK.md](SUPER-ADMIN-SPOT-CHECK.md) | 11 tests green; ordering regression caught (mutation-verified); manual checklist ready |

The deploy itself: [PRODUCTION-RELEASE-RUNBOOK.md](PRODUCTION-RELEASE-RUNBOOK.md).
Scope note (2026-10-01): staging also gained Copilot Phase 2 (`10270d6c`); it ships OFF — see runbook §7. The final
release SHA is fixed only after the readiness PR merges.
Host files (install only during the release, only if the matching step is approved):
[host/vss-discovery.service](host/vss-discovery.service), [host/scraper-token-rotate.sh](host/scraper-token-rotate.sh).
