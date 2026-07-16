# n8n Workflows — deployment artifacts (version-controlled)

Exported **n8n workflow JSON** lives here and is committed as a **deployment artifact** (frozen decision,
PO 2026-07-15). This keeps the orchestration layer reproducible and reviewable alongside the CRM code,
while the CRM remains the single source of truth (n8n is orchestration only).

## Rules
- **Commit exported workflows** (`*.json`) here, one file per workflow, named for its purpose
  (e.g. `email-followup-sequence.json`, `reminder-escalation.json`).
- **Never commit secrets.** Export using n8n's credential *references* (n8n credential store), not inline
  secrets. Scrub any tokens/URLs/HMAC secrets before committing. The CRM↔n8n secrets live in env
  (`N8N_*`), never in workflow JSON.
- Treat changes like code: PR review, meaningful commit messages.

## The CRM ↔ n8n boundary (summary — see KB `n8n-Integration-Boundary`)
- **CRM → n8n:** HMAC-signed webhooks over domain events (`email.sent`, `reminder.due`, …). Verify the
  `X-CRM-Signature: sha256=<hex>` header (HMAC-SHA256 of the raw body with `N8N_WEBHOOK_SECRET`) and dedupe
  on the envelope `event_id`.
- **n8n → CRM:** call the existing CRM REST endpoints with the scoped Sanctum **service-account token**
  (`php artisan n8n:token`). n8n **never** sends email directly (all outbound stays on
  `OutreachEmailService → EmailProvider`) and **never** owns a schedule (schedule through Planner).

## Provisioning
```bash
php artisan n8n:token            # issue the scoped service token (store in n8n credentials)
php artisan n8n:token --rotate   # rotate (revoke old tokens first)
```
Enable outbound webhooks with `N8N_ENABLED=true`, `N8N_WEBHOOK_URL=…`, `N8N_WEBHOOK_SECRET=…` (see
`.env.stub` / `n8n-Integration-Boundary`).
