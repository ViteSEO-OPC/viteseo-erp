"use client";

import { AlertTriangle, RefreshCw } from "lucide-react";

import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { formatDateTime } from "@/core/lib/format";
import { notify as toast } from "@/core/lib/notify";
import {
  useManagementGeneration,
  useRegenerateManagementAttendance,
} from "@/modules/hr/hooks/use-attendance";

/**
 * Management staff never punch, so the platform writes their attendance instead — on a nightly
 * job that needs `schedule:run` on a system cron. When that cron dies the only symptom is rows
 * quietly never appearing, which is invisible for weeks. This says when it last ran, and lets HR
 * run it now.
 */
export function ManagementGenerationCard() {
  const { data } = useManagementGeneration();
  const regenerate = useRegenerateManagementAttendance();

  // Nothing to generate, nothing to say: no exempt staff, or the company rule is off (in
  // which case management is on the ordinary time clock and there is no nightly at all).
  if (!data || !data.enabled || data.employees === 0) return null;

  const last = data.last_run;

  async function onRegenerate() {
    try {
      const result = await regenerate.mutateAsync({});
      const { created, updated } = result.stats;
      toast.success(
        created + updated === 0
          ? "Already up to date — nothing to write."
          : `Wrote ${created + updated} day${created + updated === 1 ? "" : "s"} of management attendance.`,
      );
    } catch {
      toast.error("Could not generate management attendance.");
    }
  }

  return (
    <div
      className={cn(
        "flex flex-col gap-4 rounded-card border p-6 sm:flex-row sm:items-center sm:justify-between",
        data.is_stale ? "border-warning/40 bg-warning/5" : "border-border bg-card",
      )}
    >
      <div className="flex items-start gap-3">
        {data.is_stale && <AlertTriangle className="mt-0.5 size-5 shrink-0 text-warning" />}
        <div>
          <p className="text-sm font-semibold text-foreground">
            Management attendance ({data.employees} exempt{" "}
            {data.employees === 1 ? "employee" : "employees"})
          </p>
          <p className="mt-0.5 text-sm text-muted-foreground">
            {last === null
              ? "Never generated on this system. Their days are shown as present, but no records exist yet."
              : data.is_stale
                ? `Last generated ${formatDateTime(last.ran_at)} — the nightly job has missed a run.`
                : `Last generated ${formatDateTime(last.ran_at)} · ${last.created} added, ${last.updated} refreshed${
                    last.triggered_by ? ` · by ${last.triggered_by}` : ""
                  }`}
          </p>
          {/* Days the job could not write — an unconfigured shift window, most often. */}
          {last?.notes?.length ? (
            <p className="mt-1 text-sm text-warning">{last.notes[0]}</p>
          ) : null}
        </div>
      </div>

      <Button
        variant="outline"
        onClick={onRegenerate}
        disabled={regenerate.isPending}
        className="shrink-0"
      >
        <RefreshCw className={cn("size-4", regenerate.isPending && "animate-spin")} />
        {regenerate.isPending ? "Generating…" : "Generate now"}
      </Button>
    </div>
  );
}
