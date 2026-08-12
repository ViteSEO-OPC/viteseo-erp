"use client";

import { useState } from "react";
import { isAxiosError } from "axios";
import { Button, PriorityBadge } from "@/core/ui";
import { notify } from "@/core/lib/notify";
import { ProjectPill } from "@/modules/projects/components/pills";
import { useClaimableTasks, useClaimTask } from "@/modules/projects/hooks/use-tasks";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";
import { isOverdue, OverdueBadge } from "@/modules/projects/lib/overdue";

export interface ClaimableTasksPanelProps {
  /** Scope to one project. Omit for the whole pool (every project). */
  projectId?: string;
  limit?: number;
  className?: string;
}

function formatDate(value: string | null): string {
  if (!value) return "no due date";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "no due date";
  return `due ${d.toLocaleDateString("en-US", { month: "short", day: "numeric" })}`;
}

/**
 * Open tasks — work someone posted with no assignee, free for anyone to take.
 *
 * The pool is contested, so the Claim button is the one thing that must not lie:
 * a row can be taken between the fetch and the click. The server settles that
 * with a 409, and this turns it into "X took it" plus a refetch rather than a
 * generic failure.
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

  return (
    <div className={`divide-y divide-line ${className}`}>
      {tasks.map((task) => {
        const overdue = isOverdue(task.deadline, task.status?.key);

        return (
          <div key={task.id} className="flex items-start gap-3 px-4 py-3">
            <button
              type="button"
              onClick={() => openTask(task.id)}
              className="min-w-0 flex-1 text-left"
            >
              <p className="truncate font-bold text-ink">{task.title}</p>
              <div className="mt-1 flex flex-wrap items-center gap-2">
                {task.project && (
                  <ProjectPill name={task.project.name} color={null} />
                )}
                <PriorityBadge priority={task.priority} />
                <span
                  className={`text-xs ${overdue ? "font-semibold text-danger" : "text-ink-faint"}`}
                >
                  {formatDate(task.deadline)}
                </span>
                {overdue && <OverdueBadge />}
                {task.posted_by && (
                  <span className="text-xs text-ink-faint">
                    posted by {task.posted_by.name}
                  </span>
                )}
              </div>
            </button>

            <Button
              size="sm"
              className="shrink-0"
              disabled={claiming !== null}
              onClick={() => onClaim(task.id, task.title)}
            >
              {claiming === task.id ? "Claiming…" : "Claim"}
            </Button>
          </div>
        );
      })}
    </div>
  );
}
