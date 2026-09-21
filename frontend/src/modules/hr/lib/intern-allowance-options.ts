import type {
  InternAllowanceMethod,
  InternAllowanceSettings,
} from "@/modules/hr/types/intern-allowance";

/**
 * The words each Intern Allowance choice is shown in. The accepted VALUES come from the API
 * (`options`), so a value the server does not accept can never be offered; these are only labels.
 */
const LABELS: Record<string, Record<string, string>> = {
  method: { per_day: "Per day", per_hour: "Per hour", fixed_monthly: "Fixed monthly" },
  per_day_proration: {
    none: "No proration",
    by_hours_rendered: "Calculate based on hours rendered",
  },
  monthly_proration: {
    none: "No proration",
    by_working_days: "Prorate based on working days",
    by_hours_rendered: "Prorate based on hours rendered",
  },
  late_rule: {
    none: "No deduction",
    missed_hours: "Deduct based on missed hours",
    fixed_amount: "Deduct fixed amount",
    partial_after_minutes: "Mark day as partial attendance after…",
  },
  partial_day_pricing: {
    half_day_rule: "Price it by the half-day rule",
    by_hours_rendered: "Pay the hours rendered",
    no_allowance: "No allowance for the day",
  },
  undertime_rule: {
    none: "No deduction",
    missed_hours: "Deduct based on missed hours",
    fixed_amount: "Deduct fixed amount",
  },
  early_out_rule: {
    follow_undertime: "Same as undertime",
    no_deduction: "No deduction",
    half_day_rule: "Price it by the half-day rule",
  },
  absence_rule: {
    no_allowance: "No allowance for absent day",
    absent_hours: "Deduct allowance based on absent hours",
    no_deduction: "No deduction",
  },
  half_day_rule: {
    pay_50: "Pay 50% of daily allowance",
    actual_hours: "Calculate based on actual hours",
    no_allowance: "No allowance",
    pay_full: "Pay the full allowance",
  },
  late_penalty_rule: {
    off: "Don't apply",
    follow_company: "Apply the company's tardiness penalty",
  },
  premium_day_basis: {
    hours_rendered: "The hours actually rendered",
    whole_day: "A whole day, however much was worked",
  },
  rest_day_worked: {
    no_allowance: "No allowance",
    allowance: "Allowance if worked",
    allowance_with_premium: "Allowance with rest-day premium",
  },
  holiday_not_worked: {
    no_allowance: "No allowance",
    allowance: "Allowance regardless of schedule",
  },
  holiday_worked: {
    allowance: "Allowance",
    allowance_with_premium: "Allowance with holiday premium",
    no_allowance: "No allowance",
  },
  holiday_rest_day_not_worked: { no_allowance: "No allowance", allowance: "Allowance" },
  premium_source: { company_rate: "Company premium rate", custom: "Custom rate" },
  special_working_day: {
    regular_workday: "An ordinary workday",
    as_special_holiday: "A special holiday",
  },
  suspension_not_worked: {
    follow_suspension: "Follow the suspension's paid setting",
    allowance: "Allowance",
    no_allowance: "No allowance",
  },
  suspension_worked: {
    allowance: "Allowance",
    allowance_with_premium: "Allowance with premium",
  },
  overtime_rule: {
    not_paid: "Not paid",
    regular_rate: "Paid at the hourly allowance",
    with_premium: "Paid with overtime premium",
  },
  night_diff_rule: {
    not_paid: "Not paid",
    company_rate: "Company night differential rate",
    custom: "Custom rate",
  },
  below_min_attendance: {
    no_allowance_for_day: "No allowance for the day",
    treat_as_half_day: "Treat as a half-day",
    prorate_by_hours: "Prorate by hours rendered",
    flag_only: "Flag for HR only",
  },
  leave_pay_rule: {
    unpaid: "Unpaid — recorded for HR's information",
    follow_leave_type: "Follow the leave type (paid or unpaid)",
  },
  leave_types_allowed: { all: "All leave types", selected: "Selected leave types" },
  distribution_timing: {
    every_cutoff: "Split across every cutoff",
    first_cutoff: "Whole month on the first cutoff",
    last_cutoff: "Whole month on the last cutoff",
  },
};

/** Settings that share one set of labels under different keys. */
function labelGroupFor(key: string): string {
  if (/_premium_source$/.test(key)) return "premium_source";
  if (/_holiday_rest_day_not_worked$/.test(key)) return "holiday_rest_day_not_worked";
  if (/_holiday_not_worked$/.test(key)) return "holiday_not_worked";
  if (/_holiday_worked$/.test(key)) return "holiday_worked";
  return key;
}

export function optionsFor(
  key: string,
  accepted: Record<string, string[]>,
): { value: string; label: string }[] {
  const labels = LABELS[labelGroupFor(key)] ?? {};
  return (accepted[key] ?? []).map((value) => ({ value, label: labels[value] ?? value }));
}

/** The human name of a setting, for "these were adjusted" messages. */
export const FIELD_LABELS: Record<string, string> = {
  premium_day_basis: "Premium day basis",
  late_rule: "Late attendance",
  undertime_rule: "Undertime",
  early_out_rule: "Early out",
  half_day_rule: "Half-day",
  absence_rule: "Absence",
  below_min_attendance: "Below minimum attendance",
  distribution_timing: "Distribution",
};

/** The premium each "worked" setting can switch on, keyed by its prefix. */
export const PREMIUM_RULES = {
  rest_day: "rest_day_worked",
  regular_holiday: "regular_holiday_worked",
  special_holiday: "special_holiday_worked",
  suspension: "suspension_worked",
  overtime: "overtime_rule",
} as const;

export type PremiumPrefix = keyof typeof PREMIUM_RULES;

export function premiumOn(values: Record<string, unknown>, prefix: PremiumPrefix): boolean {
  const rule = values[PREMIUM_RULES[prefix]];
  return rule === "allowance_with_premium" || rule === "with_premium";
}

/**
 * Paid by the hours rendered, the minutes an intern missed are already unpaid — which is why the
 * server switches late, undertime, early-out and half-day deductions off for these methods.
 */
export function isHoursBased(values: {
  method: string;
  per_day_proration: string;
  monthly_proration: string;
}): boolean {
  return (
    values.method === "per_hour" ||
    (values.method === "per_day" && values.per_day_proration === "by_hours_rendered") ||
    (values.method === "fixed_monthly" && values.monthly_proration === "by_hours_rendered")
  );
}

/** "per day" / "per hour" / "per month" — the unit an intern's own allowance is read in. */
export function internAllowanceUnit(method: InternAllowanceMethod | undefined): string {
  switch (method) {
    case "per_hour":
      return "per hour";
    case "fixed_monthly":
      return "per month";
    default:
      return "per day";
  }
}

/** The company default in the policy's unit. */
export function internDefaultAmount(settings: InternAllowanceSettings): number {
  switch (settings.method) {
    case "per_hour":
      return settings.hourly_amount;
    case "fixed_monthly":
      return settings.monthly_amount;
    default:
      return settings.daily_amount;
  }
}
