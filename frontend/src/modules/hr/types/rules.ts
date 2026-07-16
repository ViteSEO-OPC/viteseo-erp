// Company → Rules (Phase-2 Slice 14).

export interface WorkSchedule {
  id: number;
  name: string;
  workdays: number[]; // ISO day numbers, 1=Mon … 7=Sun
  start_time: string; // "HH:MM"
  end_time: string;
  grace_minutes: number;
  is_default: boolean;
}

export interface WorkScheduleInput {
  name: string;
  workdays: number[];
  start_time: string;
  end_time: string;
  grace_minutes: number;
  is_default: boolean;
}

export type LateDeductionMode = "none" | "per_lates";

export interface AttendancePolicy {
  id: number;
  consecutive_lates_before_absence: number;
  monthly_late_reset: boolean;
  awol_days_threshold: number;
  // Payroll: how accumulated lates translate into a pay deduction.
  late_deduction_mode: LateDeductionMode;
  lates_per_deduction: number; // per_lates: every N lates = 1 day deducted
  task_format: { require_english?: boolean; uppercase?: boolean };
}

export interface EffectiveSchedule {
  workdays: number[];
  start_time: string | null;
  end_time: string | null;
  grace_minutes: number;
  source: "default" | "assigned" | "employee_override";
  schedule_name: string | null;
  is_workday: boolean | null;
}

export interface EmployeeSchedule {
  work_schedule_id: number | null;
  custom_workdays: number[] | null;
  custom_start_time: string | null;
  custom_end_time: string | null;
  custom_grace_minutes: number | null;
  effective: EffectiveSchedule;
}

export interface EmployeeScheduleInput {
  work_schedule_id?: number | null;
  custom_workdays?: number[] | null;
  custom_start_time?: string | null;
  custom_end_time?: string | null;
  custom_grace_minutes?: number | null;
}

// ISO weekday numbers with short labels, for day pickers.
export const WEEKDAYS = [
  { value: 1, label: "Mon" },
  { value: 2, label: "Tue" },
  { value: 3, label: "Wed" },
  { value: 4, label: "Thu" },
  { value: 5, label: "Fri" },
  { value: 6, label: "Sat" },
  { value: 7, label: "Sun" },
] as const;

export function formatWorkdays(days: number[]): string {
  const map = new Map<number, string>(WEEKDAYS.map((d) => [d.value, d.label]));
  return days
    .slice()
    .sort((a, b) => a - b)
    .map((d) => map.get(d) ?? d)
    .join(", ");
}

export const SCHEDULE_SOURCE_LABEL: Record<string, string> = {
  default: "Default",
  assigned: "Assigned",
  employee_override: "Custom (per-employee)",
};
