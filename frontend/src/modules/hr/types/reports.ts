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
  rows: string[][];
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
