import type { QueryClient } from "@tanstack/react-query";

import type { Paginated } from "@/core/types";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import type { Task } from "@/modules/projects/types/task";

/**
 * Invalidate every cached task bundle belonging to one project.
 *
 * Task details are keyed by task id alone — the project is not in the key — so
 * "refresh this project's tasks" has no prefix to match. Call sites used to
 * settle for `["projects","tasks"]`, which is EVERY task in the cache plus its
 * sub-tasks, comments, links and activity: deleting one status on one project
 * refetched every task detail the session had ever opened.
 *
 * The task ids are recovered from what is already cached — the project's board,
 * plus any detail whose row names this project — and only those bundles are
 * invalidated. Nothing cached means nothing to do: an unfetched query is not
 * stale, and it will fetch on its next mount regardless.
 */
export function invalidateProjectTasks(
  qc: QueryClient,
  projectId: string | undefined,
): void {
  if (!projectId) return;

  const ids = new Set<string>();

  const board = qc.getQueryData<Paginated<Task>>(projectKeys.tasks(projectId));
  board?.data.forEach((task) => ids.add(task.id));

  for (const [key, data] of qc.getQueriesData<Task>({
    queryKey: projectKeys.tasksRoot,
  })) {
    // Detail entries only: ["projects","tasks",id]. The children hanging off
    // them (["projects","tasks",id,"comments"] and friends) are picked up by
    // the id match below, not here.
    if (key.length !== 3) continue;
    const id = key[2];
    if (typeof id === "string" && data?.project_id === projectId) ids.add(id);
  }

  if (ids.size === 0) return;

  qc.invalidateQueries({
    predicate: (query) => {
      const [namespace, resource, id] = query.queryKey;
      return (
        namespace === "projects" &&
        resource === "tasks" &&
        typeof id === "string" &&
        ids.has(id)
      );
    },
  });
}
