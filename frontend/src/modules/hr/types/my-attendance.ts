/**
 * The employee's own attendance record for one month (GET /hr/me/attendance-report).
 *
 * Every figure here is an attendance FACT — minutes late, minutes short, minutes of approved
 * overtime — never pesos. What a policy charges for those minutes is the payslip's answer.
 */

/**
 * How one day reads. `upcoming` is a workday that hasn't happened yet and `not_employed` a day
 * outside the employment window — neither is an absence, and neither counts toward the rate.
 */
export type MyDayStatus =
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

export interface MyAttendanceDay {
  day: number;
  date: string;
  weekday: string;
  status: MyDayStatus;
  is_workday: boolean;
  time_in: string | null;
  time_out: string | null;
  hours: number;
  late_minutes: number;
  undertime_minutes: number;
  overtime_minutes: number;
  /** Night-differential minutes across the whole shift, not only its overtime tail. */
  night_minutes: number;
  rest_day_minutes: number;
  leave_type: string | null;
  leave_is_paid: boolean | null;
  /** The half approved OFF — so a 1:00 PM start reads as a half day, not a late arrival. */
  half_day_off: "AM" | "PM" | "BOTH" | null;
  early_out_time: string | null;
  holiday: { name: string; type: string | null } | null;
  suspension: { name: string; is_paid: boolean } | null;
  note: string | null;
}

export interface MyAttendanceTotals {
  present: number;
  late: number;
  absent: number;
  on_leave: number;
  half_day: number;
  holiday: number;
  rest_day: number;
  suspended: number;
  suspended_paid: number;
  suspended_unpaid: number;
  upcoming: number;
  days_logged: number;
  /** Days the employee could have shown up for — the attendance rate's denominator. */
  expected_days: number;
  hours_worked: number;
  late_minutes: number;
  undertime_minutes: number;
  overtime_minutes: number;
  night_minutes: number;
  rest_day_minutes: number;
  rate: number | null;
  leave_by_type: { name: string; days: number }[];
}

export interface MyAttendanceMonth {
  value: string;
  label: string;
  short_label: string;
  /** False for a month before the hire date or still ahead — empty, not a row of zeroes. */
  has_data: boolean;
  totals: MyAttendanceTotals;
}

export interface MyAttendanceReport {
  employee: {
    name: string;
    department: string | null;
    hire_date: string | null;
    /** Management is exempt from the clock — every zero below follows from that, not from luck. */
    is_management: boolean;
  };
  schedule: {
    name: string | null;
    start_time: string | null;
    end_time: string | null;
    grace_minutes: number;
    workdays: number[];
  };
  years: number[];
  selected_year: number;
  months: MyAttendanceMonth[];
  selected_month: { value: string; label: string };
  month_in_progress: boolean;
  today: string;
  days: MyAttendanceDay[];
  totals: MyAttendanceTotals;
  year_totals: MyAttendanceTotals;
  generated_at: string;
}
