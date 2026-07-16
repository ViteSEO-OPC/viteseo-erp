# RC3 Outreach Automation Pack — n8n Workflow Runbook

Deterministic business automation on the **frozen** CRM↔n8n boundary. **Laravel decides; n8n orchestrates.**
n8n never sends email directly (all outbound → `OutreachEmailService → EmailProvider`), never owns a schedule
(all timing → Planner), never holds business state, and never evaluates reply status / template selection /
lead visibility. No AI. See KB `n8n-Integration-Boundary` and `RC3-Scheduled-Outbound-Email`.

## Wiring (once, by Ops)
1. **CRM → n8n:** set `N8N_ENABLED=true`, `N8N_WEBHOOK_URL=<n8n ingress>`, `N8N_WEBHOOK_SECRET=<shared secret>`.
   The CRM signs every webhook `X-CRM-Signature: sha256=<hex>` (HMAC-SHA256 of the raw body) with an envelope
   `{event_id, topic, schema_version, occurred_at, data}`.
2. **n8n → CRM:** `php artisan n8n:token` → store the bearer token in an n8n **HTTP Header Auth** credential
   named **"CRM Service Token"** (`Authorization: Bearer <token>`). Set n8n env
   `CRM_API_URL=https://system.viteseo.ph/crm` (confirmed target 2026-07-16 — host `$env`; see
   `DEPLOYMENT-TARGET.md`) and `N8N_WEBHOOK_SECRET` (same secret as the CRM). Link the credential on
   each workflow's HTTP node on import.
3. **Single ingress:** the CRM posts **all topics to one `N8N_WEBHOOK_URL`.** Each workflow file here has its own
   webhook path for isolated import/testing; for production, front them with a single ingress that routes by
   `topic`, OR point `N8N_WEBHOOK_URL` at the relevant path per environment. Keep the webhook **"Raw Body"**
   option ON — HMAC is verified over the exact raw bytes (JSON fallback only if raw is unavailable).
4. **Import inactive.** Activate deliberately, one at a time, after the checklist below passes.

## Developer Test Mode (validation safety — ON now)
`OUTREACH_TEST_MODE=true` redirects **every automated send** to `OUTREACH_TEST_RECIPIENT`
(`bruce.andrada@viteseo.ph`); the intended recipient is preserved in an append-only `email_events`
(`type=automation.dispatched`) metadata row + logged. Set `OUTREACH_TEST_MODE=false` to restore normal
delivery — **no code change.** Routing only; never business state.

## Common contract
- **Retry strategy:** CRM delivery job retries `tries=5, backoff=30s`; each n8n HTTP node retries
  `maxTries=5, wait=5s`; n8n dedupes on `event_id` (workflow static data) — at-least-once is safe.
- **Failure strategy:** invalid signature → workflow throws (rejected, logged in n8n Executions). HTTP 4xx/5xx →
  node retries then the execution is marked failed (`saveDataErrorExecution=all`) for inspection/replay. The CRM
  remains the source of truth; a failed n8n run never corrupts CRM state.
- **Auth:** all CRM calls use the scoped **CRM Service Token** (`outreach.automation.send` for the automation
  send endpoint; `calendar.create` for Planner). No ownership bypass beyond the one narrow automation ability.

---

## 1 — Proposal Follow-up  (`01-proposal-follow-up.json`)
- **Trigger topic:** `proposal.sent` (fired when an outbound email with `purpose=proposal` is sent).
- **Payload `data`:** `{ email_id, lead_id, assigned_to, purpose, subject, sent_at }`.
- **CRM endpoint:** `POST /api/planner-events` — creates a **Planner follow-up** (`event_type=follow-up`,
  `starts_at=now+3d`, `reminder_minutes=60`). **Planner owns the wait** (no n8n timer). The follow-up email is
  sent later by workflow #3 when the reminder fires (`require_unreplied=true`, so the CRM skips it if the lead
  has replied).
- **Retry/Failure:** common contract. **Verify:** send a `purpose=proposal` automation email → confirm a
  follow-up Planner event appears (`starts_at` +3d, source `outreach`); confirm no n8n Wait/timer is used.

## 2 — Introduction Email  (`02-introduction-email.json`)
- **Trigger topic:** `lead.qualified`.
- **Payload `data`:** `{ lead_id, business_name, assigned_to, qualified_at }`.
- **CRM endpoints:** `POST /api/planner-events` (Planner **email task**) → then
  `POST /api/automation/leads/{lead_id}/emails` `{purpose:'introduction', workflow:'introduction'}`.
- **Verify:** qualify a prospect → confirm an `email` Planner task is created and an introduction email is sent
  (to the Test-Mode recipient during validation), attributed to the lead's BD.

## 3 — Meeting Reminder + Proposal Follow-up send  (`03-meeting-reminder.json`)
- **Trigger topic:** `reminder.due` (already live in the CRM).
- **Payload `data`:** `{ planner_event_id, title, event_type, starts_at, assigned_to, lead_id }`.
- **Routing (in the Verify node):** `event_type=meeting` → send `purpose=meeting_reminder`;
  `event_type=follow-up` → send `purpose=proposal_follow_up` with `require_unreplied=true` (the send-half of #1);
  otherwise no-op. Requires `lead_id`.
- **CRM endpoint:** `POST /api/automation/leads/{lead_id}/emails`. The CRM evaluates reply state server-side.
- **Verify:** due a meeting reminder → reminder email sent; due a proposal follow-up on an **unreplied** lead →
  follow-up sent; on a **replied** lead → CRM returns `skipped: lead_already_replied` (no send).

## 4 — No-Response Escalation  (`04-no-response-escalation.json`)
- **Trigger topic:** `follow-up.completed`.
- **Payload `data`:** `{ planner_event_id, lead_id, assigned_to, completed_at }`.
- **Behaviour:** **the escalation is authoritative server-side** (`EscalateUnansweredFollowUp`): a follow-up
  completed **without a reply** creates a Planner escalation **task** for the assigned BD, and the existing
  reminder pipeline notifies them — never mutating `leads.status`. This workflow is the **observability** hook
  (n8n evaluates nothing); extend the No-Op node to forward to an ops log if desired.
- **Verify:** complete a follow-up on an unreplied lead → a `source=system` Planner task appears for the BD +
  a reminder notification; complete one on a replied lead → **no** escalation task.

## 5 — Thank You Email  (`05-thank-you-email.json`)
- **Trigger topic:** `deal.won`.
- **Payload `data`:** `{ lead_id, deal_id, amount, assigned_to, won_at }`.
- **CRM endpoint:** `POST /api/automation/leads/{lead_id}/emails` `{purpose:'thank_you', workflow:'thank_you'}`
  (the CRM selects + renders the `thank_you` template via the Template Engine).
- **Verify:** mark a lead Won → thank-you email sent (to the Test-Mode recipient during validation); Revenue /
  Conversion unchanged (they source from the Deal record).

---

## Global verification checklist (before activating in production)
- [ ] `N8N_ENABLED=true`, `N8N_WEBHOOK_URL`/`N8N_WEBHOOK_SECRET` set; token issued via `php artisan n8n:token`.
- [ ] "CRM Service Token" credential + `CRM_API_URL` + `N8N_WEBHOOK_SECRET` set in n8n; webhook "Raw Body" ON.
- [ ] HMAC verify rejects a tampered body; a valid signed body passes; duplicate `event_id` is deduped.
- [ ] Each flow produces the expected CRM effect (Planner event / automation send / server-side escalation).
- [ ] `OUTREACH_TEST_MODE=true` during validation → every automated email lands on the test recipient; the
      `email_events` metadata row shows the true intended recipient. Flip to `false` for go-live.
- [ ] No workflow uses an n8n timer/Wait for scheduling; no workflow sends email or mutates `leads.status`.
