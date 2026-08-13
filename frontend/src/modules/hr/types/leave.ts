import type { EmployeeRef } from "@/modules/hr/types/employee";
import type { AllotmentMode } from "@/modules/hr/types/leave-config";

export type { AllotmentMode };

export interface LeaveType {
  id: number;
  name: string;
  code: string;
  days_per_year: number;
  is_paid: boolean;
  /** No allowance and no balance check — independent of is_paid. */
  is_unlimited: boolean;
  color: string | null;
  /** Optional HR-facing blurb describing the leave type. */
  description: string | null;
  /** Whether this type may be filed for past dates at all. */
  allow_backdated: boolean;
  /** Max days a past-dated request may reach back; null = no limit (only when allow_backdated). */
  backdate_limit_days: number | null;
  // FIN-034 — accrual. "none" = hand-managed (the default for every existing type).
  accrual_mode: "none" | "monthly" | "annual";
  prorate_first_year: boolean;
  /** null = all unused days carry over; 0 = none do. Company policy, so no shipped default. */
  carry_over_cap_days: number | null;
  /** Months from 1 January until carried days lapse; null = never. */
  carry_over_expiry_months: number | null;
}

// Half-day requests — separate from leave, always unpaid (deducted from pay).
export interface HalfDayRequest {
  id: number;
  work_date: string;
  period: "AM" | "PM";
  reason: string;
  status: string;
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancel_reason: string | null;
  employee: EmployeeRef;
  created_at: string;
}

/**
 * Rest-day work requests — permission to work a day the employee's schedule calls a rest day.
 *
 * Unlike every other request here, there is no form: it is filed by pressing Time in on a rest
 * day. `requested_at` is that press, and approving stamps attendance at it — so the field is the
 * paid time-in, not metadata about the row.
 *
 * `expired` is its own status: a pending request goes stale 24h after `requested_at`, because
 * approving one later would back-date a shift.
 */
export interface RestDayRequest {
  id: number;
  work_date: string;
  requested_at: string;
  reason: string | null;
  status: "pending" | "approved" | "rejected" | "cancelled" | "expired";
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancel_reason: string | null;
  employee: EmployeeRef;
  created_at: string;
}

// Early-out (undertime) requests — separate from leave, always unpaid: the employee
// leaves before their scheduled end. Payroll deducts the proportional undertime.
export interface EarlyOutRequest {
  id: number;
  work_date: string;
  early_out_time: string; // HH:MM (24h)
  reason: string;
  status: string;
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancel_reason: string | null;
  employee: EmployeeRef;
  created_at: string;
}

// Overtime requests — hours worked BEYOND the schedule, filed as a time window. Opposite sign
// to early-out: an approved one ADDS pay at the rate for that day's classification. `hours` is
// derived from the window server-side, never typed.
export interface OvertimeRequest {
  id: number;
  work_date: string;
  time_from: string | null; // HH:MM (24h)
  time_to: string | null;
  hours: number;
  reason: string;
  status: string;
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancel_reason: string | null;
  employee: EmployeeRef;
  created_at: string;
}

/** Company overtime setting, as it affects FILING a request. */
export interface OvertimeSettings {
  mode: "none" | "approved_only" | "auto_after_threshold";
  requests_enabled: boolean;
  note: string;
}

/**
 * One day of auto-mode overtime the policy caps held back (FIN-039).
 *
 * `paid_minutes` pays on the run regardless; `excess_minutes` pays only once an overtime request for
 * that date is approved. So this is not a list of rejections — it is a list of overtime that is
 * waiting for somebody to say yes, which is usually either a genuine long evening or a forgotten
 * clock-out. `raw_minutes` is the whole overrun, so the two cases are easy to tell apart.
 */
export interface AutoOvertimeReviewItem {
  employee_id: number;
  employee_no: string | null;
  name: string | null;
  work_date: string;
  paid_minutes: number;
  excess_minutes: number;
  raw_minutes: number;
  approved_minutes: number;
}

/** How a date's overtime is priced: regular day / rest day / special day / regular holiday. */
export interface OvertimeClassification {
  code: "regular_day" | "rest_day" | "special_day" | "regular_holiday";
  label: string;
  rate_pct: number;
  is_workday: boolean;
  /** That day's shift end (HH:MM) — overtime can only start after it. Null on a rest day. */
  schedule_end: string | null;
}

export interface LeaveBalance {
  id: number;
  year: number;
  entitled_days: number;
  used_days: number;
  remaining_days: number;
  leave_type: { id: number; name: string; code: string; color: string | null };
}

// Shared-mode: one credit pool per employee, spent across any leave type.
export interface LeavePool {
  entitled_days: number;
  used_days: number;
  remaining_days: number;
}

// GET /hr/me/leave-balances — mode-aware. In not_shared, `balances` holds the
// per-type credits; in shared, `pool` holds the single balance (and `balances`
// is ignored by the UI).
export interface MyLeaveSummary {
  mode: AllotmentMode;
  balances: LeaveBalance[];
  pool: LeavePool | null;
}

export interface LeaveRequest {
  id: number;
  employee: EmployeeRef;
  leave_type: { id: number; name: string; code: string; color: string | null };
  start_date: string;
  end_date: string;
  is_half_day: boolean;
  half_day_period: string | null;
  days: number;
  reason: string;
  status: "pending" | "approved" | "rejected" | "cancelled";
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancel_reason: string | null;
  created_at: string;
}

export interface StoreLeaveInput {
  leave_type_id: number;
  start_date: string;
  end_date: string;
  reason: string;
  // FIN-034 — half a day of THIS leave type: 0.5 off the balance, paid when the type is paid.
  // Omitted for a full-day request; the period is only sent alongside a true flag.
  is_half_day?: boolean;
  half_day_period?: "AM" | "PM";
}
