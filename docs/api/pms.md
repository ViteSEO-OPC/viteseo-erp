# PMS API — `/api/v1/projects/*`

Projects module (POD 3). Mounted at `/api/v1/projects` by `ProjectsServiceProvider`.
All routes are behind `auth:sanctum`. Source:
`backend/app/Modules/Projects/Routes/api.php` + `app/Modules/Projects/{Controllers,Requests,Policies}`.

## Authorization model

Routes authorize via **Policies** backed by `projects.*` permissions:

- `pm_apm` (+ `super_admin`) — the **manager** tier: full CRUD on all resources,
  member management, archive/restore. `UserRole::managerRoles()` = `[pm_apm, super_admin]`.
- `pm_member` — read + contribute only: `view` on workspaces/groups/projects/
  statuses/tasks/subtasks, plus `create` on comments and links. Members can only
  view a project they are assigned to (`ProjectPolicy::view`). No create/update/
  delete on core resources.

Guard column notes the effective requirement. "Manager" = `pm_apm|super_admin`.

Resource routes registered via `Route::apiResource(...)` expand to
`index`/`store`/`show`/`update`/`destroy` and are policy-guarded per model.

---

## User Directory

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/users` | Any authenticated | User directory for member/assignee pickers (shared `users` table). |

## Workspaces (`apiResource`)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/workspaces` | `viewAny` (`projects.workspaces.view`) | List workspaces. |
| POST | `/workspaces` | `create` (Manager) | Create a workspace. Fields: `name`, `description?` (`StoreWorkspaceRequest`). |
| GET | `/workspaces/{workspace}` | `view` | Show a workspace. |
| PUT/PATCH | `/workspaces/{workspace}` | `update` (Manager) | Update a workspace (`UpdateWorkspaceRequest`). |
| DELETE | `/workspaces/{workspace}` | `delete` (Manager) | Soft-delete a workspace. |

## Groups (`apiResource` + members)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/groups` | `viewAny` (`projects.groups.view`) | List groups. |
| POST | `/groups` | `create` (Manager) | Create a group. Fields: `project_id`, `name`, `color`, `leader_id`, `archived?` (`StoreGroupRequest`). |
| GET | `/groups/{group}` | `view` | Show a group. |
| PUT/PATCH | `/groups/{group}` | `update` (Manager) | Update a group (`UpdateGroupRequest`). |
| DELETE | `/groups/{group}` | `delete` (Manager) | Delete a group. |
| POST | `/groups/{group}/members` | `manageMembers` (`projects.groups.manage_members`) | Add a member. |
| DELETE | `/groups/{group}/members/{user}` | `manageMembers` | Remove a member. |

## Projects (`apiResource` + members)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/projects` | `viewAny` (`projects.projects.view`) | List projects (members see assigned only). |
| POST | `/projects` | `create` (Manager) | Create a project. Fields: `workspace_id`, `name`, `description?`, `priority?` (low/medium/high), `color?` (`StoreProjectRequest`). |
| GET | `/projects/{project}` | `view` (member: assigned only) | Show a project. |
| PUT/PATCH | `/projects/{project}` | `update` (Manager) | Update a project (`UpdateProjectRequest`). |
| DELETE | `/projects/{project}` | `delete` (Manager) | Soft-delete a project. |
| POST | `/projects/{project}/members` | `manageMembers` (`projects.projects.manage_members`) | Add a member. |
| DELETE | `/projects/{project}/members/{user}` | `manageMembers` | Remove a member. |

## Project Statuses (nested index/store, shallow update/delete)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/projects/{project}/statuses` | `view` project | List a project's statuses (columns). |
| POST | `/projects/{project}/statuses` | `create` (Manager) | Create a status. Fields: `name`, `color` (hex `#RRGGBB`), `order?`; `key`/`is_protected` are prohibited (set by service) (`StoreProjectStatusRequest`). |
| PUT/PATCH | `/statuses/{status}` | `update` (Manager) | Update a status (`UpdateProjectStatusRequest`). |
| DELETE | `/statuses/{status}` | `delete` (Manager) | Delete a status. |

## Tasks (nested index/store, shallow show/update/delete)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/projects/{project}/tasks` | `view` project | List a project's tasks. |
| POST | `/projects/{project}/tasks` | `create` (Manager) | Create a task. Fields: `title`, `description?`, `deadline?`, `assignment_type` (individual/group), `priority`, `status_id`; `group_id` required iff group-assigned; `assignee_id?` only if individual; `effort_score?` (`StoreTaskRequest`). |
| GET | `/tasks/{task}` | `view` | Show a task. |
| PUT/PATCH | `/tasks/{task}` | `update` | Update a task (`UpdateTaskRequest`). |
| DELETE | `/tasks/{task}` | `delete` (Manager) | Soft-delete a task. |

## Bulk task import

Open to **both tiers**, on any project the caller can `view` — but they produce
different things, and `TaskImportService::isProposer()` decides which from the
caller's permission. The permissions are checked explicitly rather than through
`TaskPolicy::create`, which collapses `create` and `propose` into one answer.

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/projects/{project}/tasks/import/template` | `tasks.create` **or** `tasks.propose` | Download the Markdown brief for this project (`text/markdown` attachment): JSON schema, allowed values, and every rule the importer enforces. Written to be handed to an LLM. **Two variants** — see below. |
| POST | `/projects/{project}/tasks/import/preview` | same | Dry run. Multipart `file` (`mimes:json,txt`, ≤2 MB). Parses, resolves names → ids scoped to the project, validates each row against `StoreTaskRequest`/`StoreSubtaskRequest`, returns `{mode, summary, options, unresolved[], tasks[], workload}`. Writes nothing. Unresolved values are **aggregated** — one invented status used on 14 rows is a single entry carrying all 14 `refs`. |
| POST | `/projects/{project}/tasks/import` | same | Create the corrected plan. One transaction; each row goes through `TaskService::create` / `SubtaskService::create`, so uuids, `task.created`/`subtask.created` activity logs, `assigned_by_id` and assignee notifications all behave as for a hand-created task. With `dry_run: true` it re-validates and returns `errors` keyed by row ref without creating — that is what the preview's "Re-check" calls. |

### `direct` mode — `projects.tasks.create` (PM / Admin)

Brief carries the project's statuses, groups and **member display names** (never email
addresses — it is pasted into a third-party assistant). A task may name an `assignee`
**or** a `group`, or neither (open pool). Cap **200 tasks / 1000 items**. Tasks land
`approval_status = approved`, straight onto the board, and the commit broadcasts **one**
`TasksImported` on `project.{uuid}`.

### `proposal` mode — `projects.tasks.propose` only (member)

A member can only post work for themselves, so their brief has **no `assignee` and no
`group` field at all** — which also means no roster data leaves the org on this path.
Both fields are ignored if present, and the assignment is re-forced on commit, not just
on preview. `effort_score` is **required on every task and sub-task** (it is their own
capacity being committed). Cap **25 tasks** (`MAX_MEMBER_TASKS`).

Everything lands `approval_status = pending` with `submitted_by_id` set; sub-tasks
inherit that review through `SubtaskService`, so a task and its sub-tasks reach the
approvals queue as **one bundle** (`ApprovalService::subtasks()` excludes sub-tasks whose
parent is unapproved). Deliberately **silent**: no `TasksImported` broadcast (a pending
task is on nobody's board) and no review notification — `notifyTaskAssigned` already
returns early on self-assignment, so an import lands as one batch in the queue rather
than N entries in every PM's bell.

The preview also returns a `workload` projection for this mode: `{current, adds,
projected, level, projected_level, thresholds}`. `current` counts **approved work only**
(`WorkloadService::effortFor`), so this is "what your load becomes if your PM approves
all of it", and `adds` mirrors WorkloadService's rule — a task with sub-tasks contributes
nothing and only its sub-tasks count. Advisory: it warns, it never blocks.

Row identity is a synthetic `ref` (`t3`, `t3s1`) so the client can address rows for bulk
remaps and the server can report errors against the same identity. In either mode an
assignee is resolved against `pm_project_members` — an import can never put work on
someone who is not on the project.

## Subtasks (nested index/store, shallow show/update/delete)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/tasks/{task}/subtasks` | `view` task | List subtasks of a task. |
| POST | `/tasks/{task}/subtasks` | `create` | Create a subtask. Fields: `name`, `description?`, `assignee_id?`, `status_id`, `priority`, `effort_score` (required), `deadline?` (`StoreSubtaskRequest`). |
| GET | `/subtasks/{subtask}` | `view` | Show a subtask. |
| PUT/PATCH | `/subtasks/{subtask}` | `update` | Update a subtask (`UpdateSubtaskRequest`). |
| DELETE | `/subtasks/{subtask}` | `delete` | Soft-delete a subtask. |

## Comments (task XOR subtask parent)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/tasks/{task}/comments` | `view` task | List a task's comments. |
| POST | `/tasks/{task}/comments` | `create` (`projects.comments.create`) | Add a comment to a task. Fields: `content` (`StoreCommentRequest`). |
| GET | `/subtasks/{subtask}/comments` | `view` subtask | List a subtask's comments. |
| POST | `/subtasks/{subtask}/comments` | `create` | Add a comment to a subtask. |
| DELETE | `/comments/{comment}` | `delete` | Delete a comment. |

## Links (task XOR subtask parent)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/tasks/{task}/links` | `view` task | List a task's links. |
| POST | `/tasks/{task}/links` | `create` (`projects.links.create`) | Add a link to a task. Fields: `url` (`StoreLinkRequest`). |
| GET | `/subtasks/{subtask}/links` | `view` subtask | List a subtask's links. |
| POST | `/subtasks/{subtask}/links` | `create` | Add a link to a subtask. |
| DELETE | `/links/{link}` | `delete` | Delete a link. |

## Activity Logs

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/tasks/{task}/activity-logs` | `view` (`projects.activity_logs.view`) | Read-only task activity log. |

## Notifications (scoped to the authenticated user)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/notifications` | Own | List the caller's notifications. |
| GET | `/notifications/unread-count` | Own | Unread notification count. |
| PATCH | `/notifications/read-all` | Own | Mark all as read. |
| PATCH | `/notifications/{notification}/read` | Own | Mark one as read. |

## Archives (`admin/archives`, manager-only — enforced in controller)

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/admin/archives/workspaces` | Manager | List trashed workspaces. |
| GET | `/admin/archives/projects` | Manager | List trashed projects. |
| GET | `/admin/archives/tasks` | Manager | List trashed tasks. |
| GET | `/admin/archives/subtasks` | Manager | List trashed subtasks. |
| POST | `/admin/archives/workspaces/{uuid}/restore` | Manager | Restore a workspace. |
| POST | `/admin/archives/projects/{uuid}/restore` | Manager | Restore a project. |
| POST | `/admin/archives/tasks/{uuid}/restore` | Manager | Restore a task. |
| POST | `/admin/archives/subtasks/{uuid}/restore` | Manager | Restore a subtask. |

**Total PMS endpoints: 60** (`apiResource` expanded to its 5 verbs each;
`PUT`/`PATCH` `match` routes counted once). Note: a `channels.php` file also
registers private Reverb broadcast channels for real-time collaboration (inert
until a broadcaster is configured) — those are WebSocket channels, not HTTP
endpoints.
</content>
