/** Company → Intern Allowance (InternAllowanceSettingsController). */

export type InternAllowanceMethod = "per_day" | "per_hour" | "fixed_monthly";

/**
 * One version of the rules. Every enumerated field is a company policy choice; the accepted values
 * come back from the API as `options`, so this type stays a plain string for each.
 */
export interface InternAllowanceSettings {
  // Allowance calculation
  method: InternAllowanceMethod;
  required_hours_per_day: number;
  daily_amount: number;
  per_day_proration: string;
  hourly_amount: number;
  max_paid_hours_per_day: number;
  monthly_amount: number;
  monthly_proration: string;
  // Attendance rules
  late_rule: string;
  late_fixed_amount: number | null;
  late_partial_after_minutes: number | null;
  partial_day_pricing: string;
  undertime_rule: string;
  undertime_fixed_amount: number | null;
  early_out_rule: string;
  absence_rule: string;
  half_day_rule: string;
  late_penalty_rule: string;
  // Non-working days
  premium_day_basis: string;
  rest_day_worked: string;
  rest_day_premium_source: string;
  rest_day_premium_pct: number | null;
  regular_holiday_not_worked: string;
  regular_holiday_worked: string;
  regular_holiday_rest_day_not_worked: string;
  regular_holiday_premium_source: string;
  regular_holiday_premium_pct: number | null;
  special_holiday_not_worked: string;
  special_holiday_worked: string;
  special_holiday_rest_day_not_worked: string;
  special_holiday_premium_source: string;
  special_holiday_premium_pct: number | null;
  special_working_day: string;
  holiday_forfeit_after_absence: boolean;
  suspension_not_worked: string;
  suspension_worked: string;
  suspension_premium_source: string;
  suspension_premium_pct: number | null;
  overtime_rule: string;
  overtime_premium_source: string;
  overtime_premium_pct: number | null;
  night_diff_rule: string;
  night_diff_pct: number | null;
  // Attendance requirement
  min_attendance_pct: number;
  below_min_attendance: string;
  // Leave
  leave_pay_rule: string;
  leave_types_allowed: string;
  leave_type_ids: number[];
  // Distribution within payroll
  distribution_timing: string;
}

export interface InternAllowanceVersion {
  effective_from: string;
  status: "past" | "current" | "scheduled";
  settings: InternAllowanceSettings;
  created_by: string | null;
  updated_at: string | null;
}

export interface InternAllowanceOverview {
  /** The rules in force today. */
  settings: InternAllowanceSettings;
  effective_from: string | null;
  /** The date the last save was filed under — may be a scheduled future version. */
  saved_effective_from: string | null;
  /** Settings the last save corrected so no minute is charged twice. */
  corrected_fields: string[];
  options: Record<string, string[]>;
  versions: InternAllowanceVersion[];
}

export interface InternAllowanceSaveInput extends InternAllowanceSettings {
  /** Today or later; blank means today. */
  effective_from?: string | null;
  /** Set once HR has seen which interns' own allowances a change of unit re-reads. */
  confirm_override_reinterpretation?: boolean;
}

/** The invented month the Calculation Preview prices. */
export interface InternPreviewSample {
  frequency?: string;
  amount_override?: number | null;
  scheduled_days: number;
  hours_per_day?: number;
  absent_days?: number;
  half_days?: number;
  leave_days?: number;
  regular_holidays_off?: number;
  regular_holidays_worked?: number;
  special_holidays_off?: number;
  special_holidays_worked?: number;
  suspensions_off?: number;
  rest_days_worked?: number;
  suspensions_paid?: boolean;
  leave_type_paid?: boolean;
  late_minutes?: number[];
  undertime_minutes?: number[];
  hours_rendered?: number[];
  overtime_minutes?: number;
  night_minutes?: number;
}

export interface InternPreviewRow {
  key: string;
  name: string;
  amount: number;
  note?: string | null;
  count?: number;
  days?: number;
}

export interface InternPreview {
  settings: InternAllowanceSettings;
  corrected_fields: string[];
  rates: {
    method: InternAllowanceMethod;
    amount: number;
    amount_source: "override" | "default";
    daily_equivalent: number;
    hourly_equivalent: number;
    frequency: string;
  };
  earnings: InternPreviewRow[];
  deductions: InternPreviewRow[];
  totals: { allowance: number; premiums: number; deductions: number; net_allowance: number };
  lines: { date: string; kind: string; days: number; detail: string | null; amount: number }[];
  flags: { date: string; code: string; detail: string | null }[];
}
