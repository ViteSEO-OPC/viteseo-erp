# RC3 Outreach Automation Pack — Pre-Import Review & Import Checklist

Reviewed 2026-07-15. **Import INACTIVE. Activate nothing during import.** Validate with Test Mode ON, then
disable Test Mode and approve activation.

## 1. Workflow JSON review — PASS
- 5 files, all valid JSON, `active: false`, `settings.saveDataErrorExecution: all`.
- Each: `CRM Webhook` (Raw Body ON) → `Verify` Code node (HMAC-SHA256 over raw body vs `X-CRM-Signature`,
  `timingSafeEqual`; dedupe on `event_id`; **topic guard** — ignores foreign topics) → action node(s).
- No AI, no Wait/timer (scheduling via Planner), no direct-send/SMTP nodes, no `leads.status` writes.

## 2. HTTP endpoints vs implemented Laravel routes — PASS
| Workflow | Calls | Implemented route | Auth ability |
|---|---|---|---|
| 1 Proposal Follow-up | `POST /api/planner-events` | ✅ `PlannerEventController@store` | `calendar.create` |
| 2 Introduction | `POST /api/planner-events` → `POST /api/automation/leads/{id}/emails` | ✅ / ✅ | `calendar.create` / `outreach.automation.send` |
| 3 Meeting Reminder | `POST /api/automation/leads/{id}/emails` | ✅ `AutomationEmailController@store` | `outreach.automation.send` |
| 4 No-Response Escalation | (none — CRM escalates server-side) | n/a | n/a |
| 5 Thank You | `POST /api/automation/leads/{id}/emails` | ✅ | `outreach.automation.send` |

## 3. Environment variables — PASS
**CRM side** (`config/n8n.php`, `config/automation.php` ← `.env`):
- `N8N_ENABLED` (now `false` — set `true` at activation) · `N8N_WEBHOOK_URL` (empty — set to the n8n ingress) ·
  `N8N_WEBHOOK_SECRET` (empty — set the shared secret) · `N8N_WEBHOOK_TIMEOUT=10`.
- `OUTREACH_TEST_MODE=true` · `OUTREACH_TEST_RECIPIENT=bruce.andrada@viteseo.ph` (verified live).
- `AUTOMATION_ESCALATION_START_OFFSET=1` · `AUTOMATION_ESCALATION_REMINDER_MINUTES=1`.

**n8n side** (referenced by the workflows):
- `CRM_API_URL` — CRM base URL (workflows call `{{$env.CRM_API_URL}}/api/...`). **Confirmed target
  (2026-07-16): `https://system.viteseo.ph/crm`** — set as an `$env` var on the n8n host (see
  `DEPLOYMENT-TARGET.md`).
- `N8N_WEBHOOK_SECRET` — **same value** as the CRM (HMAC verification).

## 4. Test Mode configuration — PASS
`config('automation.test_mode.enabled') = true`, recipient `bruce.andrada@viteseo.ph`. Every automated send is
redirected; the intended recipient is preserved in an append-only `email_events` (`type=automation.dispatched`)
row + `Log::info('automation.email.dispatched', …)`. Business state is never altered. Disable = set
`OUTREACH_TEST_MODE=false` (no code change).

## 5. Credential mapping — PASS
- n8n **HTTP Header Auth** credential named exactly **`CRM Service Token`** → `Authorization: Bearer <token>`
  from `php artisan n8n:token` (abilities: `follow-ups.*`, `calendar.*`, `outreach.automation.send`).
- Every HTTP node references `credentials.httpHeaderAuth.name = "CRM Service Token"`; the `id` placeholder
  (`REPLACE_WITH_CRM_SERVICE_TOKEN_CREDENTIAL_ID`) is linked to the real credential **on import** (no secret in JSON).

## 6. Single-ingress wiring (decision required)
The CRM posts **all topics to one `N8N_WEBHOOK_URL`**. Each workflow has a distinct webhook path AND a topic
guard, so a workflow safely ignores foreign topics. Two supported options:
- **Per-topic validation (simplest):** point `N8N_WEBHOOK_URL` at ONE workflow's path and exercise only that
  topic. The guard ensures no cross-handling.
- **Single ingress (production):** front the five with one dispatcher that routes by `topic` (not built — flag
  for Ops; can be added). Until then, validate per-topic.

## 7. Rollback procedure
Automation is **off by default and fully reversible without code changes or data loss**:
- **Instant disable (CRM → n8n):** set `N8N_ENABLED=false` (or clear `N8N_WEBHOOK_URL`) → the emitter no-ops;
  no webhooks are sent. Domain events still fire internally with zero external effect.
- **Instant disable (n8n → CRM):** deactivate the workflows in n8n, and/or `php artisan n8n:token --rotate`
  (revokes the service token → all automation calls 401).
- **Restore normal delivery:** `OUTREACH_TEST_MODE=false`.
- **Full removal:** delete the imported workflows in n8n. CRM-side additions are additive and inert when
  disabled; if ever reverting code, the only schema change is the nullable `emails.purpose` column
  (migration `2026_07_16_040000` has a `down()` that drops it). No frozen surface was modified.
- **No business-state cleanup needed:** automation never mutates `leads.status`; escalation tasks are ordinary
  Planner events a BD can complete/delete.

## 8. Import checklist (execute in order; activate nothing)
- [ ] Confirm `OUTREACH_TEST_MODE=true` + recipient on the CRM target before enabling n8n.
- [ ] `php artisan n8n:token` on the CRM target → copy the bearer token.
- [ ] In n8n: create HTTP Header Auth credential **`CRM Service Token`** (`Authorization: Bearer <token>`).
- [ ] In n8n: set env `CRM_API_URL=https://system.viteseo.ph/crm` (host `$env`) and `N8N_WEBHOOK_SECRET` (matches the CRM secret).
- [ ] Import `01`–`05` **inactive**; on each HTTP node, select the `CRM Service Token` credential; confirm the
      `CRM Webhook` node "Raw Body" option is ON.
- [ ] On the CRM: set `N8N_ENABLED=true`, `N8N_WEBHOOK_URL` (chosen ingress), `N8N_WEBHOOK_SECRET`; `config:clear`.
- [ ] Sanity: send a signed test event → HMAC verify passes; a tampered body is rejected; duplicate `event_id`
      deduped.
- [ ] Per-workflow E2E (Test Mode ON): proposal send → follow-up scheduled; qualify → intro sent; meeting/
      follow-up reminder due → email sent (replied lead → skipped); Won → thank-you sent; follow-up completed
      w/o reply → escalation task + BD notified. Confirm every email lands on `bruce.andrada@viteseo.ph` and the
      `email_events` metadata shows the true intended recipient.
- [ ] Only after sign-off: `OUTREACH_TEST_MODE=false` → re-verify one flow → approve production activation.
