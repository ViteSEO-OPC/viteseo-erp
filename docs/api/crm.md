# CRM API — `/api/v1/crm/*`

CRM (Zerem) module (POD 2). Mounted at `/api/v1/crm` by `CrmServiceProvider`.
All routes are behind `auth:sanctum` **except** the public Gmail webhook. Source:
`backend/app/Modules/Crm/Routes/api.php` + `app/Modules/Crm/{Controllers,Requests,Policies}`.

## Authorization model

Most routes authorize via **Policies** (`->can(...)`) backed by `crm.*`
permissions, sometimes combined with explicit `permission:` / `role:` middleware:

- `crm_admin` — all `crm.*` except `crm.users.delete`.
- `crm_bd` — leads/notes/follow-ups/planner/dashboard, **scoped to own leads**
  (`Lead::isVisibleTo`); no export, delete, reports, or user management.
- `super_admin` — bypasses every CRM policy (`Gate::before`).

Guard column below cites the effective requirement (policy ability / permission /
role). Where a per-lead policy applies, a BD is additionally limited to leads
visible to them.

---

## Leads

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/leads` | `viewAny` (`crm.leads.view`) | List leads (`IndexLeadRequest` — filters/pagination). |
| POST | `/leads` | `create` (`crm.leads.create`) | Create a lead. Key fields: `business_name`, `contact_name` (required); `email?`, `phone?`, `industry?`, `priority?`, `status?`, `amount?` (est. deal value), `region/province/city?`, `assigned_to` (required for admin/SA — must be a BD; auto-self for BD) (`StoreLeadRequest`). |
| GET | `/leads/{lead}` | `view` (own for BD) | Show a lead. |
| PUT/PATCH | `/leads/{lead}` | `update` (`crm.leads.edit`, own for BD) | Update a lead (`UpdateLeadRequest`). |
| DELETE | `/leads/{lead}` | `delete` (`crm.leads.delete`, own for BD) | Soft-delete a lead. |
| PATCH | `/leads/{lead}/assign` | `assign` (`crm.leads.assign`) | Assign/reassign a lead to a BD. Fields: `assigned_to` (must be a BD) (`AssignLeadRequest`). |
| POST | `/leads/import` | `import` (`crm.leads.import`) | Import leads from CSV/XLSX. Fields: `file` (csv/txt/xlsx ≤10MB), `dry_run?` (default true = preview), `assigned_to?` (required on commit for admin/SA) (`ImportLeadsRequest`). |
| POST | `/leads/discovered` | `import` (`crm.leads.import`) | Ingest discovered businesses (bulk). Fields: `businesses[]` (1–500, each with `business_name`, geo, `source`, `website?`…), `dry_run?` (`IngestDiscoveredLeadsRequest`). |
| GET | `/leads/{lead}/activities` | `view` (own for BD) | Activity log for a lead. |
| GET | `/leads/{lead}/score` | `view` (own for BD) | Personal Lead Score breakdown. |

## Website Enrichment

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/website-enrichment/queue` | `import` (`crm.leads.import`) | Leads queued for website intelligence enrichment. |
| PUT | `/leads/{lead}/website-enrichment` | `import` (`crm.leads.import`) | Post enrichment results. Fields incl. `website_reachable`, `https_enabled`, core-page booleans, `emails_found[]`, socials, metadata, `provider` (`StoreWebsiteEnrichmentRequest`). |

## Emails / Outreach

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/emails` | `permission:crm.follow-ups.view` | List outreach emails/drafts. |
| POST | `/leads/{lead}/emails` | `permission:crm.follow-ups.create` + `view` lead | Send an email to a lead. Fields: `subject`, `body`, `to?` (defaults to lead email) (`SendEmailRequest`). |
| POST | `/leads/{lead}/emails/drafts` | `permission:crm.follow-ups.create` + `view` lead | Save a draft. Fields: `subject?`, `body?`, `to?` (`SaveDraftRequest`). |
| PUT/PATCH | `/emails/{email}` | `update` email | Update a draft (`SaveDraftRequest`). |
| DELETE | `/emails/{email}` | `delete` email | Delete a draft. |
| POST | `/emails/{email}/send` | `send` email | Send an existing draft. |

## Follow-ups

| Method | Path | Guard | Purpose |
|---|---|---|---|
| POST | `/leads/{lead}/follow-ups` | `permission:crm.follow-ups.create` + `view` lead | Schedule a follow-up for a lead. Fields: `title?`, `priority?`, `starts_at?`, `ends_at?` (≥ start), `reminder_minutes?`, `description?` (`StoreLeadFollowUpRequest`). Creates a PlannerEvent. |

## Notes (internal, on a lead)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/leads/{lead}/notes` | `view` lead | List notes on a lead. |
| POST | `/leads/{lead}/notes` | `permission:crm.lead.notes.create` + `view` lead | Add a note. Fields: `body` (required, ≤5000) (`StoreNoteRequest`). |
| PATCH | `/leads/{lead}/notes/{note}` | `update` note (scoped) | Edit a note (`UpdateNoteRequest`). |
| DELETE | `/leads/{lead}/notes/{note}` | `delete` note (scoped) | Delete a note. |

## Planner Events (Calendar + Board)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/planner-events` | `viewAny` PlannerEvent | List planner events. |
| POST | `/planner-events` | `create` PlannerEvent | Create an event. Fields: `title`, `event_type` (email/call/meeting/task/follow-up), `status?`, `priority?`, `starts_at?`, `ends_at?`, `all_day?`, `source?`, `lead_id?`, `deal_id?`, `assigned_to` (required for admin/SA, must be a BD) (`StorePlannerEventRequest`). |
| GET | `/planner-events/{plannerEvent}` | `view` plannerEvent | Show an event. |
| PUT/PATCH | `/planner-events/{plannerEvent}` | `update` plannerEvent | Update an event (`UpdatePlannerEventRequest`). |
| DELETE | `/planner-events/{plannerEvent}` | `delete` plannerEvent | Delete an event. |

## Team Accounts (CRM user management)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/users` | `viewAny` User (`crm.users.view`) | List CRM team accounts. |
| POST | `/users` | `create` User (`crm.users.create`) | Create a team account. Fields: `name`, `email` (unique), `role` (only roles the creator may assign — never super-admin), `status?`, `password?` (`StoreUserRequest`). |
| PUT/PATCH | `/users/{user}` | `update` user | Update a team account (`UpdateUserRequest`). |
| DELETE | `/users/{user}` | `delete` user (`crm.users.delete`) | Delete a team account (blocked for `crm_admin`; super_admin only). |

## Nearby Clients & Service Areas

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/nearby-clients` | Any authenticated | Discovered-business prospect pool (BD-facing). |
| GET | `/service-areas` | `role:super_admin\|crm_admin` | List service-area registry entries. |
| POST | `/service-areas` | `role:super_admin\|crm_admin` | Add a service area. Fields: `name`, `level` (one of `ServiceArea::LEVELS`) (`StoreServiceAreaRequest`). |
| DELETE | `/service-areas/{serviceArea}` | `role:super_admin\|crm_admin` | Delete a service area. |

## Activities

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/activities` | Any authenticated | Generic append-only activity feed. |

## Dashboard (`permission:crm.dashboard.view`)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/dashboard/kpis` | `crm.dashboard.view` | Headline KPIs. |
| GET | `/dashboard/priority-buckets` | `crm.dashboard.view` | Leads grouped by priority. |
| GET | `/dashboard/recent-leads` | `crm.dashboard.view` | Recent leads. |
| GET | `/dashboard/lead-acquisition` | `crm.dashboard.view` | Lead acquisition trend. |
| GET | `/dashboard/performance-summary` | `crm.dashboard.view` | Performance summary. |
| GET | `/dashboard/kpi-metrics` | `crm.dashboard.view` | Detailed KPI metrics. |
| GET | `/dashboard/bd-performance` | `crm.dashboard.view` + `role:super_admin\|crm_admin` | Per-BD performance (managers only). |
| GET | `/dashboard/bd-ranking` | `crm.dashboard.view` + `role:super_admin\|crm_admin` | BD ranking (managers only). |

## Webhooks (public)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| POST | `/webhooks/gmail` | Public (Google OIDC token verified in controller) + `throttle:120,1` | Inbound Gmail Pub/Sub push for mailbox sync. Not Sanctum. |

**Total CRM endpoints: 47** (`PUT`/`PATCH` `match` routes counted once).
</content>
