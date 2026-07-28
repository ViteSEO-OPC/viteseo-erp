// Company → Rules (Phase-2 Slice 14).

export interface WorkSchedule {
  id: number;
  name: string;
  workdays: number[]; // ISO day numbers, 1=Mon … 7=Sun
  start_time: string; // "HH:MM"
  end_time: string;
  grace_minutes: number;
  unpaid_break_minutes: number; // meal period — shortens the paid day
  paid_break_minutes: number; // short break — inside the paid day (flag threshold)
  am_start_time: string | null; // half-day windows ("HH:MM") — nullable
  am_end_time: string | null;
  pm_start_time: string | null;
  pm_end_time: string | null;
  expected_minutes: number | null; // derived: (end − start) − unpaid_break
  is_default: boolean;
}

export interface WorkScheduleInput {
  name: string;
  workdays: number[];
  start_time: string;
  end_time: string;
  grace_minutes: number;
  unpaid_break_minutes: number;
  paid_break_minutes: number;
  am_start_time?: string | null;
  am_end_time?: string | null;
  pm_start_time?: string | null;
  pm_end_time?: string | null;
  is_default: boolean;
}

// Per-time charging only. The per-N-lates full-day penalty is a separate, independent
// switch (`late_penalty_enabled`), so the two can be combined.
export type LateDeductionMode = "none" | "per_hour" | "tiered";
export type LateMinutesFrom = "start_time" | "after_grace";
export type UndertimeDeductionMode = "none" | "per_hour";
export type MissingClockoutMode =
  | "no_deduction_flag_for_review"
  | "treat_as_undertime_to_now"
  | "treat_as_absent";
export type EarlyArrivalMode = "none" | "offset_undertime" | "overtime_eligible";
export type OvertimeMode = "none" | "approved_only" | "auto_after_threshold";
export type DeductionRounding = "exact" | "round_up" | "round_nearest" | "round_down";
export type DailyRateBasis = "period_workdays" | "fixed_divisor";

/** One rung of the `tiered` late ladder: over N minutes late ⇒ charge H hours. */
export interface LateTier {
  over: number;
  hours: number;
}

export type ViolationSeverity = "green" | "yellow" | "orange" | "red";

/**
 * One rung of the violation colour ladder. Evaluated top-down, first match wins —
 * so list most-severe first. Flags are disciplinary; they never move pay.
 */
export interface ViolationRule {
  severity: ViolationSeverity;
  streak_min?: number; // consecutive days
  monthly_min?: number; // occurrences this month
  occurrence_min?: number;
  type?: string; // e.g. "awol" | "ua" for the absence ladder
}

export interface ViolationRules {
  late?: ViolationRule[];
  absence?: ViolationRule[];
  missed_time_out?: ViolationRule[];
  /** Slice 9 — days where break + lunch ran past the schedule allowance. Flag only. */
  long_break?: ViolationRule[];
}

export interface AttendancePolicy {
  id: number;
  monthly_late_reset: boolean;

  // Payroll: how accumulated lates translate into a pay deduction.
  late_deduction_mode: LateDeductionMode; // per-time charge for the minutes late
  late_penalty_enabled: boolean; // independent: every N lates = 1 extra day deducted
  lates_per_deduction: number; // N for the penalty above
  late_minutes_from: LateMinutesFrom; // count late minutes from start, or from end of grace
  late_tiers: LateTier[] | null; // `tiered` mode only

  undertime_deduction_mode: UndertimeDeductionMode;
  missing_clockout_mode: MissingClockoutMode; // default: flag for review, never auto-deduct

  early_arrival_mode: EarlyArrivalMode;
  overtime_mode: OvertimeMode; // takes effect when OT requests ship
  overtime_threshold_minutes: number;

  // Premium & overtime rates (whole-percent multipliers; HR-editable, hard-floored at the DOLE
  // minimum). night_diff_pct is the add-on percent (10 = +10%). Consumers land in later phases;
  // holiday premium reads the two holiday rates now (no-op at the seeded defaults).
  premium_rest_day_pct: number; // floor 130
  premium_special_day_pct: number; // floor 130
  premium_regular_holiday_pct: number; // floor 200
  overtime_ordinary_pct: number; // floor 125
  overtime_rest_special_pct: number; // floor 169
  overtime_regular_holiday_pct: number; // floor 260
  night_diff_pct: number; // floor 10 (add-on)

  // `exact` is proportionate (the legal default); round_* is a warned opt-in.
  deduction_rounding: DeductionRounding;
  deduction_block_minutes: number;
  daily_deduction_cap: number; // in days — a day can never cost more than this

  // How a day is priced; the hourly rate is dailyRate ÷ hours_per_day_divisor (DOLE 8).
  daily_rate_basis: DailyRateBasis;
  annual_divisor: number; // 261 Mon–Fri · 313 Mon–Sat · 365 all-days · or custom
  hours_per_day_divisor: number;

  // Breaks are FLAGGED, never deducted.
  /** Slice 9 -- tolerance over each schedule's own allowance before flagging. 0 = none. */
  lunch_grace_minutes: number;
  break_grace_minutes: number;

  // Violation colour ladder (disciplinary flags — never a pay deduction).
  violation_rules: ViolationRules | null;

  task_format: { require_english?: boolean; uppercase?: boolean };
}


export interface EffectiveSchedule {
  workdays: number[];
  start_time: string | null;
  end_time: string | null;
  grace_minutes: number;
  unpaid_break_minutes: number;
  paid_break_minutes: number;
  expected_minutes: number | null;
  am_start_time: string | null;
  am_end_time: string | null;
  pm_start_time: string | null;
  pm_end_time: string | null;
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
  custom_unpaid_break_minutes: number | null;
  custom_paid_break_minutes: number | null;
  effective: EffectiveSchedule;
}

export interface EmployeeScheduleInput {
  work_schedule_id?: number | null;
  custom_workdays?: number[] | null;
  custom_start_time?: string | null;
  custom_end_time?: string | null;
  custom_grace_minutes?: number | null;
  custom_unpaid_break_minutes?: number | null;
  custom_paid_break_minutes?: number | null;
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
