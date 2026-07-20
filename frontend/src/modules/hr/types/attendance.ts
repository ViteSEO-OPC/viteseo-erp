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
}

export interface MyAttendance {
  today: Attendance | null;
  week: Attendance[];
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
  hours_worked: number;
  status: string;
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
