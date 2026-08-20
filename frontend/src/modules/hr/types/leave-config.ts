// Company → Leave Type (Phase-2). Reuses LeaveType/LeaveBalance from
// types/leave.ts; adds the allotment-default and config-input shapes.

import {
  EMPLOYMENT_STATUSES as STATUS_VALUES,
  statusLabel,
} from "@/modules/hr/types/employee";

export interface LeaveTypeAllotment {
  id: number;
  leave_type_id: number;
  employment_status: string;
  days_per_year: number;
  leave_type?: { id: number; name: string; code: string; color: string | null };
}

// Company-wide leave allotment model:
// not_shared = each leave type has its own per-status credits (default)
// shared     = one pool per employment status, spent across any leave type
export type AllotmentMode = "not_shared" | "shared";

export interface LeaveSetting {
  allotment_mode: AllotmentMode;
}

// Shared-mode default pool size for one employment status.
export interface SharedAllotment {
  id: number;
  employment_status: string;
  days_per_year: number;
}

// Shared-mode: one employee's actual pool balance for the year (the editable
// override, mirroring per-type balances).
export interface EmployeeSharedBalance {
  id: number;
  year: number;
  entitled_days: number;
  used_days: number;
  remaining_days: number;
}

export interface LeaveTypeInput {
  name: string;
  code: string;
  is_paid: boolean;
  color?: string | null;
  description?: string | null;
  allow_backdated: boolean;
  backdate_limit_days?: number | null;
  // Accrual settings. Declared here so the dialog's payload is type-checked rather than
  // silently carrying fields the API contract doesn't mention.
  accrual_mode?: "none" | "monthly" | "annual";
  prorate_first_year?: boolean;
  carry_over_cap_days?: number | null;
  carry_over_expiry_months?: number | null;
}

// The employment statuses leave allotments are keyed on — derived from the one
// list in types/employee.ts so a new status can't appear on the employee record
// without a column in the allotment grids.
export const EMPLOYMENT_STATUSES = STATUS_VALUES.map((value) => ({
  value,
  label: statusLabel(value),
}));
