# PMS (Sprintly) — changes between old snapshot and pod-latest

Generated: 2026-07-16 15:55 | Base: `PMS` | Latest: `pod-latest/pms`

## Added (only in pod-latest)

- `backend/app/Modules/Projects/Concerns/ChecksDeadlineDates.php`
- `backend/app/Modules/Projects/Controllers/WorkloadController.php`
- `backend/app/Modules/Projects/Events/TaskCompleted.php`
- `backend/app/Modules/Projects/Services/WorkloadService.php`
- `backend/app/Modules/Projects/Support/SafeBroadcast.php`
- `backend/config/workload.php`
- `backend/database/migrations/2026_07_14_000000_remove_capacity_points_from_users_table.php`
- `backend/database/migrations/2026_07_14_100000_add_start_date_to_pm_tasks_table.php`
- `frontend/src/core/lib/confetti.ts`
- `frontend/src/modules/projects/components/dashboard`
- `frontend/src/modules/projects/components/groups`
- `frontend/src/modules/projects/components/notifications`
- `frontend/src/modules/projects/components/profile`
- `frontend/src/modules/projects/components/projects`
- `frontend/src/modules/projects/components/shared`
- `frontend/src/modules/projects/components/status`
- `frontend/src/modules/projects/components/tasks`
- `frontend/src/modules/projects/components/team`
- `frontend/src/modules/projects/components/workspaces`

## Removed (only in old snapshot)

- `frontend/src/modules/projects/components/AllProjectsView.tsx`
- `frontend/src/modules/projects/components/CreateGroupModal.tsx`
- `frontend/src/modules/projects/components/CreateProjectModal.tsx`
- `frontend/src/modules/projects/components/DashboardShared.tsx`
- `frontend/src/modules/projects/components/DashboardView.tsx`
- `frontend/src/modules/projects/components/EditGroupModal.tsx`
- `frontend/src/modules/projects/components/EditProjectModal.tsx`
- `frontend/src/modules/projects/components/EditWorkspaceModal.tsx`
- `frontend/src/modules/projects/components/GlobalTaskModal.tsx`
- `frontend/src/modules/projects/components/GroupsView.tsx`
- `frontend/src/modules/projects/components/ManagerDashboardView.tsx`
- `frontend/src/modules/projects/components/ManagerTeamView.tsx`
- `frontend/src/modules/projects/components/MemberDashboardView.tsx`
- `frontend/src/modules/projects/components/MemberPickerModal.tsx`
- `frontend/src/modules/projects/components/MemberTeamView.tsx`
- `frontend/src/modules/projects/components/MultiSelectFilter.tsx`
- `frontend/src/modules/projects/components/NotificationDropdown.tsx`
- `frontend/src/modules/projects/components/NotificationsView.tsx`
- `frontend/src/modules/projects/components/ProfileView.tsx`
- `frontend/src/modules/projects/components/ProjectDetailView.tsx`
- `frontend/src/modules/projects/components/StatusDonut.tsx`
- `frontend/src/modules/projects/components/StatusManagerModal.tsx`
- `frontend/src/modules/projects/components/TaskModal.tsx`
- `frontend/src/modules/projects/components/TeamShared.tsx`
- `frontend/src/modules/projects/components/TeamView.tsx`
- `frontend/src/modules/projects/components/WorkspaceDetailView.tsx`
- `frontend/src/modules/projects/components/WorkspaceViews.tsx`
- `frontend/src/modules/projects/components/WorkspacesView.tsx`
- `frontend/src/modules/projects/components/task-modal`
- `frontend/src/modules/projects/components/workspace-views`

## Modified

- `backend/app/Core/Resources/UserResource.php`
- `backend/app/Models/User.php`
- `backend/app/Modules/Projects/Controllers/CommentController.php`
- `backend/app/Modules/Projects/Controllers/ProjectStatusController.php`
- `backend/app/Modules/Projects/Controllers/SubtaskController.php`
- `backend/app/Modules/Projects/Controllers/TaskController.php`
- `backend/app/Modules/Projects/Events/NotificationCreated.php`
- `backend/app/Modules/Projects/Events/ProjectBroadcastEvent.php`
- `backend/app/Modules/Projects/Models/Task.php`
- `backend/app/Modules/Projects/Requests/StoreSubtaskRequest.php`
- `backend/app/Modules/Projects/Requests/StoreTaskRequest.php`
- `backend/app/Modules/Projects/Requests/UpdateSubtaskRequest.php`
- `backend/app/Modules/Projects/Requests/UpdateTaskRequest.php`
- `backend/app/Modules/Projects/Resources/TaskDetailResource.php`
- `backend/app/Modules/Projects/Resources/TaskResource.php`
- `backend/app/Modules/Projects/Resources/TaskSummaryResource.php`
- `backend/app/Modules/Projects/Routes/api.php`
- `backend/app/Modules/Projects/Services/NotificationService.php`
- `backend/composer.json`
- `backend/database/seeders/DemoSeeder.php`
- `frontend/src/app/(app)/projects/groups/page.tsx`
- `frontend/src/app/(app)/projects/layout.tsx`
- `frontend/src/app/(app)/projects/notifications/page.tsx`
- `frontend/src/app/(app)/projects/page.tsx`
- `frontend/src/app/(app)/projects/profile/page.tsx`
- `frontend/src/app/(app)/projects/projects/[projectId]/page.tsx`
- `frontend/src/app/(app)/projects/projects/page.tsx`
- `frontend/src/app/(app)/projects/team/manager/page.tsx`
- `frontend/src/app/(app)/projects/team/member/page.tsx`
- `frontend/src/app/(app)/projects/team/page.tsx`
- `frontend/src/app/(app)/projects/workspaces/[id]/page.tsx`
- `frontend/src/app/(app)/projects/workspaces/page.tsx`
- `frontend/src/core/forms/validation.ts`
- `frontend/src/core/layout/Topbar.tsx`
- `frontend/src/core/types/index.ts`
- `frontend/src/modules/projects/api.ts`
- `frontend/src/modules/projects/hooks.ts`
- `frontend/src/modules/projects/types.ts`
- `frontend/src/modules/projects/useProjectChannel.ts`
