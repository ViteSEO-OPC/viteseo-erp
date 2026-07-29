export type ClockState =
  | "not_clocked_in"
  | "clocked_in"
  | "on_lunch"
  | "on_break"
  | "clocked_out";

/** A task the employee logs at time-out. */
export interface DayTask {
  text: string;
  done: boolean;
}

export interface Attendance {
  id: number;
  work_date: string;
  time_in: string | null;
  time_out: string | null;
  lunch_minutes: number;
  break_minutes: number;
  hours_worked: number;
  status: string;
  state: ClockState;
  on_lunch: boolean;
  on_break: boolean;
  tasks: DayTask[];
  /**
   * How the clock-in proved where the employee was. `null` means no check ran at all —
   * the geofence was off, the row predates the feature, or the policy was enabled but
   * unconfigured and deliberately failed open. Never read a null as "unverified".
   */
  location_verified_by: "ip_gps" | "ip_only" | "remote_approved" | null;
}

/**
 * Today's clock-in window, decided by the server (Company → Rules → strict clock-in).
 * The panel only reads it — it never re-derives the rule.
 */
export interface ClockInWindow {
  /** HR requires everyone to wait for their scheduled start. */
  strict: boolean;
  /**
   * Manila "HH:MM" this employee's shift opens, or null when nothing holds them back
   * (strict off, a non-workday, or no scheduled start time).
   */
  opens_at: string | null;
  /**
   * Ask where the employee is before clocking in. This is "the server is actually
   * enforcing a geofence", NOT the raw Company → Rules toggle: a policy switched on but
   * left unconfigured fails open server-side and reports `false` here, so the panel never
   * demands a location that is about to be ignored.
   */
  geofence: boolean;
}

/**
 * A browser position fix, in the shape the clock-in endpoint takes.
 * `accuracy` is the radius of confidence in metres — the server uses it to decide whether
 * the reading is worth consulting at all, so it always travels with the coordinates.
 */
export interface LocationFix {
  lat: number;
  lng: number;
  accuracy: number;
}

export type RemoteCheckinStatus = "pending" | "approved" | "rejected";

/**
 * An off-site time-in awaiting (or having had) a human decision.
 *
 * `submitted_at` IS the official time-in — approving one stamps attendance at that moment,
 * not at the moment HR clicked approve, so nobody is marked late for HR's review delay.
 */
export interface RemoteCheckin {
  id: number;
  employee: { id: number; name: string | null; department: string | null };
  photo_url: string;
  lat: number | null;
  lng: number | null;
  has_location: boolean;
  status: RemoteCheckinStatus;
  submitted_at: string;
  reviewed_at: string | null;
  reviewed_by: string | null;
  rejection_note: string | null;
}

export interface MyAttendance {
  today: Attendance | null;
  week: Attendance[];
  clock_in: ClockInWindow;
}

export type Severity = "green" | "yellow" | "orange" | "red";

/** Mirrors Violation::SEVERITY_RANK on the API — higher wins when picking the worst flag. */
export const SEVERITY_RANK: Record<Severity, number> = {
  green: 1,
  yellow: 2,
  orange: 3,
  red: 4,
};

/** The alert-level ladder (shown in HR's legend and on the employee's banner). */
export const SEVERITY_LABEL: Record<Severity, string> = {
  green: "Low / Informational",
  yellow: "Caution",
  orange: "Warning",
  red: "Critical",
};

export interface AttendanceTiming {
  category: "early" | "on_time" | "late";
  late_minutes: number;
}

export interface ViolationFlag {
  severity: Severity;
  count: number;
  types: string[];
  /** 'YYYY-MM-DD' of the violation driving this flag (may be a past month). */
  work_date: string;
}

/** An employee's effective schedule for a day. Times are "HH:MM" (Manila, 24h). */
export interface EmployeeSchedule {
  name: string | null;
  start_time: string | null;
  end_time: string | null;
  grace_minutes: number;
  /** ISO weekdays that are workdays (1 = Mon … 7 = Sun). */
  workdays: number[];
  is_workday: boolean | null;
}

/** HR roster row (one per employee for a given day). */
export interface HrAttendanceRow {
  employee_id: number;
  name: string | null;
  /** Profile picture; null falls back to initials. */
  photo_url: string | null;
  department: string | null;
  /** 'YYYY-MM-DD' — bounds how far back violation history can be browsed. */
  hire_date: string | null;
  /** Effective work schedule for this date (override → assigned → default). */
  schedule: EmployeeSchedule;
  work_date: string;
  time_in: string | null;
  time_out: string | null;
  first_in: string | null;
  last_out: string | null;
  /** Unpaid lunch already deducted from hours_worked. */
  lunch_minutes: number;
  break_minutes: number;
  hours_worked: number;
  status: string;
  /** Slice 16 — a human asserted this status; it beats the clock and the leave table. */
  status_override: boolean;
  leave_type: string | null;
  timing: AttendanceTiming | null;
  flag: ViolationFlag | null;
  tasks: DayTask[];
  /** HR's correction note for this day, if any. */
  note: string | null;
}

export interface AttendanceSummary {
  date: string;
  checked_in: number;
  not_checked_in: number;
  on_leave: number;
  early_arrivals: number;
  late_arrivals: number;
  on_time: number;
  total_employees: number;
  absent: number;
}

export interface Violation {
  id: number;
  type: string;
  severity: Severity;
  work_date: string;
  details: string | null;
  resolved: boolean;
  employee: { id: number; name: string | null };
}

export interface DailyOverride {
  id?: number;
  date: string;
  grace_minutes: number | null;
}
