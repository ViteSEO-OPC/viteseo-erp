"use client";

import { Check, Circle, Loader2 } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

export type ImportStageState = "pending" | "active" | "done";

export type ImportStage = {
  id: string;
  label: string;
  state: ImportStageState;
  /** Real figure produced by that stage (e.g. "250 rows"). Omitted until the stage has run. */
  detail?: string;
};

type Props = {
  stages: ImportStage[];
};

/**
 * Import progress, reported from real signals only.
 *
 * A note on what this can and cannot show: `POST /leads/import` is a single synchronous request —
 * LeadImportService reads the workbook, maps the header, validates, de-duplicates and (on commit)
 * inserts, all before it responds. The browser therefore observes exactly two measurable things: the
 * upload transfer, and whether the response has arrived. Everything the server does in between is one
 * opaque step, so it is shown as one active stage rather than a timed sequence of nine.
 *
 * Once the response lands, the stages it evidently performed are marked done and annotated with the
 * counts the response actually returned. Nothing here is on a timer and no stage advances on anything
 * other than a real event, so the list can never claim progress that hasn't happened.
 */
export function ImportStages({ stages }: Props) {
  return (
    <ol className="flex flex-col gap-1.5" aria-label="Import progress">
      {stages.map((stage) => (
        <li key={stage.id} className="flex items-center gap-2.5 text-sm">
          <span className="flex h-4 w-4 shrink-0 items-center justify-center" aria-hidden>
            {stage.state === "done" ? (
              <Check className="h-4 w-4 text-[var(--color-success-emerald)]" />
            ) : stage.state === "active" ? (
              <Loader2 className="h-3.5 w-3.5 animate-spin text-teal-500" />
            ) : (
              <Circle className="h-2 w-2 text-muted-foreground/50" />
            )}
          </span>

          <span
            className={cn(
              "flex-1 truncate",
              stage.state === "done" && "text-foreground",
              stage.state === "active" && "font-medium text-foreground",
              stage.state === "pending" && "text-muted-foreground",
            )}
          >
            {stage.label}
          </span>

          {stage.detail ? (
            <span className="shrink-0 text-xs text-muted-foreground">{stage.detail}</span>
          ) : null}

          {/* Screen readers get the state as words — the icon alone is decorative. */}
          <span className="sr-only">
            {stage.state === "done" ? "completed" : stage.state === "active" ? "in progress" : "pending"}
          </span>
        </li>
      ))}
    </ol>
  );
}
