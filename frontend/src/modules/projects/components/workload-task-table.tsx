"use client";

import { Avatar, Card, PriorityBadge } from "@/core/ui";
import type {
  WorkloadBar,
  WorkloadRow,
} from "@/modules/projects/types/workload";
import { ProjectPill } from "@/modules/projects/components/pills";
import { Pagination } from "@/modules/projects/components/team-shared";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";
import {
  CategoryBadge,
  EffortCell,
} from "@/modules/projects/components/workload-shared";

/** Effort is scored 0-10 per task, independent of the capacity bands. */
const TASK_EFFORT_MAX = 10;

function fmtDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-US", {
    month: "short",
    day: "2-digit",
    year: "numeric",
  });
}

/**
 * The page's task table: every active work row, flattened across tasks and
 * sub-tasks. A sub-task row names its parent so "Branding" isn't context-free.
 * Clicking a row opens the ordinary task modal.
 */
export function WorkloadTaskTable({
  result,
  page,
  onPage,
  showAssignee = true,
}: {
  result: {
    data?: { data: WorkloadRow[]; meta: { total: number; last_page: number } };
    isLoading: boolean;
    isError: boolean;
  };
  page: number;
  onPage: (page: number) => void;
  /** Hidden on the member page — every row is theirs. */
  showAssignee?: boolean;
}) {
  const openTask = useOpenTask();
  const { data, isLoading, isError } = result;
  const rows = data?.data ?? [];
  const total = data?.meta.total ?? 0;
  const pageCount = Math.max(1, data?.meta.last_page ?? 1);

  if (isError) {
    return (
      <Card className="p-8 text-center">
        <p className="font-semibold text-danger">Couldn&apos;t load the task list.</p>
        <p className="mt-1 text-sm text-ink-soft">
          Make sure the API is running and you&apos;re signed in.
        </p>
      </Card>
    );
  }

  const columns = ["Project Name", "Task Name", "Effort", "Category", "Priority"];
  if (showAssignee) columns.push("Assignee");
  columns.push("Due Date");

  return (
    <>
      <Card className="overflow-hidden rounded-2xl p-0">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[860px] border-collapse">
            <thead>
              <tr className="border-b border-line bg-surface-alt">
                {columns.map((c) => (
                  <th
                    key={c}
                    className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink"
                  >
                    {c}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                <tr>
                  <td colSpan={columns.length} className="py-10 text-center text-ink-soft">
                    Loading tasks...
                  </td>
                </tr>
              ) : rows.length === 0 ? (
                <tr>
                  <td colSpan={columns.length} className="py-10 text-center text-ink-soft">
                    No active work matches these filters.
                  </td>
                </tr>
              ) : (
                rows.map((r, idx) => (
                  <tr
                    key={`${r.kind}-${r.id}`}
                    onClick={() => r.task_id && openTask(r.task_id)}
                    className={`cursor-pointer border-b border-line last:border-b-0 hover:bg-black/[0.02] ${
                      idx % 2 === 1 ? "bg-surface-alt" : "bg-white"
                    }`}
                  >
                    <td className="px-5 py-3.5">
                      <ProjectPill name={r.project_name ?? "—"} />
                    </td>
                    <td className="px-5 py-3.5 text-sm">
                      <span className="font-semibold text-ink">{r.title}</span>
                      {r.kind === "subtask" && r.parent_title && (
                        <span className="block text-xs text-ink-faint">
                          ↳ sub-task in {r.parent_title}
                        </span>
                      )}
                    </td>
                    <td className="px-5 py-3.5 text-sm">
                      <EffortCell
                        score={r.effort_score}
                        category={r.category}
                        max={TASK_EFFORT_MAX}
                      />
                    </td>
                    <td className="px-5 py-3.5">
                      <CategoryBadge category={r.category} />
                    </td>
                    <td className="px-5 py-3.5">
                      {r.priority && <PriorityBadge priority={r.priority} />}
                    </td>
                    {showAssignee && (
                      <td className="px-5 py-3.5 text-sm">
                        {r.assignee_name ? (
                          <span className="flex items-center gap-2">
                            <Avatar name={r.assignee_name} size={24} />
                            <span className="text-ink">{r.assignee_name}</span>
                          </span>
                        ) : r.group_name ? (
                          <span className="text-ink-soft">{r.group_name} (group)</span>
                        ) : (
                          <span className="text-ink-faint">Unassigned</span>
                        )}
                      </td>
                    )}
                    <td className="px-5 py-3.5 text-sm text-ink-soft">
                      {fmtDate(r.deadline)}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {!isLoading && total > 0 && (
        <Pagination
          page={page - 1}
          pageCount={pageCount}
          onPage={(p) => onPage(p + 1)}
          summary={`Showing ${rows.length} of ${total} active items`}
        />
      )}
    </>
  );
}

/** Aggregated group rows — the table's shape when the Group toggle is on. */
export function WorkloadGroupTable({
  groups,
  isLoading,
}: {
  groups: WorkloadBar[];
  isLoading: boolean;
}) {
  const columns = [
    "Group",
    "Members",
    "Items",
    "Light",
    "Moderate",
    "Heavy",
    "Total Effort",
    "Workload",
  ];

  return (
    <Card className="overflow-hidden rounded-2xl p-0">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[760px] border-collapse">
          <thead>
            <tr className="border-b border-line bg-surface-alt">
              {columns.map((c) => (
                <th
                  key={c}
                  className="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink"
                >
                  {c}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr>
                <td colSpan={columns.length} className="py-10 text-center text-ink-soft">
                  Loading groups...
                </td>
              </tr>
            ) : groups.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="py-10 text-center text-ink-soft">
                  No group-assigned work is active right now.
                </td>
              </tr>
            ) : (
              groups.map((g, idx) => (
                <tr
                  key={g.id}
                  className={`border-b border-line last:border-b-0 ${
                    idx % 2 === 1 ? "bg-surface-alt" : "bg-white"
                  }`}
                >
                  <td className="px-5 py-3.5 text-sm font-semibold text-ink">{g.name}</td>
                  <td className="px-5 py-3.5 text-sm text-ink-soft">{g.member_count ?? 0}</td>
                  <td className="px-5 py-3.5 text-sm text-ink-soft">{g.task_count}</td>
                  <td className="px-5 py-3.5 text-sm text-ink-soft">{g.light}</td>
                  <td className="px-5 py-3.5 text-sm text-ink-soft">{g.moderate}</td>
                  <td className="px-5 py-3.5 text-sm text-ink-soft">{g.heavy}</td>
                  <td className="px-5 py-3.5 text-sm font-bold text-ink">{g.total_effort}</td>
                  <td className="px-5 py-3.5">
                    <LevelBadge level={g.level} />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

function LevelBadge({ level }: { level: "light" | "moderate" | "heavy" }) {
  const tone = level === "heavy" ? "danger" : level === "moderate" ? "warning" : "success";
  return (
    <span
      className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold uppercase ${
        tone === "danger"
          ? "bg-danger-soft text-danger"
          : tone === "warning"
            ? "bg-warning-soft text-warning"
            : "bg-success-soft text-success"
      }`}
    >
      {level}
    </span>
  );
}
