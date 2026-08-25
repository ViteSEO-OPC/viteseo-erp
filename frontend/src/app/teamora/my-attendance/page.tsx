"use client";

import { useMemo, useState } from "react";
import { CalendarClock } from "lucide-react";

import { cn } from "@/lib/utils";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { DataTable, type Column } from "@/core/components/data-table";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { MyAttendanceCalendar } from "@/modules/hr/components/my-attendance-calendar";
import { useMyAttendanceReport } from "@/modules/hr/hooks/use-my-attendance-report";
import {
  MY_DAY_STATUS,
  dayHighlightLine,
  dayHighlights,
  formatHours,
  formatMinutes,
} from "@/modules/hr/lib/my-attendance-display";
import type { MyAttendanceDay, MyAttendanceMonth } from "@/modules/hr/types/my-attendance";

/** My Attendance — the employee's own month, day by day. */

/** 'YYYY-MM' shifted by whole months, without dragging a date library in for it. */
function shiftMonth(month: string, delta: number): string {
  const [year, index] = month.split("-").map(Number);
  const d = new Date(year, index - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

/** 'YYYY-MM' → 'March 2026', for a month the loaded report does not list yet. */
function monthLabel(month: string): string {
  const [year, index] = month.split("-").map(Number);
  return new Date(year, index - 1, 1).toLocaleDateString("en-US", {
    month: "long",
    year: "numeric",
  });
}

export default function MyAttendancePage() {
  // Undefined until the reader picks one — the server answers with the current Manila month,
  // which the client should not have to guess at.
  const [month, setMonth] = useState<string | undefined>(undefined);
  const { data: report, isLoading, isError, isFetching } = useMyAttendanceReport(month);

  // TWO months, and the difference matters. `pendingMonth` is what the reader just asked for and
  // is what the filter bar shows — a select that snapped back to the old month mid-fetch would
  // read as a broken control. `loadedMonth` is what the figures below actually describe, so the
  // calendar and the tables never claim to be a month they are not.
  const pendingMonth = month ?? report?.selected_month.value ?? "";
  const loadedMonth = report?.selected_month.value ?? "";
  const selectedYear = pendingMonth ? Number(pendingMonth.slice(0, 4)) : report?.selected_year;

  const days = useMemo(() => report?.days ?? [], [report]);

  // The report lists the months of the year it was BUILT for, so a pending pick in another year
  // is not in that list yet. Carry it, or the select shows an empty trigger while it loads.
  const monthOptions = useMemo(() => {
    const options = (report?.months ?? []).map((m) => ({ value: m.value, label: m.label }));
    if (pendingMonth && !options.some((o) => o.value === pendingMonth)) {
      options.unshift({ value: pendingMonth, label: monthLabel(pendingMonth) });
    }
    return options;
  }, [report, pendingMonth]);

  const yearOptions = useMemo(() => {
    const years = new Set(report?.years ?? []);
    if (selectedYear) years.add(selectedYear);
    return [...years].sort((a, b) => b - a).map((y) => ({ value: String(y), label: String(y) }));
  }, [report, selectedYear]);

  function pickYear(year: string) {
    // Keep the month you were reading when you change year — January every time would throw
    // away the only part of the filter you did not touch.
    const mm = pendingMonth ? pendingMonth.slice(5, 7) : "01";
    setMonth(`${year}-${mm}`);
  }

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="My Attendance"
        subtitle="Your own record, month by month — the days you worked, how late or short they ran, and every day marked as leave, a holiday or a suspension."
      />

      {/* Filters */}
      <div className="mt-6 flex flex-wrap items-center gap-3">
        <label htmlFor="my-att-year" className="text-caption font-medium text-muted-foreground">
          Year
        </label>
        <SimpleSelect
          id="my-att-year"
          className="w-28"
          value={selectedYear ? String(selectedYear) : undefined}
          onValueChange={pickYear}
          placeholder="Year"
          options={yearOptions}
        />

        <label htmlFor="my-att-month" className="text-caption font-medium text-muted-foreground">
          Month
        </label>
        <SimpleSelect
          id="my-att-month"
          className="w-44"
          value={pendingMonth || undefined}
          onValueChange={setMonth}
          placeholder="Month"
          options={monthOptions}
        />

        {isFetching && !isLoading && (
          <span className="text-caption text-muted-foreground">Updating…</span>
        )}

        {report && (
          <span className="ml-auto text-caption text-muted-foreground">
            {report.schedule.start_time && report.schedule.end_time
              ? `Your schedule: ${report.schedule.name ?? "Default"} · ${report.schedule.start_time}–${report.schedule.end_time}`
              : "No fixed schedule on file"}
            {report.schedule.grace_minutes > 0
              ? ` · ${report.schedule.grace_minutes} min grace`
              : ""}
          </span>
        )}
      </div>

      {isLoading ? (
        <p className="mt-6 text-sm text-muted-foreground">Loading your attendance…</p>
      ) : isError || !report ? (
        <p className="mt-6 text-sm text-destructive">
          Could not load your attendance record. Please try again.
        </p>
      ) : (
        <div className={cn(isFetching && "opacity-60 transition-opacity")}>
          {/* Headline: the five numbers the month is usually opened for. */}
          <div className="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <StatCard
              label="Days present"
              value={report.totals.present}
              subNote={`${report.totals.days_logged} day${report.totals.days_logged === 1 ? "" : "s"} clocked`}
            />
            <StatCard
              label="Late"
              value={report.totals.late}
              subNote={
                report.totals.late_minutes > 0
                  ? `${formatMinutes(report.totals.late_minutes)} in total`
                  : "On time all month"
              }
            />
            <StatCard label="Absent" value={report.totals.absent} />
            <StatCard
              label="On leave"
              value={report.totals.on_leave}
              subNote={
                report.totals.leave_by_type.length > 0
                  ? report.totals.leave_by_type.map((l) => `${l.name} ${l.days}`).join(" · ")
                  : undefined
              }
            />
            <StatCard
              label="Attendance rate"
              value={report.totals.rate === null ? "—" : `${report.totals.rate}%`}
              subNote={`of ${report.totals.expected_days} expected day${report.totals.expected_days === 1 ? "" : "s"}`}
            />
          </div>

          {/* Everything else the month contained, at a glance. */}
          <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <Tile label="Late" value={formatMinutes(report.totals.late_minutes)} tone="warning" />
            <Tile
              label="Undertime"
              value={formatMinutes(report.totals.undertime_minutes)}
              tone="orange"
            />
            <Tile
              label="Overtime"
              value={formatMinutes(report.totals.overtime_minutes)}
              tone="info"
            />
            <Tile
              label="Night differential"
              value={formatMinutes(report.totals.night_minutes)}
              tone="violet"
            />
            <Tile
              label="Rest-day work"
              value={formatMinutes(report.totals.rest_day_minutes)}
              tone="brand"
            />
            <Tile label="Hours worked" value={formatHours(report.totals.hours_worked)} tone="success" />
            <Tile label="Half days" value={report.totals.half_day} tone="orange" />
            <Tile label="Holidays" value={report.totals.holiday} tone="violet" />
            <Tile
              label="Work suspensions"
              value={report.totals.suspended}
              tone="brand"
              hint={
                report.totals.suspended_unpaid > 0
                  ? `${report.totals.suspended_unpaid} unpaid`
                  : undefined
              }
            />
            <Tile label="Rest days" value={report.totals.rest_day} tone="muted" />
          </div>

          {report.month_in_progress && (
            <p className="mt-3 text-caption text-muted-foreground">
              {report.selected_month.label} is still in progress — today is never counted as an
              absence, and days still ahead are marked upcoming.
            </p>
          )}

          {report.employee.attendance_exempt && (
            <p className="mt-3 text-caption text-muted-foreground">
              You are on a management schedule, which is exempt from the time clock: scheduled
              workdays read as present and no lateness or undertime is measured against you.
            </p>
          )}

          <div className="mt-5 grid gap-5 xl:grid-cols-2">
            <MyAttendanceCalendar
              month={loadedMonth}
              days={days}
              today={report.today}
              onShiftMonth={(delta) => setMonth(shiftMonth(pendingMonth, delta))}
            />

            {/*
             * The twelve months of the year, so one month can be read against the rest.
             * Clicking a row is how the reader moves — the same gesture as the calendar arrows.
             */}
            <DataTable<MyAttendanceMonth>
              title={`${report.selected_year} at a glance`}
              columns={monthColumns}
              rows={report.months}
              rowKey={(m) => m.value}
              onRowClick={(m) => setMonth(m.value)}
              rowClassName={(m) =>
                cn(
                  m.value === loadedMonth && "bg-brand-subtle",
                  !m.has_data && "text-muted-foreground/60",
                )
              }
              pageSize={0}
              searchable={false}
              filterable={false}
              empty="No months to show."
            />
          </div>

          <DataTable<MyAttendanceDay>
            className="mt-5"
            title={`Daily record — ${report.selected_month.label}`}
            titleRight={
              <span className="inline-flex items-center gap-1.5 text-caption text-muted-foreground">
                <CalendarClock className="size-4" />
                Filter by status, or search a date
              </span>
            }
            columns={dayColumns}
            rows={days}
            rowKey={(d) => d.date}
            pageSize={31}
            searchPlaceholder="Search a date…"
            getSearchText={(d) => `${d.date} ${d.weekday} ${dayHighlightLine(d)}`}
            empty="No days in this month."
          />

          <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-caption text-muted-foreground">
            <span>
              Lateness and undertime are measured against your schedule; what they cost, if
              anything, is shown on your payslip.
            </span>
            <span>Generated {report.generated_at}</span>
          </div>
        </div>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ columns

const dayColumns: Column<MyAttendanceDay>[] = [
  {
    header: "Date",
    cell: (d) => (
      <span className="whitespace-nowrap font-semibold text-foreground">
        {d.day} <span className="font-normal text-muted-foreground">{d.weekday}</span>
      </span>
    ),
  },
  {
    header: "Status",
    cell: (d) => (
      <span
        className={cn(
          "inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-caption font-bold",
          MY_DAY_STATUS[d.status].chip,
        )}
      >
        {MY_DAY_STATUS[d.status].label}
        {d.status === "suspended_unpaid" ? " · unpaid" : ""}
      </span>
    ),
  },
  { header: "Time in", cell: (d) => d.time_in ?? "—" },
  { header: "Time out", cell: (d) => d.time_out ?? "—" },
  { header: "Hours", align: "right", cell: (d) => formatHours(d.hours) },
  {
    header: "Late",
    align: "right",
    cell: (d) =>
      d.late_minutes > 0 ? (
        <span className="font-semibold text-warning">{formatMinutes(d.late_minutes)}</span>
      ) : (
        "—"
      ),
  },
  {
    header: "Undertime",
    align: "right",
    cell: (d) =>
      d.undertime_minutes > 0 ? (
        <span className="font-semibold text-severity-orange">
          {formatMinutes(d.undertime_minutes)}
        </span>
      ) : (
        "—"
      ),
  },
  {
    header: "Overtime",
    align: "right",
    cell: (d) =>
      d.overtime_minutes > 0 ? (
        <span className="font-semibold text-info">{formatMinutes(d.overtime_minutes)}</span>
      ) : (
        "—"
      ),
  },
  {
    header: "Details",
    cell: (d) => {
      // Everything the three numeric columns to the left do NOT already say: which leave, which
      // holiday, which suspension, an approved half-day or early-out, night and rest-day minutes,
      // and whatever HR typed on the row.
      const text = dayHighlights(d)
        .filter((h) => h.kind !== "late" && h.kind !== "undertime" && h.kind !== "overtime")
        .map((h) => h.text)
        .join(" · ");

      return text ? (
        <span className="block max-w-[16rem] truncate text-muted-foreground" title={text}>
          {text}
        </span>
      ) : (
        "—"
      );
    },
  },
];

const monthColumns: Column<MyAttendanceMonth>[] = [
  {
    header: "Month",
    cell: (m) => <span className="font-semibold text-foreground">{m.short_label}</span>,
  },
  { header: "Present", align: "right", cell: (m) => count(m.totals.present) },
  { header: "Late", align: "right", cell: (m) => count(m.totals.late) },
  { header: "Absent", align: "right", cell: (m) => count(m.totals.absent) },
  { header: "Leave", align: "right", cell: (m) => count(m.totals.on_leave) },
  {
    header: "Late time",
    align: "right",
    cell: (m) => (m.totals.late_minutes > 0 ? formatMinutes(m.totals.late_minutes) : "—"),
  },
  {
    header: "Undertime",
    align: "right",
    cell: (m) =>
      m.totals.undertime_minutes > 0 ? formatMinutes(m.totals.undertime_minutes) : "—",
  },
  {
    header: "Overtime",
    align: "right",
    cell: (m) => (m.totals.overtime_minutes > 0 ? formatMinutes(m.totals.overtime_minutes) : "—"),
  },
];

/** A zero in a month that has not happened is not a fact — it is an empty cell. */
function count(value: number): React.ReactNode {
  return value === 0 ? <span className="text-muted-foreground/60">0</span> : value;
}

// -------------------------------------------------------------------- tile

const TILE_TONE = {
  success: "bg-success-subtle text-success",
  danger: "bg-danger-subtle text-danger",
  info: "bg-info-subtle text-info",
  warning: "bg-warning-subtle text-warning",
  orange: "bg-severity-orange-subtle text-severity-orange",
  violet: "bg-violet-subtle text-violet",
  brand: "bg-brand-subtle text-brand",
  muted: "bg-muted text-foreground",
} as const;

/** One tinted figure — the dashboard's tile, reused so the two screens read alike. */
function Tile({
  label,
  value,
  tone,
  hint,
}: {
  label: string;
  value: React.ReactNode;
  tone: keyof typeof TILE_TONE;
  hint?: string;
}) {
  return (
    <div className={cn("rounded-card p-4", TILE_TONE[tone])}>
      <p className="text-h3 font-bold leading-none">{value}</p>
      <p className="mt-1.5 text-caption font-medium text-foreground/70">{label}</p>
      {hint && <p className="text-caption text-foreground/60">{hint}</p>}
    </div>
  );
}
