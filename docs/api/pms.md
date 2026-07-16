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

**Total PMS endpoints: 57** (`apiResource` expanded to its 5 verbs each;
`PUT`/`PATCH` `match` routes counted once). Note: a `channels.php` file also
registers private Reverb broadcast channels for real-time collaboration (inert
until a broadcaster is configured) — those are WebSocket channels, not HTTP
endpoints.
</content>
