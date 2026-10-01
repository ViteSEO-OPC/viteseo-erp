export interface ReportSummaryItem {
  label: string;
  value: string;
}

export interface ReportBarItem {
  label: string;
  value: number;
  display: string;
}

export interface ReportBars {
  title: string;
  items: ReportBarItem[];
}

export interface ReportTable {
  columns: string[];
  /** ONE PAGE of rows when `meta` is present — read `meta.total` for how many there are. */
  rows: string[][];
  /**
   * Present only on the tables the server pages: the ones carrying a row per employee (leave
   * liability, tax reconciliation, the payroll register). Absent means the table is small by
   * nature and arrived whole.
   *
   * On the register `per_page` is a target rather than an exact count — its rows come in
   * department blocks that must not be split from their subtotal.
   */
  meta?: {
    total: number;
    current_page: number;
    per_page: number;
    last_page: number;
  } | null;
}

export interface TitledReportTable extends ReportTable {
  title: string;
}

export interface ReportExportEntry {
  when: string | null;
  by: string;
}

export interface MovementPoint {
  label: string;
  joiners: number;
  leavers: number;
  net: number;
}

export interface ReportDepartment {
  id: number;
  name: string;
}

export interface Report {
  title: string;
  description: string;
  mock: boolean;
  restricted?: boolean;
  summary: ReportSummaryItem[];
  bars: ReportBars | null;
  table: ReportTable | null;
  // Payroll & Cost specific — government remittance (employee vs employer).
  remittance?: TitledReportTable | null;
  /**
   * The register's comparison against the previous period of the SAME pay group. Null when
   * there is no earlier comparable run (a first run, or a 13th-month with no prior 13th-month).
   */
  variance?: TitledReportTable | null;
  /** Completed runs, so the register can offer a picker instead of only the latest. */
  runs?: { id: number; label: string }[];
  selected_run?: {
    id: number;
    cycle: string;
    status: string;
    is_locked: boolean;
  };
  /** The pay groups that actually have completed runs. */
  pay_groups?: string[];
  /** Tax years that have completed regular payroll, newest first. */
  years?: number[];
  selected_year?: number;
  note: string | null;
  exports: ReportExportEntry[];
  // Headcount & Movement specifics.
  movement?: MovementPoint[];
  departments?: ReportDepartment[];
  // Recruitment Funnel specifics.
  funnel?: ReportBarItem[];
  // Absences & Leaves + Compensation specifics.
  stacked?: StackedSeries;
  // Attrition & Tenure specifics.
  departures?: StackedSeries;
  tenure?: ReportBarItem[] | { label: string; value: number }[];
  // Diversity specifics.
  representation?: StackedSeries;
  // Audit Trail specifics.
  entries?: AuditEntry[];
  /**
   * Present when the SERVER paged this report — `entries` is then one page, not the whole
   * result, and the screen must render `total` rather than counting what it holds.
   */
  pagination?: ReportPagination;
  // Attendance specifics — the three month-scoped views share one envelope, so a single
  // component renders all of them and only the row payload below differs per tab.
  tabs?: ReportTab[];
  active_tab?: string;
  months?: ReportMonthOption[];
  selected_month?: ReportMonthOption;
  selected_department?: string;
  working_days?: number;
  elapsed_working_days?: number;
  month_in_progress?: boolean;
  generated_at?: string;
  attendance?: AttendanceSummaryRow[];
  dtr?: AttendanceDtrRow[];
  leave?: AttendanceLeaveRow[];
}

export interface ReportTab {
  key: string;
  title: string;
  description: string;
}

export interface ReportMonthOption {
  value: string;
  label: string;
}

/**
 * How one day of an employee's month reads. `upcoming` is a workday that hasn't happened yet and
 * `not_employed` a day outside their employment window — neither is an absence, and neither
 * counts toward the attendance rate.
 *
 * This must stay a superset of what `AttendanceReportService::gridFor()` can emit, which includes
 * anything HR stored in `hr_attendance.status` — the suspension statuses reach the report that way.
 */
export type AttendanceDayStatus =
  | "present"
  | "late"
  | "absent"
  | "on_leave"
  | "half_day"
  | "rest_day"
  | "holiday"
  | "suspended_paid"
  | "suspended_unpaid"
  | "not_employed"
  | "upcoming";

export interface AttendanceReportDay {
  day: number;
  date: string;
  weekday: string;
  status: AttendanceDayStatus;
  leave_type: string | null;
  holiday: string | null;
  time_in: string | null;
  time_out: string | null;
  in_minutes: number | null;
  out_minutes: number | null;
  hours: number;
  /**
   * Minutes past the start the employee was expected at, or null on a day with no punch to
   * judge. Zero covers three different days — arrived early, arrived inside the grace window,
   * or a schedule that cannot say when the day began — which is why it renders as a dash and
   * the Status column is left to tell them apart.
   */
  late_minutes: number | null;
  note: string | null;
}

interface AttendanceRowBase {
  employee_id: number;
  name: string;
  department: string | null;
  days: AttendanceReportDay[];
}

export interface AttendanceSummaryRow extends AttendanceRowBase {
  present: number;
  late: number;
  absent: number;
  on_leave: number;
  /** Days the employee could have shown up for — the attendance rate's denominator. */
  expected_days: number;
  rate: number | null;
}

export interface AttendanceDtrRow extends AttendanceRowBase {
  days_logged: number;
  total_hours: number;
  average_hours: number | null;
  average_in: string | null;
  average_out: string | null;
}

export interface AttendanceLeaveBalance {
  name: string;
  entitled: number;
  used: number;
  remaining: number;
}

export interface AttendanceLeaveRequestRow {
  id: number;
  type: string;
  start_date: string | null;
  end_date: string | null;
  days: number;
  status: string;
}

export interface AttendanceLeaveRow {
  employee_id: number;
  name: string;
  department: string | null;
  taken: number;
  approved: number;
  pending: number;
  entitled: number;
  used: number;
  remaining: number;
  balances: AttendanceLeaveBalance[];
  requests: AttendanceLeaveRequestRow[];
}

/** What the server says about a paged report: the whole set, and which slice of it this is. */
export interface ReportPagination {
  /** Rows matching the current filters across every page. */
  total: number;
  page: number;
  per_page: number;
  pages: number;
  /** Rows in THIS response — less than `per_page` on the last page. */
  returned: number;
}

export interface AuditEntry {
  timestamp: string;
  user: string;
  module: string;
  action: string;
  details: string;
}

export interface StackedSeriesDef {
  name: string;
  color: string | null;
}

export interface StackedPoint {
  label: string;
  values: number[];
}

export interface StackedSeries {
  series: StackedSeriesDef[];
  points: StackedPoint[];
}
