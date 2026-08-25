"use client";

import { useMemo, useState } from "react";
import { AlertTriangle } from "lucide-react";

import { Label } from "@/components/ui/label";
import { DataTable, type Column } from "@/core/components/data-table";
import { formatDate, formatTimeManila } from "@/core/lib/format";
import { useOpenShifts } from "@/modules/hr/hooks/use-attendance";
import { useAttendancePolicy } from "@/modules/hr/hooks/use-rules";
import { SoftBadge, type Tone } from "@/modules/hr/lib/attendance-display";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { SimpleSelect } from "@/modules/hr/ui/select";
import type { OpenShiftRow } from "@/modules/hr/types/attendance";

/** Default look-back, matching the server's own default window. */
const LOOKBACK_DAYS = 30;

function daysAgo(days: number): string {
  const d = new Date(`${manilaToday()}T00:00:00`);
  d.setDate(d.getDate() - days);
  return d.toLocaleDateString("en-CA");
}

/** Why the row is here, in HR's words rather than the engine's. */
function describe(row: OpenShiftRow): { label: string; tone: Tone; detail: string } {
  if (row.approved_overtime_hours !== null) {
    return {
      label: "Approved OT unpaid",
      tone: "danger",
      detail: `${row.approved_overtime_hours}h of overtime was approved, but with no time out the day pays none. Confirm when they actually left.`,
    };
  }

  switch (row.reason) {
    case "rest_day":
      return {
        label: "Rest day",
        tone: "danger",
        detail:
          "They clocked in on a day they were not scheduled. There is no shift end to close this against, so the day pays nothing until you set the times.",
      };
    case "no_schedule":
      return {
        label: "No schedule",
        tone: "danger",
        detail:
          "This employee has no work schedule assigned, so there is nothing to close the shift against. Assign one under Company → Settings, then correct the day.",
      };
    case "in_after_end":
      return {
        label: "In after shift end",
        tone: "danger",
        detail:
          "The time in falls at or after their own scheduled end, which cannot be a normal shift. Usually a wrong date or a mis-set clock — check before correcting.",
      };
    case "auto_closed":
    default:
      return {
        label: "Auto-closed",
        tone: "muted",
        detail:
          "Paid as a full normal shift ending at their scheduled time, with no overtime. The pay is already right — closing the record is tidying up.",
      };
  }
}

/** Open shifts HR should look at — the stale-attendance review queue (item 6). */
export function OpenShiftQueue({ onFix }: { onFix: (row: OpenShiftRow) => void }) {
  const { data: policy } = useAttendancePolicy();
  const [from, setFrom] = useState(() => daysAgo(LOOKBACK_DAYS));
  const [to, setTo] = useState(() => manilaToday());
  const [filter, setFilter] = useState<"needs_fix" | "auto_closed" | "all">("needs_fix");

  const { data: rows, isLoading, isError } = useOpenShifts(from, to);

  const all = useMemo(() => rows ?? [], [rows]);
  const needsFix = useMemo(() => all.filter((r) => r.status === "needs_fix"), [all]);
  const shown = useMemo(
    () => (filter === "all" ? all : all.filter((r) => r.status === filter)),
    [all, filter],
  );

  const allowanceHours = ((policy?.missing_timeout_allowance_minutes ?? 180) / 60).toFixed(
    (policy?.missing_timeout_allowance_minutes ?? 180) % 60 === 0 ? 0 : 1,
  );

  const columns: Column<OpenShiftRow>[] = [
    {
      header: "Employee",
      cell: (r) => (
        <div>
          <span className="font-semibold text-foreground">{r.name ?? "—"}</span>
          <span className="block text-xs text-muted-foreground">{r.department ?? "—"}</span>
        </div>
      ),
    },
    { header: "Date", cell: (r) => formatDate(r.work_date) },
    { header: "Time In", cell: (r) => formatTimeManila(r.time_in) },
    {
      header: "Scheduled",
      cell: (r) =>
        r.scheduled_start && r.scheduled_end ? `${r.scheduled_start}–${r.scheduled_end}` : "—",
    },
    {
      header: "Paid until",
      cell: (r) =>
        r.resolved_time_out ? (
          <span>
            {r.resolved_time_out}
            {r.resolved_next_day && (
              <span className="ml-1 text-xs text-muted-foreground">+1d</span>
            )}
          </span>
        ) : (
          // Not "00:00" and not blank: pay genuinely refused to place an end here, and that
          // refusal is the reason the row is in the queue at all.
          <span className="text-danger">Unpaid</span>
        ),
    },
    {
      // The OTHER clock. The allowance decides when pay stops waiting; this decides whether the
      // employee can still close the shift from their own panel. Once they can't, the correction
      // has to come from HR, which is the difference between a nudge and a task.
      header: "Open for",
      cell: (r) => (
        <span>
          {Math.round(r.hours_open)}h
          {!r.employee_can_fix && (
            <span
              className="ml-1 text-xs text-muted-foreground"
              title="Past the 18-hour limit — the employee can no longer close this themselves"
            >
              · HR only
            </span>
          )}
        </span>
      ),
    },
    {
      header: "Why",
      cell: (r) => {
        const d = describe(r);
        // The explanation rides on the badge rather than a column of its own: it is a
        // paragraph per row, and a table that wide stops being scannable.
        return (
          <span title={d.detail}>
            <SoftBadge tone={d.tone}>{d.label}</SoftBadge>
          </span>
        );
      },
    },
  ];

  // Nothing needing a fix and no filter applied = nothing worth taking up space for, the same
  // rule the field time-in queue above it follows.
  if (filter === "needs_fix" && !isLoading && !isError && needsFix.length === 0) return null;

  return (
    <div className="rounded-card border border-border bg-card p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-h3 font-bold text-foreground">
            {needsFix.length > 0 && <AlertTriangle className="size-4 text-danger" />}
            Open shifts
            {needsFix.length > 0 && (
              <SoftBadge tone="danger">{needsFix.length} need fixing</SoftBadge>
            )}
          </h2>
          <p className="max-w-3xl text-sm text-muted-foreground">
            People who timed in and never timed out. A forgotten punch on a normal workday is
            paid automatically as a full shift ending at their scheduled time, {allowanceHours}h
            after that shift ends — those show as <em>Auto-closed</em> and need nothing from you.
            The rest could not be worked out at all and are being paid wrongly until you correct
            them. Click a row to fix it.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <div>
            <Label className="text-xs text-muted-foreground">From</Label>
            <DatePicker value={from} onChange={setFrom} max={to} className="w-40" />
          </div>
          <div>
            <Label className="text-xs text-muted-foreground">To</Label>
            <DatePicker value={to} onChange={setTo} max={manilaToday()} className="w-40" />
          </div>
        </div>
      </div>

      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading open shifts…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load open shifts.</p>
        ) : (
          <DataTable
            columns={columns}
            rows={shown}
            rowKey={(r) => r.attendance_id}
            onRowClick={onFix}
            // Our own filter rather than the auto one DataTable derives from `status`: that would
            // read "Needs Fix / Auto Closed" off the raw values and, because it sees only the rows
            // we already filtered, would offer whichever single option was left.
            toolbar={
              <SimpleSelect
                className="h-9 w-44"
                value={filter}
                onValueChange={(v) => setFilter(v as typeof filter)}
                options={[
                  { value: "needs_fix", label: "Needs fixing" },
                  { value: "auto_closed", label: "Auto-closed" },
                  { value: "all", label: "All open shifts" },
                ]}
              />
            }
            // The default indexes every field, which would let "closed" match the internal
            // `auto_closed` on rows whose badge says something else entirely.
            getSearchText={(r) => [r.name, r.department, r.work_date].filter(Boolean).join(" ")}
            searchPlaceholder="Search people…"
            empty={
              filter === "auto_closed"
                ? "No shifts were auto-closed in this range."
                : "No open shifts in this range."
            }
          />
        )}
      </div>
    </div>
  );
}
