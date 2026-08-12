"use client";

import { useState } from "react";
import { isAxiosError } from "axios";
import { Button } from "@/core/ui";
import { notify } from "@/core/lib/notify";
import {
  DashboardTaskTable,
  type DashboardTaskRow,
} from "@/modules/projects/components/dashboard-shared";
import { useClaimableTasks, useClaimTask } from "@/modules/projects/hooks/use-tasks";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";

export interface ClaimableTasksPanelProps {
  /** Scope to one project. Omit for the whole pool (every project). */
  projectId?: string;
  limit?: number;
  className?: string;
}

/**
 * Open tasks — work someone posted with no assignee, free for anyone to take.
 *
 * The pool is contested, so the Claim button is the one thing that must not lie:
 * a row can be taken between the fetch and the click. The server settles that
 * with a 409, and this turns it into "X took it" plus a refetch rather than a
 * generic failure.
 *
 * Rendered through the shared DashboardTaskTable so this reads as the same table
 * as every other task list — the dashboards, the board's List view and the
 * archives all share that column layout. Claim rides in as its action column.
 */
export function ClaimableTasksPanel({
  projectId,
  limit = 10,
  className = "",
}: ClaimableTasksPanelProps): React.ReactElement {
  const { data, isLoading, isError } = useClaimableTasks({ projectId, limit });
  const claim = useClaimTask();
  const openTask = useOpenTask();
  // Which row is in flight — the whole list must not spin for one claim.
  const [claiming, setClaiming] = useState<string | null>(null);

  const tasks = data?.data ?? [];

  if (isLoading) {
    return <p className={`text-sm text-ink-soft ${className}`}>Loading…</p>;
  }

  if (isError) {
    return (
      <p className={`text-sm text-ink-soft ${className}`}>
        Couldn&apos;t load open tasks.
      </p>
    );
  }

  if (tasks.length === 0) {
    return (
      <p className={`text-sm text-ink-soft ${className}`}>
        Nothing up for grabs right now.
      </p>
    );
  }

  const onClaim = async (id: string, title: string) => {
    setClaiming(id);
    try {
      await claim.mutateAsync(id);
      notify.success("Task claimed", `“${title}” is yours — it's on your board now.`);
    } catch (error) {
      // 409: someone else got there first. The message names them, and the
      // mutation's invalidation has already pulled the row off the list.
      if (isAxiosError(error) && error.response?.status === 409) {
        notify.warning(
          "Already taken",
          (error.response.data as { message?: string })?.message ??
            "Someone claimed this a moment ago.",
        );
      } else {
        notify.error("Couldn't claim that", "Please try again.");
      }
    } finally {
      setClaiming(null);
    }
  };

  // ClaimableTask nests its project; the shared table looks projects up by id,
  // so build those maps from what the pool already returned rather than firing
  // another projects request.
  const projectName = new Map<string, string>();
  const projectColor = new Map<string, string | null>();
  for (const t of tasks) {
    if (t.project) {
      projectName.set(t.project.id, t.project.name);
      projectColor.set(t.project.id, null);
    }
  }

  const rows: (DashboardTaskRow & { id: string; title: string })[] = tasks.map(
    (t) => ({
      id: t.id,
      project_id: t.project?.id ?? "",
      title: t.title,
      priority: t.priority,
      status: t.status,
      deadline: t.deadline,
    }),
  );

  return (
    <div className={className}>
      <DashboardTaskTable
        items={rows}
        projectName={projectName}
        projectColor={projectColor}
        onOpen={openTask}
        actionHeader="Action"
        action={(row) => (
          <Button
            size="sm"
            disabled={claiming !== null}
            onClick={() => onClaim(row.id, row.title)}
          >
            {claiming === row.id ? "Claiming…" : "Claim"}
          </Button>
        )}
      />
    </div>
  );
}
