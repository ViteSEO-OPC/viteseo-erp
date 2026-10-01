# Lead-data migration — database-restore rollback runbook

Release: staging `796eda8c` (+ this readiness branch) → main → production. Production today: release9 @ `f70ae1aa`,
DB `teamora`, 350 migrations, max batch 17. Host: MariaDB 10.11 root over the unix socket.
Tool: [`db-backup-restore.sh`](db-backup-restore.sh) (in this folder; rehearsed, see §9). At release time it is copied
out of the release commit to `$W/db-backup-restore.sh` (release runbook §1.2) — production's current checkout does not
contain it. `T=$W/db-backup-restore.sh` below.
All 151 `teamora` tables are InnoDB and the db has no routines/triggers/events/views (checked 2026-10-01), so
`--single-transaction` gives a consistent dump; `backup` re-checks the engines and refuses otherwise.

## 1. Migrations involved

The release applies **28** migrations (all pending on production, all in one new batch 18). Two of them rewrite
EXISTING data in `crm_leads`; the other 26 only add tables/columns.

| Migration | Kind | Effect on existing data |
|---|---|---|
| `Crm/Migrations/2026_09_16_000002_widen_crm_leads_website_column` | schema | `crm_leads.website` VARCHAR(255) → VARCHAR(2048). No row changes on `up`. **`down` NULLs every website longer than 255 chars** before narrowing. |
| `Crm/Migrations/2026_09_17_000002_refile_social_urls_stored_as_lead_websites` | **data** | Moves a Facebook/Instagram URL found in `crm_leads.website` into `crm_leads.facebook` / `instagram` (only if that column was empty) and sets `website = NULL`. If the social column already had a value, `website` is just cleared. `website_status` untouched. |
| 26 others (CRM calls/email designs/discovery/copilot/spend meter; HR OJT/intern allowance/other IDs) | additive | New tables/columns only. Their `down` drops them — and with them any data written after the deploy. |

Full ordered list: `03-pending-migrations.txt` in the rehearsal evidence (§9) — re-generate at deploy with
`migrate:status --pending` and compare (it must be exactly these 28).

## 2. Tables/columns affected
- `crm_leads.website` (type widened; values moved/cleared), `crm_leads.facebook`, `crm_leads.instagram` (filled).
- Schema-only: `crm_calls`, `crm_emails`, `crm_leads` (+`discovery_ai_assisted`), `crm_email_templates`, `hr_candidates`,
  `hr_employees`, `hr_payslips`, `hr_requisitions`; 12 new tables (`crm_call_provider_settings`, `crm_email_*`,
  `crm_discovery_runs`, `crm_discovery_run_targets`, `crm_provider_spend_meter`, `crm_lead_discovery_lanes`,
  `crm_copilot_settings`, `crm_copilot_ai_logs`, `hr_intern_allowance_versions`, …).

## 3. Known semantic data change (measured on a copy of production, 2026-10-01)
**3 leads (ids 616, 690, 787)**: `website` held a Facebook URL; after migration `website = NULL` and `facebook` = that
URL (it was empty before) — evidence `08-leads-changed.txt`. Every original column of every other pre-existing table is
unchanged (NULL-safe per-column CRC comparison of a clean restore vs the migrated copy, all 150 tables — evidence
`07-original-columns-diff.txt`: only `crm_leads` CHANGED). The count can differ at release time if new leads were
discovered meanwhile; the release runbook re-measures it.

## 4. Why `migrate:rollback` is NOT a restore (proven in rehearsal)
- `refile…down()` puts the URL back into `website` but **leaves it in `facebook` too** — rehearsal: after rollback the
  3 leads have `website` restored and `facebook` still set (it was NULL). `crm_leads` ≠ pre-deploy.
- It cannot tell a moved URL from a legitimate one: any lead that had a social URL and no website BEFORE the deploy
  (or got one after) would also get `website` filled by `down()`.
- Rows where the social column was already set had `website` cleared and are **not restored at all**.
- `widen…down()` deletes websites > 255 chars written after the deploy.
- Every additive `down()` drops its table/column with whatever users entered after the deploy (intern allowance
  settings, payslip intern fields, discovery runs, email designs, call settings…).
→ The only rollback is restoring the pre-deploy dump (§7). From this release `migrate:rollback` (and
  fresh/refresh/reset/wipe) is **refused on production** (`DB::prohibitDestructiveCommands`) — the rehearsal above ran it
  only on a throwaway copy to prove why it must not be relied on.

## 5. Required pre-deploy backup (writes stopped first)
Stop writes, then dump (the dump is a consistent read; stopping writes makes the verification exact and sets the RPO):
```bash
# Same variables as the release runbook: W=/root/backups/ops/vsp/release-<TS> (0700), T=$W/db-backup-restore.sh
B=/var/www/vsp-release9/backend
OUT=$W/db                                          # never reuse; the script refuses an existing dir
cd $B && sudo -u www-data env HOME=/tmp php8.4 artisan down --retry=60      # maintenance mode (HTTP 503)
systemctl stop vss-scheduler.timer vss-queue                               # no scheduled/queued writes
# (Meta webhooks during maintenance get 503 and are retried by Meta.)
bash $T backup "$OUT"
```
Keep `$OUT` (0700; files 0600 — contains password/token hashes and encrypted credentials). Do not copy it off-host
unencrypted.

## 6. Backup verification (must pass before any migration)
```bash
bash $T verify "$OUT" teamora_release_verify && bash $T drop-scratch teamora_release_verify
```
GO only if `verify` exits 0 and prints **`VERIFY OK: all N tables identical to the source at dump time (rows + checksums)`**
(`backup` already checked: InnoDB only, `gzip -t`, `-- Dump completed` trailer, live table count = dumped table count,
SHA256SUMS). Any difference → STOP: with writes stopped there must be none. Never drop a scratch db by hand —
`drop-scratch` only accepts `*_verify` / `*_rehearsal` names.

## 7. Restore commands (the rollback)
```bash
cd $B && sudo -u www-data env HOME=/tmp php8.4 artisan down --retry=60
systemctl stop vss-scheduler.timer vss-queue            # and: systemctl disable --now vss-discovery (if installed)
# 1) SAVE THE POST-RELEASE STATE FIRST — the restore drops it, and it is the only copy of anything users entered
#    after go-live (payslips, intern settings, discovery runs…). Never skip this, even under pressure.
bash $T backup "$W/db-post-release"
# 2) Replace the live db with the pre-deploy dump (drop + recreate with the recorded charset/collation + load, ~15-20 s)
CONFIRM_RESTORE=teamora bash $T restore "$OUT" teamora
```
`restore` validates the dump (files, SHA256SUMS, gzip) BEFORE dropping anything, and refuses ANY existing target
unless `CONFIRM_RESTORE` names it. If the load fails midway, re-run the same command (the dump is untouched).
DB-level grants live in `mysql.db` and survive the drop/recreate. Then roll the CODE back (release runbook §Rollback) **before** bringing the
app up — new code against the old schema is not supported.

## 8. Restore validation
```bash
bash $T snapshot teamora $W/after-restore.snapshot
bash $T compare "$OUT/source-after-dump.snapshot" $W/after-restore.snapshot                  # IDENTICAL
mysql -N -e "SELECT COUNT(*), MAX(batch) FROM teamora.migrations"                           # 350 / 17
APPUSER=$(grep -E '^DB_USERNAME=' $B/.env | cut -d= -f2-)                                     # name only, not the password
mysql -N -e "SELECT COUNT(*) FROM mysql.db WHERE db='teamora' AND user='$APPUSER'"           # 1 (app user still granted)
mysql -N -e "SELECT id, website, facebook FROM teamora.crm_leads WHERE id IN (616,690,787)"  # website back, facebook NULL
```

## 9. Throwaway restore rehearsal — DONE 2026-10-01, twice (final run with the hardened tool)
Evidence: `/root/backups/ops/vsp/restore-rehearsal-20261001-011018/` (0700; the earlier run `…-004611` used a first
version of the tool). Throwaway dbs only; the live db was only read (350/17 before and after).
| Step | Result (file) |
|---|---|
| `backup` of live `teamora` | 151 tables, InnoDB only, 350/17, 776,348 bytes, sha256 `7b551230…` (`01-backup-verify.log`) |
| `verify` into `teamora_restore_rehearsal` | load 18 s; **VERIFY OK: all 151 tables identical (rows + checksums)** |
| Guards | `restore` into an existing db without CONFIRM (rehearsal db **and** `teamora_demo`) → refused; `drop-scratch teamora` / `verify … teamora` → refused; injected `SOURCE_DB` → refused (`02-guards.log`); demo untouched |
| `migrate --pretend` / `migrate --force` (staging code, env-overridden to the throwaway db after a `select database()` probe) | 28 pending (`03`), no DROP of an existing table (`04`), 28 DONE → 378 / batch 18 (`05`, `06`) |
| Data change | original columns of the 150 pre-existing tables other than `migrations` (whose rows are expected to change): only `crm_leads` changed (`07`); 3 leads 616/690/787 website→facebook (`08`) |
| Post-release backup of the migrated copy | 163 tables; later verified restorable (VERIFY OK) (`post-release-backup/`) |
| `migrate:rollback --step=28` | 350/17 but `crm_leads` still ≠ pre-deploy; facebook residue on all 3 leads (`09`, `10-after-rollback.diff`, `11`) — §4 proven |
| `restore` with `CONFIRM_RESTORE=<that db>` from the migrated state | 16 s load; `compare` vs pre-deploy snapshot → **IDENTICAL**, 350/17, collation `utf8mb4_unicode_ci` (`12-*`) |
| Cleanup | all scratch dbs dropped with `drop-scratch` |

## 10. RPO / RTO and operational notes
- **RPO = the moment writes were stopped in §5.** Anything users do after the app is back up is NOT in the dump.
  If production must be restored after users have worked on the new release, that work is lost (or must be
  re-entered/exported first — e.g. intern allowance settings, payslips of a run made on the new release).
- **RTO ≈ 5–10 min**: post-release backup (~5 s) + restore (~20 s at 16 MB) + code rollback incl. composer + `.next`
  restore + service start.
- Disk: the dump is <1 MB compressed; the scratch verify db is ~16 MB.
- Old dumps contain secrets → keep 0600, prune per the backups policy, never attach to tickets.

## 11. If the deployment fails
a. **Before migrations** (checkout/composer failed): no DB change. `git -c core.fileMode=false checkout --detach f70ae1aa`,
   **`composer install --no-dev --optimize-autoloader`** (vendor may already hold the new packages), restore `.next` if it
   was touched, `artisan up`, start queue + scheduler. No restore needed.
b. **During migrations** (a migration errored): writes are still stopped. **Restore (§7)** — exact, ~20 s. (A
   `migrate:rollback` is refused on production and would not be exact anyway — §4.)
c. **After migrations, before app validation** (app still down, no user writes): **restore (§7)** + code rollback.
   Zero data loss (RPO = §5).
d. **After the app is live** (users have written): decide with the owner — restoring loses everything since §5.
   Options: (1) roll forward with a fix (preferred if the defect is code-only); (2) export the new rows that matter,
   restore, re-enter (the §7 post-release backup keeps a copy of everything either way); (3) code-only rollback is NOT possible after these migrations without a restore (old code
   against the new schema is unsupported). Never run `migrate:rollback` on a live system as a "quick fix".

## 12. STOP / GO
**GO to migrate** only if all are true: correct main SHA checked out; `migrate:status --pending` = exactly the 28
expected; writes stopped; §5 backup succeeded; §6 verify identical; `--pretend` output reviewed (no seeder, no
unexpected DROP of existing data); rollback refs recorded.
**STOP** if any: pending list differs; dump verify shows a row/checksum diff while writes are stopped; a migration
outside the list; any `db:seed`/`--seed` in the command history of this deploy; disk < 2 GB free; the app user's grants
missing after a restore.
