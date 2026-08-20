"use client";

import { useMemo, useState } from "react";
import { AlertTriangle, Eye } from "lucide-react";

import { useAutoOvertimeReview } from "@/modules/hr/hooks/use-overtime";
import { useAttendancePolicy } from "@/modules/hr/hooks/use-rules";
import { Label } from "@/components/ui/label";
import { DataTable, type Column } from "@/core/components/data-table";
import { formatDate } from "@/core/lib/format";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { SoftBadge } from "@/modules/hr/lib/attendance-display";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import type {
  AutoOvertimeReviewItem,
  AutoOvertimeReviewReason,
} from "@/modules/hr/types/leave";

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

const FLAG_LABEL: Record<Exclude<AutoOvertimeReviewReason, "cap">, string> = {
  long_overtime: "Unusually long",
  late_and_overtime: "Late and overtime",
};

function FlagBadges({ row }: { row: AutoOvertimeReviewItem }) {
  const flags = row.reasons.filter(
    (r): r is Exclude<AutoOvertimeReviewReason, "cap"> => r !== "cap",
  );
  if (flags.length === 0) return <span className="text-muted-foreground">—</span>;

  return (
    <span className="inline-flex flex-wrap gap-1">
      {flags.map((f) => (
        <span
          key={f}
          title={
            f === "long_overtime"
              ? "More overtime in one day than your review threshold. Often a clock-out corrected to the wrong time."
              : "This day was both late and overtime. The late minutes are docked AND the overtime is paid at a premium — Art. 88 forbids netting one against the other."
          }
        >
          <SoftBadge tone="warning">{FLAG_LABEL[f]}</SoftBadge>
        </span>
      ))}
    </span>
  );
}

/**
 * Auto-mode overtime that wants a human's attention (FIN-039, extended by item 7).
 *
 * TWO TABLES, NOT ONE, and the split is not cosmetic — the two kinds have OPPOSITE consequences
 * of inaction:
 *
 *   • Held — a cap is withholding these minutes. Doing nothing means the employee is not paid.
 *     Resolving it is the ordinary approve flow on the Overtime Requests table below; an approval
 *     releases the minutes and removes the row, so a second verb here would invent a state nobody
 *     reads. Rendered as a warning, because inaction has a cost.
 *   • Flagged — the day already pays in full, automatically. It is listed only because it looks
 *     unusual: an overrun past the review threshold, or a day that was both late and overtime.
 *     There is nothing to approve. Rendered neutrally, because the correct outcome is often to
 *     read it and move on.
 *
 * Merging them into one table with a status column was the tempting version and it is wrong here:
 * a reader who mistakes a flagged row for a held one sits on wages that were already paid, and one
 * who makes the opposite mistake leaves somebody unpaid.
 *
 * Visible whenever overtime is on auto. It used to hide itself unless a cap was set, which meant
 * the shipped configuration — both caps 0, i.e. UNLIMITED — reviewed nothing at all.
 */
export function AutoOvertimeReview() {
  const { data: policy } = useAttendancePolicy();
  const [period, setPeriod] = useState(() => monthBounds(manilaToday()));

  const auto = policy?.overtime_mode === "auto_after_threshold";

  const { data: rows, isLoading } = useAutoOvertimeReview(
    auto ? period.start : "",
    auto ? period.end : "",
  );

  const held = useMemo(() => (rows ?? []).filter((r) => r.kind === "held"), [rows]);
  const flagged = useMemo(() => (rows ?? []).filter((r) => r.kind === "flagged"), [rows]);

  // Nothing is paid from the clock alone in the other modes, so every overtime minute was already
  // authorised by a person and this panel would only be noise.
  if (!auto) return null;

  const employee: Column<AutoOvertimeReviewItem> = {
    header: "Employee",
    cell: (r) => (
      <div>
        <span className="font-medium text-foreground">{r.name ?? "—"}</span>
        <span className="block text-xs text-muted-foreground">{r.employee_no}</span>
      </div>
    ),
  };
  const date: Column<AutoOvertimeReviewItem> = {
    header: "Date",
    cell: (r) => formatDate(r.work_date),
  };
  // Shown on BOTH tables. A held day that was also late would otherwise lose that fact entirely:
  // the held columns are all minutes, and "arrived late and still earned overtime" is exactly the
  // context that should reach whoever is deciding whether to approve it.
  const why: Column<AutoOvertimeReviewItem> = {
    header: "Why",
    cell: (r) => <FlagBadges row={r} />,
  };
  const clocked: Column<AutoOvertimeReviewItem> = {
    header: "Clocked past shift",
    cell: (r) => <span className="text-foreground">{hm(r.raw_minutes)}</span>,
  };

  const heldColumns: Column<AutoOvertimeReviewItem>[] = [
    employee,
    date,
    clocked,
    {
      header: "Paying automatically",
      cell: (r) => <span className="text-success">{hm(r.paid_minutes)}</span>,
    },
    {
      header: "Held for approval",
      cell: (r) => <span className="font-semibold text-warning">{hm(r.excess_minutes)}</span>,
    },
    why,
  ];

  const flaggedColumns: Column<AutoOvertimeReviewItem>[] = [
    employee,
    date,
    clocked,
    {
      header: "Being paid",
      cell: (r) => <span className="font-semibold text-success">{hm(r.paid_minutes)}</span>,
    },
    {
      header: "Late that day",
      cell: (r) =>
        r.late_minutes > 0 ? (
          <span className="text-danger">{hm(r.late_minutes)}</span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    why,
  ];

  const search = {
    getSearchText: (r: AutoOvertimeReviewItem) =>
      `${r.name ?? ""} ${r.employee_no ?? ""} ${r.work_date}`,
    searchPlaceholder: "Search employee, date…",
  };

  const totalHeld = held.reduce((sum, r) => sum + r.excess_minutes, 0);
  const totalFlagged = flagged.reduce((sum, r) => sum + r.paid_minutes, 0);

  return (
    <div className="mt-10">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-h3 font-bold text-foreground">Overtime to review</h2>
          <p className="mt-1 max-w-2xl text-caption text-muted-foreground">
            Overtime the time clock paid on its own, in the period below. Nothing here needs doing
            unless it looks wrong — except the held section, where doing nothing leaves someone
            unpaid.
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

      {isLoading ? (
        <p className="mt-4 text-sm text-muted-foreground">Checking attendance…</p>
      ) : (
        <>
          {/* Held — inaction costs someone their wages, so it leads and it is a warning. */}
          {held.length > 0 && (
            <div className="mt-6">
              <h3 className="flex items-center gap-2 text-base font-bold text-foreground">
                <AlertTriangle className="size-5 text-warning" aria-hidden />
                Waiting on your approval
              </h3>
              <p className="mt-1 max-w-2xl text-caption text-muted-foreground">
                Overtime that goes past your auto-pay cap. The capped part is paid on the run;{" "}
                <strong>the held part is not paid until an overtime request for that date is
                approved</strong> below. A large overrun is often a forgotten clock-out — check
                before approving.
              </p>
              <div className="mt-3">
                <DataTable
                  columns={heldColumns}
                  rows={held}
                  rowKey={(r) => `${r.employee_id}-${r.work_date}`}
                  {...search}
                  empty="Nothing held back in this period."
                />
                <p className="mt-2 text-caption text-muted-foreground">
                  <strong>{hm(totalHeld)}</strong> of overtime is currently unpaid across{" "}
                  {held.length} {held.length === 1 ? "day" : "days"}.
                </p>
              </div>
            </div>
          )}

          {/* Flagged — already paid. Neutral on purpose: reading it and moving on is a valid outcome. */}
          {flagged.length > 0 && (
            <div className="mt-8">
              <h3 className="flex items-center gap-2 text-base font-bold text-foreground">
                <Eye className="size-5 text-muted-foreground" aria-hidden />
                Paid automatically — worth a look
              </h3>
              <p className="mt-1 max-w-2xl text-caption text-muted-foreground">
                These days are <strong>already being paid in full</strong>; nothing is being held
                back and there is nothing to approve. They are listed because the amount is unusual
                or the day was also late. If one is wrong, correct the attendance record — the
                overtime is recalculated from the punches.
              </p>
              <div className="mt-3">
                <DataTable
                  columns={flaggedColumns}
                  rows={flagged}
                  rowKey={(r) => `${r.employee_id}-${r.work_date}`}
                  {...search}
                  empty="Nothing flagged in this period."
                />
                <p className="mt-2 text-caption text-muted-foreground">
                  <strong>{hm(totalFlagged)}</strong> of overtime is paying across {flagged.length}{" "}
                  flagged {flagged.length === 1 ? "day" : "days"}.
                </p>
              </div>
            </div>
          )}

          {held.length === 0 && flagged.length === 0 && (
            <p className="mt-4 text-sm text-muted-foreground">
              No automatic overtime needs reviewing in this period.
            </p>
          )}
        </>
      )}
    </div>
  );
}
