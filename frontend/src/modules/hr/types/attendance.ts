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
  /** How early punches are handled — see EarlyClockInMode in types/rules. */
  mode: "allow" | "defer" | "block";
  /**
   * Manila "HH:MM" this employee's shift opens, or null when nothing holds them back
   * (strict off, a non-workday, or no scheduled start time).
   */
  opens_at: string | null;
  /**
   * `defer` mode only: the Manila "HH:MM" a punch made RIGHT NOW would be recorded at.
   * Null once the shift has started, because from then on it's stamped normally.
   */
  defers_to: string | null;
  /**
   * Ask where the employee is before clocking in. This is "the server is actually
   * enforcing a geofence", NOT the raw Company → Rules toggle: a policy switched on but
   * left unconfigured fails open server-side and reports `false` here, so the panel never
   * demands a location that is about to be ignored.
   */
  geofence: boolean;
  /**
   * Is today one of this employee's scheduled workdays? `false` turns Time in into a REQUEST
   * to work a rest day — the server refuses the punch and files one instead. Read from the
   * server rather than derived from the weekday: the effective schedule resolves override →
   * assigned → default, and a second copy of that in the browser would drift.
   */
  is_workday: boolean;
  /**
   * The employee's latest rest-day request for today, when today is a rest day. Null on a
   * workday, for management, and before anything is filed. `requested_at` is the time-in an
   * approval will stamp — the panel quotes it so the promise is visible before HR decides.
   */
  rest_day_request: {
    id: number;
    status: "pending" | "approved" | "rejected" | "cancelled" | "expired";
    requested_at: string | null;
    review_note: string | null;
  } | null;
  /**
   * HR has cancelled work today (typhoon, shutdown). Null on an ordinary day and on the
   * employee's own rest day, where saying "work is cancelled" would be noise.
   *
   * It does NOT disable the time clock: a skeleton crew still comes in, and blocking them
   * would leave the people who actually worked with no record of it.
   */
  suspension: {
    name: string;
    is_paid: boolean;
    status: "suspended_paid" | "suspended_unpaid";
  } | null;
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
  /**
   * Which half of the day is approved OFF — an unpaid half-day request or half-day leave,
   * the two being the same shape with different funding. `'BOTH'` is a whole day reached by
   * filing each half separately. Null on an ordinary day.
   *
   * The board shows it because the shift on such a day is genuinely shorter: without it a
   * 1:00 PM arrival read as an unexplained one, and the person reading the row had no way to
   * tell an approved afternoon from someone who simply turned up late.
   */
  half_day_off: "AM" | "PM" | "BOTH" | null;
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

/**
 * Why an open shift is in the review queue — mirrors AttendanceService's RESOLVE_* constants.
 * These are the branches the pay engine took, not a label: `auto_closed` means pay DID resolve
 * the day at the scheduled end, the other three mean it could not price the day at all.
 */
export type OpenShiftReason =
  | "auto_closed"
  | "rest_day"
  | "no_schedule"
  | "in_after_end";

/** One row of the stale-attendance review queue (item 6). */
export interface OpenShiftRow {
  attendance_id: number;
  employee_id: number;
  name: string | null;
  department: string | null;
  work_date: string;
  time_in: string | null;
  /**
   * Manila 'HH:MM' for the day, or null when the employee has no schedule at all. On a day with
   * an approved half day this is the HALF the employee was expected to work — the same window
   * `resolved_time_out` was derived against, so the two cannot appear to contradict each other.
   */
  scheduled_start: string | null;
  scheduled_end: string | null;
  /** Which half was approved off, and hence why the window above is shorter than usual. */
  half_day_off: "AM" | "PM" | "BOTH" | null;
  reason: OpenShiftReason;
  /**
   * `needs_fix` — the day cannot be priced and is being paid wrongly until HR corrects it.
   * `auto_closed` — the money is already right; the record is just still open.
   * Note an approved overtime request forces `needs_fix` even on an auto-closed day, because
   * the approval says overtime happened and the auto-close paid none.
   */
  status: "needs_fix" | "auto_closed";
  /** Manila 'HH:MM' where pay placed the end of the shift; null when it could not. */
  resolved_time_out: string | null;
  /** The resolved end falls on the day after work_date (an overnight schedule). */
  resolved_next_day: boolean;
  /** Approved overtime on this date that the auto-close pays nothing for. */
  approved_overtime_hours: number | null;
  hours_open: number;
  /** Under the 18h self-close limit — the employee can still close it from their own clock. */
  employee_can_fix: boolean;
  /** ISO instant the allowance expired and pay stopped waiting. */
  auto_closes_at: string | null;
}
