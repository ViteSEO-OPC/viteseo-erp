"use client";

import { Badge } from "@/core/ui";
import {
  useGroupWorkload,
  useUserWorkload,
} from "@/modules/projects/hooks/use-workload";
import type { WorkloadLevel } from "@/modules/projects/types/workload";

/**
 * Compact "how loaded is this person / group" pills for list rows.
 *
 * These lived in `tasks/task-shared` because the task modal's assignment panel
 * was the first thing to show a workload reading. But the Team roster and the
 * Groups page render them too, which had those domains importing from `tasks/`
 * for something that has nothing to do with a task. Three domains use them, so
 * by this folder's own rule they belong here.
 *
 * The task modal's larger `WorkloadCard` / `WorkloadIndicator` stayed behind:
 * those are part of the assignment flow and only that flow uses them. They read
 * `WORKLOAD_META` from here so the tone and wording stay in one place.
 *
 * Advisory only — nothing here blocks an assignment.
 */

export const WORKLOAD_META: Record<
  WorkloadLevel,
  { tone: "success" | "warning" | "danger"; label: string; description: string }
> = {
  light: {
    tone: "success",
    label: "Light",
    description: "Light workload — can take on higher-effort tasks.",
  },
  moderate: {
    tone: "warning",
    label: "Moderate",
    description:
      "Balanced workload — can still take on medium- to high-effort tasks.",
  },
  heavy: {
    tone: "danger",
    label: "Heavy",
    description:
      "Heavy workload — review current assignments before delegating more.",
  },
};

/**
 * Compact group workload pill (score coloured by level) for list rows such as
 * the Groups page. Renders nothing until loaded.
 */
export function GroupWorkloadBadge({ groupId }: { groupId: string }) {
  const { data } = useGroupWorkload(groupId);
  if (!data) return null;
  const meta = WORKLOAD_META[data.level];
  return (
    <Badge tone={meta.tone}>
      <span title={`Total active effort: ${data.effort_score} (${meta.label})`}>
        {data.effort_score} effort
      </span>
    </Badge>
  );
}

/**
 * Compact member workload cell (score + level badge) for directory lists such
 * as the Team page. Fetches on demand; advisory only.
 */
export function UserWorkloadBadge({ userId }: { userId: number }) {
  const { data, isLoading, isError } = useUserWorkload(userId);
  if (isLoading) return <span className="text-sm text-ink-faint">…</span>;
  if (isError || !data) return <span className="text-sm text-ink-soft">—</span>;
  const meta = WORKLOAD_META[data.level];
  return (
    <span className="flex items-center gap-2">
      <span className="text-sm font-bold text-ink">{data.effort_score}</span>
      <Badge tone={meta.tone}>{meta.label}</Badge>
    </span>
  );
}
