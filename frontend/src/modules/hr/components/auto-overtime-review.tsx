"use client";

import { useState } from "react";
import { AlertTriangle } from "lucide-react";

import { useAutoOvertimeReview } from "@/modules/hr/hooks/use-overtime";
import { useAttendancePolicy } from "@/modules/hr/hooks/use-rules";
import { Label } from "@/components/ui/label";
import { DataTable, type Column } from "@/core/components/data-table";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import type { AutoOvertimeReviewItem } from "@/modules/hr/types/leave";

/** Minutes as "6h 30m" — the figures here are hours-scale, so bare minutes read badly. */
function hm(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m}m`;
  return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

/** The first and last day of the month a date falls in — the default period to review. */
function monthBounds(date: string): { start: string; end: string } {
  const [y, m] = date.split("-").map(Number);
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
  return {
    start: `${date.slice(0, 7)}-01`,
    end: `${date.slice(0, 7)}-${String(last).padStart(2, "0")}`,
  };
}

/**
 * Auto-mode overtime the policy caps held back, awaiting an approval (FIN-039).
 *
 * WHAT THIS IS NOT: a rejection list. Every row is overtime the employee actually clocked, which the
 * caps stopped from paying automatically. Approving an overtime request for that date pays it in
 * full; leaving it here means it does not get paid. So the panel has to make the consequence of
 * inaction visible, which is why it renders as a warning rather than a neutral table.
 *
 * There is no action button of its own, deliberately. The resolution is the ordinary approve flow on
 * the Overtime Requests table below — an approval releases the minutes AND removes the row, so a
 * second verb here would either duplicate that or invent a state nobody reads.
 *
 * Hidden entirely unless the company is in auto mode with a cap set, since that is the only
 * configuration where anything can be held back. Both caps default to 0 (uncapped).
 */
export function AutoOvertimeReview() {
  const { data: policy } = useAttendancePolicy();
  const [period, setPeriod] = useState(() => monthBounds(manilaToday()));

  const capped =
    policy?.overtime_mode === "auto_after_threshold" &&
    ((policy?.auto_overtime_daily_cap_minutes ?? 0) > 0 ||
      (policy?.auto_overtime_period_cap_minutes ?? 0) > 0);

  const { data: rows, isLoading } = useAutoOvertimeReview(
    capped ? period.start : "",
    capped ? period.end : "",
  );

  // Nothing can be held back in any other configuration, so the panel would only be noise.
  if (!capped) return null;

  const columns: Column<AutoOvertimeReviewItem>[] = [
    {
      header: "Employee",
      cell: (r) => (
        <div>
          <span className="font-medium text-foreground">{r.name ?? "—"}</span>
          <span className="block text-xs text-muted-foreground">{r.employee_no}</span>
        </div>
      ),
    },
    { header: "Date", cell: (r) => r.work_date },
    {
      header: "Clocked past shift",
      cell: (r) => <span className="text-foreground">{hm(r.raw_minutes)}</span>,
    },
    {
      header: "Paying automatically",
      cell: (r) => <span className="text-success">{hm(r.paid_minutes)}</span>,
    },
    {
      header: "Held for approval",
      cell: (r) => <span className="font-semibold text-warning">{hm(r.excess_minutes)}</span>,
    },
  ];

  const total = (rows ?? []).reduce((sum, r) => sum + r.excess_minutes, 0);

  return (
    <div className="mt-10">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-h3 font-bold text-foreground">
            <AlertTriangle className="size-5 text-warning" aria-hidden />
            Overtime awaiting approval
          </h2>
          <p className="mt-1 max-w-2xl text-caption text-muted-foreground">
            Overtime these employees clocked that goes past your auto-pay cap. The capped part is
            paid on the run; <strong>the held part is not paid until an overtime request for that
            date is approved</strong> below. A large overrun is often a forgotten clock-out — check
            before approving.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
          <div className="flex items-center">
            <Label htmlFor="ot-review-start" className="mr-2">
              From
            </Label>
            <DatePicker
              id="ot-review-start"
              value={period.start}
              onChange={(v) => setPeriod((p) => ({ ...p, start: v ?? p.start }))}
              className="w-40"
            />
          </div>
          <div className="flex items-center">
            <Label htmlFor="ot-review-end" className="mr-2">
              To
            </Label>
            <DatePicker
              id="ot-review-end"
              value={period.end}
              onChange={(v) => setPeriod((p) => ({ ...p, end: v ?? p.end }))}
              className="w-40"
            />
          </div>
        </div>
      </div>

      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Checking attendance…</p>
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={rows ?? []}
              rowKey={(r) => `${r.employee_id}-${r.work_date}`}
              getSearchText={(r) => `${r.name ?? ""} ${r.employee_no ?? ""} ${r.work_date}`}
              searchPlaceholder="Search employee, date…"
              empty="Nothing held back in this period."
            />
            {total > 0 && (
              <p className="mt-2 text-caption text-muted-foreground">
                <strong>{hm(total)}</strong> of overtime is currently unpaid across{" "}
                {(rows ?? []).length} {(rows ?? []).length === 1 ? "day" : "days"}.
              </p>
            )}
          </>
        )}
      </div>
    </div>
  );
}
