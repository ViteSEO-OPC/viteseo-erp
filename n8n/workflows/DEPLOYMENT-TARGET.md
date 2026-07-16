# RC3 Outreach Automation — Confirmed Deployment Target

Confirmed by Ops (devops@viteseo.ph) **2026-07-16**. This pins the CRM base URL the n8n workflows
call back to. It supersedes the `<crm base url>` / "CRM base URL" placeholders in
`RC3-Outreach-Automation-Pack.md` and `IMPORT-CHECKLIST.md`.

## Confirmed CRM base URL

```
CRM_API_URL = https://system.viteseo.ph/crm
```

- This is an **n8n process environment variable** (`$env`), set on the **n8n host/container**
  environment — NOT an n8n UI "Variable" (`$vars`) and NOT hardcoded in any workflow. The five
  workflow HTTP nodes reference it as `={{ $env.CRM_API_URL }}/api/...`, so setting it requires
  editing the n8n deployment environment and restarting n8n (an Ops action; there is no in-app or
  REST way to set `$env`).
- No trailing slash. The workflows append `/api/...` themselves.

## Resolved n8n → CRM callback endpoints (with the value above)

| Workflow | Node | Resolved URL |
|---|---|---|
| 01 Proposal Follow-up | Create Planner follow-up | `https://system.viteseo.ph/crm/api/planner-events` |
| 02 Introduction Email | Create Planner email task | `https://system.viteseo.ph/crm/api/planner-events` |
| 02 Introduction Email | Automation send | `https://system.viteseo.ph/crm/api/automation/leads/{lead_id}/emails` |
| 03 Meeting Reminder | Automation send | `https://system.viteseo.ph/crm/api/automation/leads/{lead_id}/emails` |
| 05 Thank You | Automation send | `https://system.viteseo.ph/crm/api/automation/leads/{lead_id}/emails` |

(04 No-Response Escalation makes no CRM call — escalation is authoritative server-side.)

## n8n-side environment (to set on the n8n host at activation)

```
CRM_API_URL=https://system.viteseo.ph/crm
N8N_WEBHOOK_SECRET=<same shared secret as the CRM's N8N_WEBHOOK_SECRET>
```

## Status / remaining deployment blockers (2026-07-16)

Probe of `https://system.viteseo.ph/crm`, `/crm/api`, `/crm/api/planner-events` → **all HTTP 404**
(host up, TLS OK, but nothing mounted at the path yet). The backend/API is **not yet deployed** at
the target. Setting `CRM_API_URL` live now has no effect until these are resolved:

1. **Backend not deployed** — the Laravel API must be reachable at `https://system.viteseo.ph/crm/api/*`.
   Requires the reverse proxy / ingress at `system.viteseo.ph` to route `/crm/api/*` to the Laravel app.
   (Blocker owner: infra/ops.)
2. **n8n host env access** — `CRM_API_URL` (and `N8N_WEBHOOK_SECRET`) must be set in the n8n
   deployment environment + n8n restarted. Cannot be done from the n8n UI/API. (Blocker owner: ops.)
3. **CRM-side production config not set** — on the deployed CRM: `APP_URL=https://system.viteseo.ph/crm`,
   `N8N_ENABLED=true`, `N8N_WEBHOOK_URL=<n8n ingress>`, `N8N_WEBHOOK_SECRET=<shared secret>`,
   plus `php artisan n8n:token` to issue the "CRM Service Token". (Currently `.env.example` has
   `APP_URL=http://localhost`, `N8N_ENABLED=false`, empty webhook url/secret.)
4. **Service-to-service note (not a blocker, for awareness):** n8n → CRM calls are server-to-server
   (no browser Origin), so CORS does not gate them. `config/cors.php` currently allow-lists only
   localhost origins — that is a **frontend** blocker (browser app at `system.viteseo.ph/crm` calling
   the API), not an n8n-callback blocker. Flag for the frontend deploy, separate from this pack.

## Unchanged / preserved

- No workflow JSON was modified; the `$env.CRM_API_URL` pattern is the frozen, correct wiring.
- Import stays **INACTIVE**; nothing is activated by recording this target.
- Developer Test Mode remains the activation-time safety (`OUTREACH_TEST_MODE`, redirect to
  `bruce.andrada@viteseo.ph`).
