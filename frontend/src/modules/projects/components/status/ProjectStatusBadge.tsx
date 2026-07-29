"use client";

import { Badge, type BadgeTone } from "@/core/ui";
import type { Project } from "../../types";
import type { StatusCounts } from "./StatusDonut";

export type ProjectStatusLabel =
  | "Done"
  | "Completed"
  | "In Progress"
  | "Not Started";

/**
 * A project's display status — the single derivation every surface shares
 * (detail header, workspace cards, dashboard cards).
 *
 * "Done" is the explicit project state set by the PM; everything else is
 * inferred from its task counts. A project with no tasks reads "Not Started"
 * rather than "In Progress", and one whose tasks are all complete reads
 * "Completed" without the PM having closed it yet.
 */
export function projectStatusOf(
  project: Pick<Project, "status"> | null | undefined,
  counts: StatusCounts,
): ProjectStatusLabel {
  if (project?.status === "done") return "Done";

  const total = counts.todo + counts.in_progress + counts.done;
  if (total === 0) return "Not Started";
  if (counts.done === total) return "Completed";
  if (counts.in_progress > 0 || counts.done > 0) return "In Progress";
  return "Not Started";
}

/** One tone per label, so a status reads the same colour on every screen. */
export const PROJECT_STATUS_TONE: Record<ProjectStatusLabel, BadgeTone> = {
  Done: "success",
  Completed: "success",
  "In Progress": "accent",
  "Not Started": "neutral",
};

/** The project status as a tone-matched badge. */
export function ProjectStatusBadge({
  project,
  counts,
  className,
}: {
  project: Pick<Project, "status"> | null | undefined;
  counts: StatusCounts;
  className?: string;
}): React.ReactElement {
  const status = projectStatusOf(project, counts);

  return (
    <Badge tone={PROJECT_STATUS_TONE[status]} className={className}>
      {status}
    </Badge>
  );
}
