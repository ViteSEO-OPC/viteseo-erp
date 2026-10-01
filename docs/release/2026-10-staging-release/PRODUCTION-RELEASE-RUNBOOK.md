# Production release runbook — staging → main → production (system.viteseo.ph)

**Status: NOT APPROVED. Production stays on hold until every gate in §0 is green.** Nothing here has been run.
Written 2026-10-01 against: staging `796eda8c` (+ the `release/staging-readiness` merge), main `2c445ea6`,
production release9 @ `f70ae1aa` (detached), DB `teamora` 350 migrations / batch 17.
**Scope update 2026-10-01:** staging moved to `10270d6c` (Copilot Phase 2, §7) before this branch was merged. The
**final staging / main SHA is decided only after the readiness PR merges** — always deploy the reviewed `MAIN_SHA`
from §1.1, never a SHA written in this file. Copilot Phase 2 added no migration, so the 28-migration plan stands.

Companion documents (same folder): [LEAD-DATA-ROLLBACK-RUNBOOK](LEAD-DATA-ROLLBACK-RUNBOOK.md) ·
[HR-PAYROLL-SIGNOFF](HR-PAYROLL-SIGNOFF.md) · [SUPER-ADMIN-SPOT-CHECK](SUPER-ADMIN-SPOT-CHECK.md) ·
[db-backup-restore.sh](db-backup-restore.sh) · [host/vss-discovery.service](host/vss-discovery.service) ·
[host/scraper-token-rotate.sh](host/scraper-token-rotate.sh).

Conventions: run as root on the host. `P=/var/www/vsp-release9; B=$P/backend; F=$P/frontend`.
Artisan ALWAYS as `sudo -u www-data env HOME=/tmp XDG_CONFIG_HOME=/tmp php8.4 artisan …` (alias `A`). Never print `.env`
values or tokens. Never `config:cache` / `route:cache`. Never `migrate:fresh` / `migrate:refresh` / `migrate:reset` /
`migrate:rollback` / `db:wipe` (all refused on production from this release: `DB::prohibitDestructiveCommands`; the
rollback path is the database restore in §5). During the release do not edit
users' roles on the Team/Roles pages (custom-role saves `syncPermissions` and strip hybrid grants — Shandy, Cristina,
direct crm_admin grants). **Never `db:seed`, `migrate --seed`, `migrate:fresh/refresh`,
`RolesAndPermissionsSeeder`, `ProductionSeeder`, or `platform:assign-roles` — not in this release, not "to fix
permissions".** (They now refuse outside local/testing anyway — `App\Support\ReseedGuard` — do not set the
`ALLOW_ROLE_RESEED` / `ALLOW_DEMO_SEED` override.)

```bash
set -o pipefail
P=/var/www/vsp-release9; B=$P/backend; F=$P/frontend; TS=$(date -u +%Y%m%d-%H%M%S); W=/root/backups/ops/vsp/release-$TS
T=$W/db-backup-restore.sh                       # copied out of the release commit in §1.2
A() { (cd $B && sudo -u www-data env HOME=/tmp XDG_CONFIG_HOME=/tmp php8.4 artisan "$@"); }
GSSH='ssh -F /dev/null -i /root/.ssh/zerem_github_deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts -o BatchMode=yes'
REPO=git@github.com:ViteSEO-OPC/viteseo-platform.git
```

## 0. Gates (all must be ✔ before the deploy window)
| Gate | Evidence |
|---|---|
| HR/payroll owner signed **APPROVE** | [HR-PAYROLL-SIGNOFF](HR-PAYROLL-SIGNOFF.md) |
| staging merged to **main** by PR (merge commit), main SHA reviewed = `MAIN_SHA` | GitHub PR |
| Lead-data rollback plan understood; rehearsal passed | LEAD-DATA-ROLLBACK §9 |
| No-seeder rule acknowledged by the person deploying | this page, top |
| Super-admin automated suite green on `MAIN_SHA`; manual spot-check done on demo | SUPER-ADMIN-SPOT-CHECK |
| Decision recorded for each optional activation (discovery worker, scraper cutover, enrichment) — default: **not today** | §2.6 |
| Maintenance window announced (≈20 min; inbox/webhooks paused) | |
| Copilot AI ships **OFF** (§7). Enabling it later needs its own approval incl. the processor/privacy decision | §7 |

## 1. Pre-deploy (read-only except the backup dirs)
1. **SHAs** — fetch with the read-only deploy key and pin the exact commit:
   ```bash
   cd $P && GIT_SSH_COMMAND="$GSSH" git fetch --no-tags $REPO +refs/heads/main:refs/remotes/origin/main +refs/heads/staging:refs/remotes/origin/staging
   MAIN_SHA=$(git rev-parse origin/main); echo $MAIN_SHA            # must equal the reviewed SHA in §0
   git merge-base --is-ancestor 796eda8c $MAIN_SHA && echo "staging content included"
   git rev-parse HEAD                                                # f70ae1aa… (current production)
   ```
2. **Rollback refs + tool**: `mkdir -p $W && chmod 700 $W; git -C $P rev-parse HEAD > $W/ROLLBACK_SHA`
   `git -C $P show $MAIN_SHA:docs/release/2026-10-staging-release/db-backup-restore.sh > $T && chmod 700 $T && bash -n $T`
   (the current production checkout does not contain it).
   `wc -l < $B/storage/logs/laravel.log > $W/log-lines-before` (to count only NEW errors afterwards).
   `git -C $P tag -f rollback/pre-release-$TS f70ae1aa75fc7a9630d03b21e4c7c119d1a8da3f` (local tag, not pushed).
   `cp -a $F/.next $W/next-build` · `install -m 600 $B/.env $W/backend.env` · `cp -a $F/.env.production $W/`.
3. **Working tree**: `git -C $P -c core.fileMode=false status --porcelain` → only `?? scraper/logs`. Anything else: STOP.
4. **Lockfiles**: `git -C $P diff --stat f70ae1aa $MAIN_SHA -- backend/composer.lock frontend/package-lock.json` —
   expected changes: `twilio/sdk` (composer), `@twilio/voice-sdk`, `@tanstack/react-virtual` (npm). Anything else: review.
5. **Config / env**: no new key is REQUIRED; every new feature defaults OFF/manual:
   `DISCOVERY_WORKER_ENABLED` / `DISCOVERY_ENRICHMENT_ENABLED` (off), `CALL_PROVIDER` (manual), `COPILOT_AI_ENABLED`
   (off), `GOTENBERG_URL` (unset = download-only). **Do not add any of them in this release** unless §2.6 says so.
   **Copilot:** `COPILOT_AI_ENABLED` must stay **absent** (it is today). Only `true`/`1` enable it, but beware: Laravel
   reads `off`, `no`, `disabled` as **true** — never write those; to switch it off delete the line (or write `false`).
   Frontend: no new required key. `NEXT_PUBLIC_GOOGLE_MAPS_API_KEY` is optional (unset = the Nearby board draws the
   OpenStreetMap map — a supported state); `frontend/.env.production` stays as is.
   Confirm: `grep -cE '^(DISCOVERY_|NERBY_|COPILOT_|CALL_PROVIDER=|CALLING_|TWILIO_|GOTENBERG_|OFFICE_PREVIEW_)' $B/.env` → `0`;
   `grep -cE '^(ALLOW_|RESEED_)' $B/.env` → `0` (a left-over opt-in would authorize a reseed);
   `ls $B/bootstrap/cache/` → only `packages.php services.php` (no `config.php`, no `routes-*.php`);
   `grep -c '^QUEUE_CONNECTION=database$' $B/.env` → `1`;
   `mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='teamora' AND engine<>'InnoDB'"` → `0`.
   V1 state to preserve: `META_OUTBOUND_ENABLED`, `AI_DRAFTS_ENABLED`, `AI_AUTO_REPLY_ENABLED` (+ allowlist 1,2, actor 6)
   — record `grep -cE '^(META_OUTBOUND_ENABLED|AI_DRAFTS_ENABLED|AI_AUTO_REPLY_ENABLED|AI_AUTO_REPLY_CONVERSATION_IDS|AI_AUTO_REPLY_ACTOR_USER_ID)=' $B/.env` (expect 5).
6. **Permissions**: this release needs **no** grant. (The staging seeder also lists `hr.intern_allowance.*`; no code
   checks them. `kiosk.*` / `crm.conversations.ingest` are already absent in production today and unused there.)
   If a role gap ever appears: grant that ONE permission (`Permission::findOrCreate` + `givePermissionTo`) after a
   backup — never reseed.
7. **Migrations preview** (read-only): after checkout in §2 step 3, `A migrate:status --pending` must list exactly the
   28 migrations of LEAD-DATA-ROLLBACK §1, and `A migrate --pretend --force > $W/pretend.sql` must contain no
   `DROP TABLE` of an existing table and no seeder.
8. **Phase A host state** (must already be true): no `vss-discovery` unit; scraper still on token #193 (user 21);
   `/root/scripts/scraper-token-rotate.sh` is the OLD script; `readlink -f /var/lib/vss-scraper/current` →
   `/var/www/vsp-release9/scraper` (in-place deploy: same dir, so `scraper-postdeploy.sh` is NOT needed —
   `scraper/requirements.txt` is unchanged since f70ae1aa and the `.venv` stays).
9. **Scraper nightly decision** (§4): cut over in this window (§2.6b) or PAUSE the 18:30 cron line before §2.

## 2. Deploy
1. **Freeze writes**: `A down --retry=60` · `systemctl stop vss-scheduler.timer vss-queue`.
   (Next.js stays up and shows API errors; Meta webhooks get 503 and are retried by Meta.)
2. **Database backup + verify** (LEAD-DATA-ROLLBACK §5–6):
   `bash $T backup $W/db && bash $T verify $W/db teamora_release_verify && bash $T drop-scratch teamora_release_verify`
   → must print `VERIFY OK … identical (rows + checksums)` (exit 0). **No verified backup → STOP** (`A up`, start services).
3. **Checkout (deterministic)**: `cd $P && git -c core.fileMode=false checkout --detach $MAIN_SHA && git rev-parse HEAD`.
4. **Backend deps**: `cd $B && php8.4 $(command -v composer) install --no-dev --optimize-autoloader --no-interaction`
   (as root, like the existing `vendor/`; composer hooks only run `package:discover` — no seeding).
5. **Migrations**: re-check §1.7, then `A migrate --force 2>&1 | tee $W/migrate.log; echo "rc=${PIPESTATUS[0]}"` — **no `--seed`**
   (rc must be 0).
   Expect 28 DONE, then `mysql -N -e "SELECT COUNT(*), MAX(batch) FROM teamora.migrations"` → `378 18`.
   Any failure → LEAD-DATA-ROLLBACK §11b.
6. **Optional activations — only those approved in §0, in this order, each with its own check; default is to skip all:**
   a. *Discovery worker* — RUNBOOK of Phase A: set `DISCOVERY_PYTHON_BIN=$P/scraper/.venv/bin/python`,
      `DISCOVERY_SCRAPER_PATH=$P/scraper`, `DISCOVERY_CRM_BASE_URL=https://system.viteseo.ph/api/v1/crm`,
      `DISCOVERY_ALLOW_REMOTE_COMMIT=true`; prove `sudo -u www-data env HOME=/tmp $P/scraper/.venv/bin/python -m scraper --help`;
      install `host/vss-discovery.service` (set `WorkingDirectory=$B`), `systemctl daemon-reload && systemctl enable --now vss-discovery`;
      only then append `DISCOVERY_WORKER_ENABLED=true` (guarded append: backup `.env` 600, key absent, newline-terminated,
      `chown root:www-data; chmod 640`).
   b. *Scraper identity cutover* — after the app is up (§3), install `host/scraper-token-rotate.sh` over
      `/root/scripts/scraper-token-rotate.sh` (backup first), run it (issues the org-1 NERBY operator token, proves
      authorization + live probe, swaps `CRM_TOKEN`, revokes only older operator tokens), then `--check` → OK / probe 200,
      dry-run `discover-all` (no `--commit`), then retire token #193 and set user 21 inactive (exact SQL in the
      Phase A runbook, escaping verified). The FIRST token issue creates `nerby-service@crm.local` + role
      `nerby_service` — intended; it is not the seeder.
   c. *Enrichment* — LAST, only after a and b: one manual tick
      `cd $B && sudo -u www-data env HOME=/tmp DISCOVERY_ENRICHMENT_ENABLED=true php8.4 artisan crm:enrich-websites --audit=2 --search=0`,
      review, then append `DISCOVERY_ENRICHMENT_ENABLED=true`.
7. **Frontend**: `cd $F && npm ci && npm run build 2>&1 | tail -5` (as root, like the existing `.next`).
8. **Reload / restart**: `systemctl reload php8.4-fpm` · `systemctl restart vss-reverb` (loads the new broadcasting
   code) · `systemctl start vss-queue vss-scheduler.timer` · `pm2 restart vss-web9 --update-env` · `A up`.

## 3. Post-deploy checks (all ✔ or roll back — §5 criteria)
| # | Check | Command / action | Expect |
|---|---|---|---|
| 1 | Code | `git -C $P rev-parse HEAD` | `$MAIN_SHA` |
| 2 | Health | `curl -s -o /dev/null -w '%{http_code}' https://system.viteseo.ph/` ; `…/api/v1/auth/me` | 200 ; 401 |
| 3 | Migrations | `mysql -N -e "SELECT COUNT(*),MAX(batch) FROM teamora.migrations"` ; `A migrate:status --pending` | `378 18` ; none |
| 4 | Lead data | `SELECT id,website,facebook FROM teamora.crm_leads WHERE id IN (616,690,787)` | website NULL, facebook = URL (expected change) |
| 5 | Queue | `systemctl is-active vss-queue`; `SELECT queue,COUNT(*) FROM jobs GROUP BY queue`; `SELECT COUNT(*) FROM failed_jobs WHERE failed_at > '<deploy start>'` | active; no growing backlog; 0 |
| 6 | Seeder not run | `SELECT COUNT(*) FROM teamora.roles` and role/permission counts vs `$W/db/source-after-dump.snapshot` rows for `roles`, `permissions`, `role_has_permissions`, `model_has_roles` | unchanged |
| 7 | Meta inbox | super_admin opens Inbox, conversations 1 and 2 load; manual reply composer enabled | as before |
| 8 | V1 auto-reply state | `grep -cE '^(META_OUTBOUND_ENABLED|AI_DRAFTS_ENABLED|AI_AUTO_REPLY_ENABLED|AI_AUTO_REPLY_CONVERSATION_IDS|AI_AUTO_REPLY_ACTOR_USER_ID)=' $B/.env`; worker start time > `.env` mtime | 5; yes |
| 9 | Live message test (optional, operator-sent) | an internal test DM on Messenger → inbox shows it; auto-reply decision row appears in `crm_auto_replies` | as in V1 proofs |
| 10 | Discovery safety (read-only) | `A config:show discovery.worker_enabled` and `A config:show discovery.enrichment.enabled` (false unless §2.6a/c ran); `SELECT COUNT(*) FROM teamora.users WHERE email='nerby-service@crm.local'` (0 unless §2.6b ran); `systemctl is-enabled vss-discovery` | as decided |
| 11 | Scraper auth | if cut over: `scraper-token-rotate.sh --check` → OK + probe 200; if NOT: the 18:30 cron line is paused (`crontab -l`) | as decided |
| 12 | HR spot-check | open run 9 + 3 payslips (incl. run 5) and compare with pre-deploy; **do not re-run any run** | identical |
| 13 | Super-admin / org | SUPER-ADMIN-SPOT-CHECK rows 1–7, 10 | all ✔ |
| 15 | Copilot OFF | `A config:show copilot.ai.enabled` → `false`; `SELECT COUNT(*) FROM teamora.crm_copilot_settings` → 0 (no row = AI off) ; `SELECT COUNT(*) FROM teamora.crm_copilot_ai_logs` → 0 | off; 0; 0 |
| 16 | Copilot smoke (rule-based only) | super_admin: open the Zerem Copilot panel, ask "how many leads do I have?" and one lead question → deterministic answers, no error; devtools: `GET /api/v1/crm/copilot/config` returns AI unavailable; no `POST /copilot/ask` reaches a provider (logs count stays 0) | as described |
| 14 | Logs | `tail -n +$(( $(cat $W/log-lines-before) + 1 )) $B/storage/logs/laravel.log \| grep -c 'production.ERROR'` | 0 |

**Rollback triggers**: any of #1–#8, #12, #13 ✘; error rate visibly up; data looks wrong to the HR owner; an
auto-reply sent where it should not. Decide within 30 minutes of `A up` while the data delta is still small.

## 4. Scraper nightly — decision required in §0 (MANDATORY either way)
The new code refuses the old scraper token on ingest (403, `EnsureNerbyServiceCaller`) by design. Either do §2.6b in the
same window, or **pause the 18:30 cron line before §2** (`crontab -l > $W/crontab.before; crontab -e`, comment the
`discover-all` line) until the cutover. Letting it run is NOT harmless: no leads are stored, but the engine still makes
its provider calls and advances its rotation cursor (`.discovery-state.json`), so the targets of that night would be
skipped without having been discovered.

## 5. Rollback
Order: freeze → save post-release DB → restore DB → code → build → services → validate. Use the record in `$W`.
1. **Freeze**: `A down --retry=60`; `systemctl stop vss-scheduler.timer vss-queue`; `systemctl disable --now vss-discovery` (if installed).
2. **Save the post-release database FIRST**: `bash $T backup $W/db-post-release` — the restore below drops it, and it
   is the only copy of everything entered after go-live. Do not skip.
3. **Database**: `CONFIRM_RESTORE=teamora bash $T restore $W/db teamora` (≈20 s) → validate per LEAD-DATA-ROLLBACK §8
   (snapshot IDENTICAL, 350/17, app user granted). Data entered after go-live is not in production any more (it is in
   `$W/db-post-release`) — LEAD-DATA-ROLLBACK §10.
4. **Code**: `cd $P && git -c core.fileMode=false checkout --detach $(cat $W/ROLLBACK_SHA)` (f70ae1aa) ·
   `cd $B && php8.4 $(command -v composer) install --no-dev --optimize-autoloader --no-interaction`.
5. **Frontend**: `rm -rf $F/.next && cp -a $W/next-build $F/.next` (no rebuild needed; the restored build runs on the new
   `node_modules` — `next` itself did not change between the two commits, only two added packages the old build
   does not import).
6. **Env**: `install -m 640 -o root -g www-data $W/backend.env $B/.env` (undoes any §2.6 switch).
7. **Tokens**: if §2.6b ran — restore `/var/lib/vss-scraper/.env` from the backup the rotate script printed, restore the
   old `/root/scripts/scraper-token-rotate.sh`; the DB restore already brings back token #193 / user 21 active and
   removes the NERBY account and its tokens.
8. **Services**: `systemctl reload php8.4-fpm`; `systemctl restart vss-reverb`; `systemctl start vss-queue vss-scheduler.timer`;
   `pm2 restart vss-web9 --update-env`; `A up`; re-enable the 18:30 cron line if it was paused.
9. **Validate**: post-deploy checks #2, #5, #7, #8, #12 against the OLD expectations (350/17; auto-reply state 5 keys;
   inbox loads); `git -C $P rev-parse HEAD` = f70ae1aa.
Worker rollback alone (keep the release): delete `DISCOVERY_WORKER_ENABLED` from `.env` → runs refused again;
`systemctl disable --now vss-discovery` (SIGTERM lets the current job finish ≤ 960 s).
Enrichment alone: delete `DISCOVERY_ENRICHMENT_ENABLED` (next tick skipped).

## 7. Copilot (Phase 1 + Phase 2) — in scope, shipped OFF
**New to production as a whole:** production `f70ae1aa` has no Copilot AI backend; this release adds Phase 1 (global
aggregates/knowledge) and Phase 2 (lead-scoped answers, commits `4f7cd82d`, `29f3a00a`, merge `10270d6c`), plus the two
pending migrations `2026_09_29_000000_create_crm_copilot_settings_table` / `…000100_create_crm_copilot_ai_logs_table`
(already among the 28).
- **Off by default, two locks:** env `COPILOT_AI_ENABLED` (absent = off) AND a Super Admin setting row
  `crm_copilot_settings.ai_enabled` (no row = off) AND a usable stored OpenAI/OpenRouter key. With the env lock off,
  nothing reads the setting or the key and no AI call can happen (single gate in `CopilotAiService::answer`). No job,
  listener or schedule entry; no new route/middleware; no new env key.
- **Frontend when off:** the panel uses the existing rule-based answers; the AI path is hidden; no error.
- **Data flow if ever enabled (decision required first):** for an open-ended question about ONE lead the user may see,
  the provider (OpenRouter if the stored NERBY key is `sk-or-…`, else `OPENAI_BASE_URL`, default OpenAI; model from the
  stored setting) receives: the business name (emails/URLs/phone shapes scrubbed, ≤120 chars), status, priority,
  website status, stored score, last-activity date, owner as you/a teammate/unassigned, and follow-up COUNTS; plus the
  question text as typed (not scrubbed). Never sent: contact name, email, phone, address, coordinates, website/social
  links, notes, message/conversation bodies, ids. No Messenger/Instagram content → no Meta/App Review change.
  **Privacy/processor impact:** identifiable lead data to a third-party AI provider; the setting is GLOBAL (all tenant
  orgs). Needs a DPA/processor + privacy-notice decision and an org-scope decision before anyone turns it on.
- **Authorization (tested):** route `role:super_admin|crm_admin|crm_bd`, `throttle:20,1`; lead resolved in the caller's
  org only, active (non-prospect), not deleted, BD = own assigned leads only, plus `LeadPolicy@view`; every refusal is
  the same "not answered" (no existence leak). Audit rows store a question hash, counts, model, timings, outcome — no
  prompt, answer or lead data; 90-day retention.
- **Rollback:** part of the normal DB restore (its tables are in the 28 migrations). To switch AI off at any time:
  delete `COPILOT_AI_ENABLED` from `.env` (PHP-FPM reads it on the next request; no cache) and/or turn the Super Admin
  setting off.
- **Known follow-ups (not blockers while off):** make the env flag strict (`filter_var(..., FILTER_VALIDATE_BOOL)`);
  scrub the question text; count `lead_denied` against the daily cap.

## 6. Afterwards
Record `MAIN_SHA`, timings, check results and the backup path in `/root/docs/vsp/`; update memory notes; keep `$W`
(0700) until the release is accepted; demo instance is NOT part of this release.
