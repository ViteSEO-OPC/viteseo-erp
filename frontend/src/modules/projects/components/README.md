# Projects (PMS) components

One folder per **domain** — the thing a screen is about — plus a single
`shared/` for what genuinely has no domain. The rule for placing a new file:

> Name the noun the component is about. If a folder for that noun exists, it
> goes there. If three or more domains already import it, it goes in `shared/`.
> If neither, it is probably a new domain — add the folder.

| Folder | What lives here |
|---|---|
| `shared/` | Cross-domain primitives: the table chrome (`data-table`), loading placeholders (`skeletons`), badges/pills, pagination, the status donut, global search, the activity feed, the module's theme scope. |
| `dashboard/` | The manager and member dashboards and the pieces only they use. |
| `projects/` | The projects list, a project's detail page, and its create/edit modals. |
| `workspaces/` | The workspaces list, a workspace's detail page, its edit modal. |
| `board/` | The task board itself — List / Kanban / Calendar and the shape they share. Mounted by the workspace page, the project page and "My Tasks". |
| `tasks/` | The task modal and everything inside it: view, create, import, statuses. |
| `team/` | The team roster and the member picker. |
| `groups/` | Groups list and its modals. |
| `workload/` | Workload analysis: chart, table, capacity. |
| `review/` | The approval and archive queues — the two management surfaces that share `admin-table`. |
| `notes/` | The notes editor and the four shells it renders in (card, sheet, panel, toolbar). |
| `notifications/` | The notifications page and the topbar dropdown. |
| `profile/` | The profile page. |

## Boundaries

**One hard rule: `shared/` may not import from a domain folder.** It is the
bottom of the graph. A domain importing `shared/` is normal; the reverse means
the file was filed wrong. This currently holds with zero exceptions, and it is
the one worth checking in review.

**Domain → domain imports are expected, not a smell.** There are ~26 of them and
they are feature composition, not leakage — three domains are *mounted* by
others rather than owning a route of their own:

- `board/` — the List/Kanban/Calendar switcher, mounted by `projects/`,
  `workspaces/` and `dashboard/` ("My Tasks").
- `notes/` — the notes rail, mounted by `dashboard/`, `projects/` and `board/`.
- `tasks/` — the task modal, opened from every list that shows a task.

The rest are ordinary: `team ↔ groups` (the team page creates a group, the
groups page picks members), `projects/` reading `MemberGroupTags` from
`team-shared`, `tasks/` using `review/`'s reject dialog.

What *is* a smell is a domain importing another domain for something generic.
That is how `Pagination` ended up in `team-shared` (imported by the archives,
approvals, projects and workload tables) and how the workload badges ended up in
`task-shared` (imported by `team/` and `groups/`). Both now live in `shared/`.
The test: **if three or more domains import it, it is not domain code.**

**`*-shared` files are a domain's own helpers**, not module-wide ones —
`task-shared`, `team-shared`, `workload-shared`, `dashboard-shared`,
`workspace-view-shared`. Module-wide helpers live in `shared/`.

Everything is imported by its full path
(`@/modules/projects/components/<folder>/<file>`); there are no barrel
`index.ts` files, so an import always says where the thing lives.
