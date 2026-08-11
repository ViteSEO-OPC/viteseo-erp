import apiClient from "@/core/api/client";
import type { ApiResource, Paginated } from "@/core/types";
import type {
  GroupWorkload,
  Workload,
  WorkloadAnalysis,
  WorkloadRow,
  WorkloadThresholds,
} from "@/modules/projects/types/workload";

// Live member workload (Member Workload Indicator) — loaded on demand when an
// assignee is selected in the task modal.
export async function getUserWorkload(userId: number): Promise<Workload> {
  const { data } = await apiClient.get<ApiResource<Workload>>(
    `/projects/members/${userId}/workload`,
  );
  return data.data;
}

// Live group workload (same aggregation, scoped to the group) — for the
// Groups page and the task modal's group-assignment indicator.
export async function getGroupWorkload(groupId: string): Promise<GroupWorkload> {
  const { data } = await apiClient.get<ApiResource<GroupWorkload>>(
    `/projects/groups/${groupId}/workload`,
  );
  return data.data;
}

// Workload Analysis. The payload is role-scoped server-side: a manager gets
// the whole platform, a member only their own work.
export async function getWorkloadAnalysis(
  params?: Record<string, unknown>,
): Promise<WorkloadAnalysis> {
  const { data } = await apiClient.get<ApiResource<WorkloadAnalysis>>(
    "/projects/workload/analysis",
    { params },
  );
  return data.data;
}

export async function getWorkloadTasks(
  params?: Record<string, unknown>,
): Promise<Paginated<WorkloadRow>> {
  const { data } = await apiClient.get<Paginated<WorkloadRow>>(
    "/projects/workload/analysis/tasks",
    { params },
  );
  return data;
}

export async function updateWorkloadCapacity(payload: {
  max_capacity: number;
  light_max: number;
  moderate_max: number;
}): Promise<WorkloadThresholds> {
  const { data } = await apiClient.put<ApiResource<WorkloadThresholds>>(
    "/projects/workload/capacity",
    payload,
  );
  return data.data;
}
