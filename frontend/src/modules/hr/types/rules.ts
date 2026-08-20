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
/**
 * SINGLE-VALUED ON PURPOSE (item 8). These two columns once accepted several values apiece, none
 * of which any engine ever read — `treat_as_undertime_to_now` / `treat_as_absent` and
 * `offset_undertime` / `overtime_eligible` were settings that silently did nothing, which is worse
 * than an absent feature because HR believed a forgotten punch was being charged.
 *
 * The server now rejects everything else (`UpdateAttendancePolicyRequest`) and migration
 * `2026_07_28_000012_normalise_inert_policy_modes` collapsed the stored rows, so a union listing
 * the old values was the last place in the codebase still claiming they were reachable.
 *
 * WIDEN THESE AGAIN, don't add a fourth mode elsewhere, if the behaviour is ever built — and widen
 * the request rule in the same change, or the UI will offer what the API refuses.
 *
 * A missing clock-out is handled by `missing_timeout_allowance_minutes` instead: it resolves to the
 * employee's own scheduled end rather than being deducted or treated as an absence.
 */
export type MissingClockoutMode = "no_deduction_flag_for_review";
export type EarlyArrivalMode = "none";
/**
 * What happens when someone punches in before their shift starts.
 *  allow — recorded at the time they actually pressed it
 *  defer — accepted, but recorded at the shift's start time (pressed 7:30 ⇒ counts 8:00)
 *  block — refused until the shift has started
 */
export type EarlyClockInMode = "allow" | "defer" | "block";
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
  // Minutes past the scheduled end before an open punch counts as abandoned rather than still
  // running. A DURATION, so each employee's cutoff is their own shift end + this. 30–720.
  missing_timeout_allowance_minutes: number;

  early_arrival_mode: EarlyArrivalMode; // what early minutes are WORTH (inert for now)
  early_clock_in_mode: EarlyClockInMode; // what happens to an early punch
  // Does working a rest day need HR's permission first? On (the default) turns Time in on a
  // non-workday into a work request; off records the punch straight away. Pay is identical
  // either way — the rest-day premium is priced off the attendance row, not off an approval.
  rest_day_work_requires_request: boolean;

  // Geofenced clock-in. Off = today's clock-in, untouched; it doubles as the kill switch.
  // On but half-configured (no pin, or no IPs) deliberately behaves as OFF — the server
  // fails open rather than locking the company out of its own time clock.
  geofence_enabled: boolean;
  office_lat: number | null; // null = no pin set (distinct from 0,0, a real point)
  office_lng: number | null;
  office_radius_m: number; // metres; server floor 20 (GPS itself is only ~10–20 m accurate)
  office_ips: string[]; // exact matches only — CIDR ranges are an MVP non-goal
  /** Read-only: the IP the SERVER sees this browser as. Never sent back on save. */
  your_ip?: string | null;
  overtime_mode: OvertimeMode; // takes effect when OT requests ship
  overtime_threshold_minutes: number;
  // FIN-039 — ceilings on auto-mode overtime that pays with no approval. 0 = uncapped.
  // Minutes past a cap are not lost: they queue for review and pay once approved.
  auto_overtime_daily_cap_minutes: number;
  auto_overtime_period_cap_minutes: number;
  /** Item 7 — a day's overtime worth flagging for review. Flags only; withholds nothing. 0 = off. */
  auto_overtime_review_minutes: number;

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
  night_diff_start_time: string; // "HH:MM", defaults to 22:00
  night_diff_end_time: string; // "HH:MM", defaults to 06:00

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

/**
 * What a save to Company → Rules sends. WRITE-ONLY: `effective_from` is not part of the policy
 * resource, it dates the version payroll resolves against, so it is deliberately absent from
 * `AttendancePolicy` above — reading the policy back never returns it.
 *
 * Omitted means today. Set it to a date inside a cycle that has already been run to correct
 * that cycle; payroll prices each cycle on the rules in force on its last day.
 */
export type AttendancePolicyUpdate = Partial<Omit<AttendancePolicy, "id">> & {
  effective_from?: string;
};

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
  /**
   * Half-day windows. Null means INHERIT the assigned schedule's value, per field — so an
   * employee whose afternoons start at 13:30 sets only `custom_pm_start_time` and keeps the
   * template's end. These are what an approved half day is judged against.
   */
  custom_am_start_time: string | null;
  custom_am_end_time: string | null;
  custom_pm_start_time: string | null;
  custom_pm_end_time: string | null;
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
  custom_am_start_time?: string | null;
  custom_am_end_time?: string | null;
  custom_pm_start_time?: string | null;
  custom_pm_end_time?: string | null;
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
