"use client";

import { Fragment } from "react";
import { ChevronDown } from "lucide-react";

import { cn } from "@/lib/utils";
import type {
  AttendanceDayStatus,
  AttendanceDtrRow,
  AttendanceLeaveRow,
  AttendanceReportDay,
  AttendanceSummaryRow,
} from "@/modules/hr/types/reports";

/**
 * The three attendance-report tables and the day grid they expand into.
 *
 * They share one shell (ExpandableTable) because they answer the same question at three depths —
 * totals, punches, leave — and a reader moving between tabs should not have to relearn the table.
 */

// ------------------------------------------------------------------ day status

/** Every status a day can read as, with the colour and wording it gets everywhere on this screen. */
const DAY_STATUS: Record<AttendanceDayStatus, { label: string; chip: string; dot: string }> = {
  present: { label: "Present", chip: "bg-success-subtle text-success", dot: "bg-success" },
  late: { label: "Late", chip: "bg-warning-subtle text-warning", dot: "bg-warning" },
  absent: { label: "Absent", chip: "bg-danger-subtle text-danger", dot: "bg-danger" },
  on_leave: { label: "On leave", chip: "bg-info-subtle text-info", dot: "bg-info" },
  half_day: {
    label: "Half day",
    chip: "bg-severity-orange-subtle text-severity-orange",
    dot: "bg-severity-orange",
  },
  holiday: { label: "Holiday", chip: "bg-violet-subtle text-violet", dot: "bg-violet" },
  rest_day: { label: "Rest day", chip: "bg-muted text-muted-foreground", dot: "bg-muted-foreground" },
  not_employed: {
    label: "Not employed",
    chip: "bg-muted text-muted-foreground/60",
    dot: "bg-muted-foreground",
  },
  upcoming: { label: "Upcoming", chip: "bg-muted text-muted-foreground/60", dot: "bg-muted-foreground" },
};

/** The four statuses worth a legend — the rest are days nobody was expected in. */
const LEGEND: AttendanceDayStatus[] = ["present", "late", "absent", "on_leave"];

export function AttendanceLegend() {
  return (
    <div className="flex flex-wrap items-center gap-4">
      {LEGEND.map((status) => (
        <span key={status} className="flex items-center gap-1.5 text-caption text-muted-foreground">
          <span className={cn("size-2 rounded-full", DAY_STATUS[status].dot)} />
          {DAY_STATUS[status].label}
        </span>
      ))}
    </div>
  );
}

/** The one-line description a day's tooltip carries: what it was, and when they clocked in/out. */
function dayTitle(day: AttendanceReportDay): string {
  const parts = [`${day.date} (${day.weekday}) — ${DAY_STATUS[day.status].label}`];
  if (day.leave_type && day.status === "on_leave") parts.push(day.leave_type);
  if (day.time_in) parts.push(`In ${day.time_in}${day.time_out ? ` · Out ${day.time_out}` : ""}`);
  if (day.hours > 0) parts.push(`${day.hours.toFixed(2)} h`);
  if (day.note) parts.push(day.note);
  return parts.join(" · ");
}

/** The month as a strip of numbered day squares, coloured by status. */
export function DayGrid({ days }: { days: AttendanceReportDay[] }) {
  return (
    <div className="flex flex-wrap gap-1">
      {days.map((day) => (
        <span
          key={day.date}
          title={dayTitle(day)}
          className={cn(
            "flex size-8 items-center justify-center rounded-md text-xs font-bold",
            DAY_STATUS[day.status].chip,
          )}
        >
          {day.day}
        </span>
      ))}
    </div>
  );
}

// -------------------------------------------------------------------- shell

interface ExpandColumn<T> {
  header: string;
  cell: (row: T) => React.ReactNode;
  /** Fixed width for the numeric columns, so the three tabs' tables line up. */
  className?: string;
}

interface ExpandableRow {
  employee_id: number;
  name: string;
  department: string | null;
}

/**
 * Employee rows that open into a per-employee detail panel.
 *
 * The whole row is clickable, because that is what the screen tells the reader to do — but the
 * name is also a real `<button>`, so the breakdown is reachable from the keyboard without giving
 * a `<tr>` a button role and breaking the table for a screen reader.
 */
function ExpandableTable<T extends ExpandableRow>({
  rows,
  columns,
  detail,
  expanded,
  onToggle,
  empty,
}: {
  rows: T[];
  columns: ExpandColumn<T>[];
  detail: (row: T) => React.ReactNode;
  expanded: number | null;
  onToggle: (employeeId: number) => void;
  empty: string;
}) {
  if (rows.length === 0) {
    return <p className="px-6 py-12 text-center text-sm text-muted-foreground">{empty}</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-left">
        <thead>
          <tr className="bg-muted/40">
            <th className="px-6 py-2.5 text-label font-bold uppercase tracking-wide text-muted-foreground">
              Employee
            </th>
            {columns.map((col) => (
              <th
                key={col.header}
                className={cn(
                  "px-3 py-2.5 text-center text-label font-bold uppercase tracking-wide text-muted-foreground",
                  col.className,
                )}
              >
                {col.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const isOpen = expanded === row.employee_id;

            return (
              <Fragment key={row.employee_id}>
                <tr
                  onClick={() => onToggle(row.employee_id)}
                  className={cn(
                    "cursor-pointer border-t border-border transition-colors",
                    isOpen ? "bg-brand-subtle/50" : "hover:bg-muted/40",
                  )}
                >
                  <td className="px-6 py-3">
                    <button
                      type="button"
                      // Toggle here too (so the keyboard works), but stop the click bubbling —
                      // otherwise the row's own handler toggles a second time and it never opens.
                      onClick={(e) => {
                        e.stopPropagation();
                        onToggle(row.employee_id);
                      }}
                      aria-expanded={isOpen}
                      className="flex items-center gap-2 text-left outline-none focus-visible:underline"
                    >
                      <ChevronDown
                        className={cn(
                          "size-4 shrink-0 text-muted-foreground transition-transform",
                          isOpen && "rotate-180",
                        )}
                      />
                      <span className="flex flex-col">
                        <span className="font-semibold text-foreground">{row.name}</span>
                        <span className="text-caption text-muted-foreground">
                          {row.department ?? "No department"}
                        </span>
                      </span>
                    </button>
                  </td>
                  {columns.map((col) => (
                    <td
                      key={col.header}
                      className={cn("px-3 py-3 text-center text-sm text-foreground", col.className)}
                    >
                      {col.cell(row)}
                    </td>
                  ))}
                </tr>
                {isOpen && (
                  <tr className="bg-brand-subtle/50">
                    <td colSpan={columns.length + 1} className="px-6 pb-5 pt-1">
                      {detail(row)}
                    </td>
                  </tr>
                )}
              </Fragment>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function DetailHeading({ children }: { children: React.ReactNode }) {
  return <p className="mb-3 text-caption font-medium text-muted-foreground">{children}</p>;
}

// ---------------------------------------------------------- monthly summary

/** 95%+ reads as met, 85–94% as slipping, below that as a problem worth a conversation. */
function rateTone(rate: number): string {
  if (rate >= 95) return "bg-success-subtle text-success";
  if (rate >= 85) return "bg-warning-subtle text-warning";
  return "bg-danger-subtle text-danger";
}

export function AttendanceSummaryTable({
  rows,
  expanded,
  onToggle,
  empty,
}: {
  rows: AttendanceSummaryRow[];
  expanded: number | null;
  onToggle: (employeeId: number) => void;
  empty: string;
}) {
  return (
    <ExpandableTable
      rows={rows}
      expanded={expanded}
      onToggle={onToggle}
      empty={empty}
      columns={[
        { header: "Present", className: "w-24", cell: (r) => r.present },
        { header: "Late", className: "w-20", cell: (r) => r.late },
        { header: "Absent", className: "w-20", cell: (r) => r.absent },
        { header: "On leave", className: "w-24", cell: (r) => r.on_leave },
        {
          header: "Attendance rate",
          className: "w-36",
          cell: (r) =>
            r.rate === null ? (
              <span className="text-muted-foreground" title="No days to measure in this period.">
                —
              </span>
            ) : (
              <span
                title={`${r.present + r.late} of ${r.expected_days} days attended`}
                className={cn(
                  "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold",
                  rateTone(r.rate),
                )}
              >
                {r.rate}%
              </span>
            ),
        },
      ]}
      detail={(row) => (
        <>
          <DetailHeading>Daily record — {row.name}</DetailHeading>
          <DayGrid days={row.days} />
        </>
      )}
    />
  );
}

// ------------------------------------------------------------ daily time record

/** Days worth printing in a time record: one that was punched, or one that was expected and wasn't. */
function loggedDays(days: AttendanceReportDay[]): AttendanceReportDay[] {
  return days.filter(
    (d) => d.time_in !== null || d.status === "absent" || d.status === "on_leave" || d.status === "half_day",
  );
}

export function AttendanceDtrTable({
  rows,
  expanded,
  onToggle,
  empty,
}: {
  rows: AttendanceDtrRow[];
  expanded: number | null;
  onToggle: (employeeId: number) => void;
  empty: string;
}) {
  return (
    <ExpandableTable
      rows={rows}
      expanded={expanded}
      onToggle={onToggle}
      empty={empty}
      columns={[
        { header: "Days logged", className: "w-28", cell: (r) => r.days_logged },
        { header: "Total hours", className: "w-28", cell: (r) => r.total_hours.toFixed(2) },
        {
          header: "Avg hours",
          className: "w-28",
          cell: (r) => (r.average_hours === null ? "—" : r.average_hours.toFixed(2)),
        },
        { header: "Avg time in", className: "w-32", cell: (r) => r.average_in ?? "—" },
        { header: "Avg time out", className: "w-32", cell: (r) => r.average_out ?? "—" },
      ]}
      detail={(row) => {
        const days = loggedDays(row.days);

        return (
          <>
            <DetailHeading>Time record — {row.name}</DetailHeading>
            {days.length === 0 ? (
              <p className="text-sm text-muted-foreground">No days were logged or expected this month.</p>
            ) : (
              <div className="overflow-hidden rounded-lg border border-border bg-card">
                <table className="w-full border-collapse text-left">
                  <thead>
                    <tr className="bg-muted/40">
                      {["Date", "Day", "Time in", "Time out", "Hours", "Status"].map((h) => (
                        <th
                          key={h}
                          className="px-3 py-2 text-label font-bold uppercase tracking-wide text-muted-foreground"
                        >
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {days.map((day) => (
                      <tr key={day.date} className="border-t border-border">
                        <td className="px-3 py-2 text-sm text-foreground">{day.date}</td>
                        <td className="px-3 py-2 text-sm text-muted-foreground">{day.weekday}</td>
                        <td className="px-3 py-2 text-sm text-foreground">{day.time_in ?? "—"}</td>
                        <td className="px-3 py-2 text-sm text-foreground">{day.time_out ?? "—"}</td>
                        <td className="px-3 py-2 text-sm text-foreground">
                          {day.hours > 0 ? day.hours.toFixed(2) : "—"}
                        </td>
                        <td className="px-3 py-2">
                          <span
                            className={cn(
                              "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold",
                              DAY_STATUS[day.status].chip,
                            )}
                          >
                            {DAY_STATUS[day.status].label}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        );
      }}
    />
  );
}

// -------------------------------------------------------------- leave report

const REQUEST_TONE: Record<string, string> = {
  approved: "bg-success-subtle text-success",
  pending: "bg-warning-subtle text-warning",
  rejected: "bg-danger-subtle text-danger",
  cancelled: "bg-muted text-muted-foreground",
};

export function AttendanceLeaveTable({
  rows,
  expanded,
  onToggle,
  empty,
}: {
  rows: AttendanceLeaveRow[];
  expanded: number | null;
  onToggle: (employeeId: number) => void;
  empty: string;
}) {
  return (
    <ExpandableTable
      rows={rows}
      expanded={expanded}
      onToggle={onToggle}
      empty={empty}
      columns={[
        { header: "Days taken", className: "w-28", cell: (r) => r.taken.toFixed(1) },
        { header: "Approved", className: "w-24", cell: (r) => r.approved },
        { header: "Pending", className: "w-24", cell: (r) => r.pending },
        { header: "Entitled", className: "w-24", cell: (r) => r.entitled.toFixed(1) },
        { header: "Used", className: "w-24", cell: (r) => r.used.toFixed(1) },
        {
          header: "Remaining",
          className: "w-28",
          cell: (r) => <span className="font-semibold text-foreground">{r.remaining.toFixed(1)}</span>,
        },
      ]}
      detail={(row) => (
        <div className="space-y-5">
          <div>
            <DetailHeading>Requests touching this month — {row.name}</DetailHeading>
            {row.requests.length === 0 ? (
              <p className="text-sm text-muted-foreground">No leave requests in this month.</p>
            ) : (
              <ul className="space-y-1.5">
                {row.requests.map((req) => (
                  <li
                    key={req.id}
                    className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-border bg-card px-3 py-2 text-sm"
                  >
                    <span className="font-semibold text-foreground">{req.type}</span>
                    <span className="text-muted-foreground">
                      {req.start_date}
                      {req.end_date && req.end_date !== req.start_date ? ` → ${req.end_date}` : ""}
                    </span>
                    <span className="text-muted-foreground">
                      {req.days} day{req.days === 1 ? "" : "s"}
                    </span>
                    <span
                      className={cn(
                        "ml-auto inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize",
                        REQUEST_TONE[req.status] ?? "bg-muted text-muted-foreground",
                      )}
                    >
                      {req.status}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {row.balances.length > 0 && (
            <div>
              <DetailHeading>Balance by leave type</DetailHeading>
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {row.balances.map((balance) => (
                  <div
                    key={balance.name}
                    className="rounded-lg border border-border bg-card px-3 py-2 text-sm"
                  >
                    <p className="font-semibold text-foreground">{balance.name}</p>
                    <p className="text-caption text-muted-foreground">
                      {balance.remaining} left of {balance.entitled} · {balance.used} used
                    </p>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    />
  );
}
