"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth, useIsManager } from "@/core/auth/AuthProvider";
import {
  Button,
  Card,
  FilterIcon,
  SegmentedControl,
  SegmentedItem,
  Select,
} from "@/core/ui";
import { useGroups } from "@/modules/projects/hooks/use-groups";
import { useProjects } from "@/modules/projects/hooks/use-projects";
import {
  useWorkloadAnalysis,
  useWorkloadTasks,
} from "@/modules/projects/hooks/use-workload";
import type { Priority } from "@/modules/projects/types/common";
import type { WorkloadCategory } from "@/modules/projects/types/workload";
import {
  AdjustCapacityModal,
} from "@/modules/projects/components/adjust-capacity-modal";
import { WorkloadChart } from "@/modules/projects/components/workload-chart";
import {
  WorkloadGroupTable,
  WorkloadTaskTable,
} from "@/modules/projects/components/workload-task-table";
import {
  CategoryLegend,
  WorkloadKpiCards,
} from "@/modules/projects/components/workload-shared";

const PER_PAGE = 5;

/**
 * Workload Analysis.
 *
 * One route, two readings of the same data: a manager sees the whole team (and
 * can retune capacity), a member sees only their own work. The split is
 * enforced server-side — this component only decides what to draw.
 */
export function WorkloadAnalysisView() {
  const { isAuthenticated, isLoading } = useAuth();
  const isManager = useIsManager();
  const router = useRouter();

  const [scope, setScope] = useState<"all" | "group">("all");
  const [projectId, setProjectId] = useState("");
  const [category, setCategory] = useState<"" | WorkloadCategory>("");
  const [priority, setPriority] = useState<"" | Priority>("");
  const [groupId, setGroupId] = useState("");
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [capacityOpen, setCapacityOpen] = useState(false);
  const [page, setPage] = useState(1);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) router.push("/login");
  }, [isAuthenticated, isLoading, router]);

  const filters: Record<string, unknown> = {};
  if (projectId) filters.project_id = projectId;
  if (category) filters.category = category;
  if (priority) filters.priority = priority;
  if (groupId) filters.group_id = groupId;

  const analysis = useWorkloadAnalysis(filters);
  const tasks = useWorkloadTasks({ ...filters, page, per_page: PER_PAGE });
  const projectsQuery = useProjects();
  const groupsQuery = useGroups();

  if (isLoading) return <div className="p-8 text-ink-soft">Loading...</div>;
  if (!isAuthenticated) return null;

  const data = analysis.data;
  const bars = scope === "group" ? (data?.groups ?? []) : (data?.members ?? []);

  // Any filter change can shrink the result set past the current page.
  const resetPage = () => setPage(1);

  return (
    <div className="px-4 py-4 sm:px-6 lg:px-8">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-ink sm:text-[32px]">Workload Analysis</h1>
          <p className="mt-2 text-[15px] text-ink-soft">
            {isManager
              ? "Monitor workload distribution and task effort across your team"
              : "Your active tasks and sub-tasks across every project"}
          </p>
        </div>
        {isManager && data?.can_adjust_capacity && (
          <Button
            className="w-full shrink-0 sm:w-auto"
            onClick={() => setCapacityOpen(true)}
          >
            Adjust Capacity
          </Button>
        )}
      </div>

      {/* Toolbar */}
      <div className="mt-6 flex flex-wrap items-center gap-3">
        {isManager && (
          <SegmentedControl className="flex-1 sm:flex-none">
            {(["all", "group"] as const).map((s) => (
              <SegmentedItem
                key={s}
                active={scope === s}
                onClick={() => {
                  setScope(s);
                  resetPage();
                }}
                className="flex-1 capitalize sm:flex-none"
              >
                {s === "all" ? "All" : "Group"}
              </SegmentedItem>
            ))}
          </SegmentedControl>
        )}
        <Button variant="outline" onClick={() => setFiltersOpen((v) => !v)}>
          <FilterIcon className="h-4 w-4" />
          Filters
        </Button>
      </div>

      {filtersOpen && (
        <Card className="mt-3 grid grid-cols-1 gap-4 rounded-2xl p-4 sm:grid-cols-2 lg:grid-cols-4">
          <label className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Project
            <Select
              className="mt-1.5"
              value={projectId}
              onChange={(e) => {
                setProjectId(e.target.value);
                resetPage();
              }}
            >
              <option value="">All projects</option>
              {(projectsQuery.data?.data ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </Select>
          </label>
          <label className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Group
            <Select
              className="mt-1.5"
              value={groupId}
              onChange={(e) => {
                setGroupId(e.target.value);
                resetPage();
              }}
            >
              <option value="">All groups</option>
              {(groupsQuery.data?.data ?? []).map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </Select>
          </label>
          <label className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Category
            <Select
              className="mt-1.5"
              value={category}
              onChange={(e) => {
                setCategory(e.target.value as "" | WorkloadCategory);
                resetPage();
              }}
            >
              <option value="">All categories</option>
              <option value="light">Light (1-3)</option>
              <option value="moderate">Moderate (4-7)</option>
              <option value="heavy">Heavy (8-10)</option>
            </Select>
          </label>
          <label className="text-xs font-bold uppercase tracking-wide text-ink-soft">
            Priority
            <Select
              className="mt-1.5"
              value={priority}
              onChange={(e) => {
                setPriority(e.target.value as "" | Priority);
                resetPage();
              }}
            >
              <option value="">All priorities</option>
              {(["High", "Medium", "Low"] as const).map((p) => (
                <option key={p} value={p}>
                  {p}
                </option>
              ))}
            </Select>
          </label>
        </Card>
      )}

      <div className="mt-6">
        <WorkloadKpiCards
          totals={data?.totals ?? { total: 0, light: 0, moderate: 0, heavy: 0 }}
        />
      </div>

      {/* Chart — the team for a manager, a personal breakdown for a member. */}
      {isManager ? (
        <Card className="mt-6 rounded-2xl p-4 sm:p-5">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 className="text-lg font-bold text-ink">
              {scope === "group" ? "Group Workload" : "Employee Workload"}
            </h2>
            <CategoryLegend />
          </div>
          <div className="mt-4">
            {analysis.isLoading ? (
              <div className="flex h-[240px] items-center justify-center text-sm text-ink-soft sm:h-[300px] lg:h-[340px]">
                Loading workload…
              </div>
            ) : (
              <WorkloadChart
                bars={bars}
                thresholds={data?.thresholds ?? { light: 20, moderate: 40, max: 100 }}
                emptyMessage={
                  scope === "group"
                    ? "No group-assigned work is active right now."
                    : "No active work is assigned right now."
                }
              />
            )}
          </div>
        </Card>
      ) : (
        <MemberWorkloadPanel analysis={data} isLoading={analysis.isLoading} />
      )}

      <div className="mt-6">
        {isManager && scope === "group" ? (
          <WorkloadGroupTable groups={data?.groups ?? []} isLoading={analysis.isLoading} />
        ) : (
          <WorkloadTaskTable
            result={tasks}
            page={page}
            onPage={setPage}
            showAssignee={isManager}
          />
        )}
      </div>

      {isManager && data && (
        <AdjustCapacityModal
          open={capacityOpen}
          onClose={() => setCapacityOpen(false)}
          thresholds={data.thresholds}
          bars={data.members ?? []}
        />
      )}
    </div>
  );
}

/**
 * The member panel: their own effort split as a donut, beside a completion
 * readout. Completion is the one place Done work is counted — it is the point
 * of a completion percentage — and it says so.
 */
function MemberWorkloadPanel({
  analysis,
  isLoading,
}: {
  analysis?: import("@/modules/projects/types/workload").WorkloadAnalysis;
  isLoading: boolean;
}) {
  const me = analysis?.me;
  const completion = analysis?.completion;

  return (
    <Card className="mt-6 grid grid-cols-1 gap-8 rounded-2xl p-4 sm:p-6 lg:grid-cols-2">
      <div>
        <h2 className="text-lg font-bold text-ink">My Workload</h2>
        {isLoading ? (
          <p className="mt-6 text-sm text-ink-soft">Loading…</p>
        ) : (
          <div className="mt-4 flex flex-col items-center gap-6 sm:flex-row sm:items-center sm:gap-8">
            <EffortDonut
              light={me?.light ?? 0}
              moderate={me?.moderate ?? 0}
              heavy={me?.heavy ?? 0}
            />
            <div className="space-y-2 text-center sm:text-left">
              <CategoryLegend />
              <p className="text-sm text-ink-soft">
                Total effort <span className="font-bold text-ink">{me?.total_effort ?? 0}</span>{" "}
                across {me?.task_count ?? 0} active{" "}
                {me?.task_count === 1 ? "item" : "items"}
              </p>
              <p className="text-sm capitalize text-ink-soft">
                Workload level:{" "}
                <span className="font-bold text-ink">{me?.level ?? "light"}</span>
              </p>
            </div>
          </div>
        )}
      </div>

      <div>
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-lg font-bold text-ink">Completed Tasks</h2>
          <span className="text-xl font-bold text-ink">{completion?.percent ?? 0}%</span>
        </div>
        <div className="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-line">
          <div
            className="h-full rounded-full bg-brand transition-[width]"
            style={{ width: `${completion?.percent ?? 0}%` }}
          />
        </div>
        <p className="mt-2 text-sm text-ink-soft">
          {completion?.done ?? 0} of {completion?.total ?? 0} tasks completed
        </p>

        <p className="mt-6 text-sm font-bold text-ink">Task Status Breakdown</p>
        <div className="mt-3 flex flex-wrap gap-x-10 gap-y-4">
          {[
            { label: "To Do", value: completion?.todo ?? 0, className: "text-ink" },
            {
              label: "In Progress",
              value: completion?.in_progress ?? 0,
              className: "text-[#6366f1]",
            },
            { label: "Done", value: completion?.done ?? 0, className: "text-brand" },
          ].map((s) => (
            <div key={s.label}>
              <p className={`text-2xl font-bold ${s.className}`}>{s.value}</p>
              <p className="text-xs text-ink-soft">{s.label}</p>
            </div>
          ))}
        </div>
      </div>
    </Card>
  );
}

/** Effort split by task category, as a ring. Mirrors StatusDonut's approach. */
function EffortDonut({
  light,
  moderate,
  heavy,
  size = 170,
  stroke = 26,
}: {
  light: number;
  moderate: number;
  heavy: number;
  size?: number;
  stroke?: number;
}) {
  const segments = [
    { value: light, color: "#10b981" },
    { value: moderate, color: "#f59e0b" },
    { value: heavy, color: "#ef4444" },
  ];
  const r = (size - stroke) / 2;
  const circumference = 2 * Math.PI * r;
  const total = segments.reduce((s, x) => s + x.value, 0);

  if (total === 0) {
    return (
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label="No active effort">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#e5e7eb" strokeWidth={stroke} />
      </svg>
    );
  }

  let offset = 0;
  const arcs = segments.map((s) => {
    const len = (s.value / total) * circumference;
    const arc = { len, offset, color: s.color };
    offset += len;
    return arc;
  });

  return (
    <svg
      width={size}
      height={size}
      viewBox={`0 0 ${size} ${size}`}
      className="-rotate-90"
      role="img"
      aria-label={`Effort split: ${light} light, ${moderate} moderate, ${heavy} heavy`}
    >
      {arcs.map((a, i) => (
        <circle
          key={i}
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={a.color}
          strokeWidth={stroke}
          strokeDasharray={`${a.len} ${circumference - a.len}`}
          strokeDashoffset={-a.offset}
        />
      ))}
    </svg>
  );
}
