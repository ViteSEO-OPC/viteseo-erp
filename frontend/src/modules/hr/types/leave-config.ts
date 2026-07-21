// Company → Leave Type (Phase-2 Slice 13). Reuses LeaveType/LeaveBalance from
// types/leave.ts; adds the allotment-default and config-input shapes.

export interface LeaveTypeAllotment {
  id: number;
  leave_type_id: number;
  employment_status: string;
  days_per_year: number;
  leave_type?: { id: number; name: string; code: string; color: string | null };
}

// Company-wide leave allotment model:
//   not_shared = each leave type has its own per-status credits (default)
//   shared     = one pool per employment status, spent across any leave type
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
  allow_backdated: boolean;
  backdate_limit_days?: number | null;
}

// The employment statuses leave allotments are keyed on (mirrors the backend
// EmployeeService::STATUSES). Labels for display.
export const EMPLOYMENT_STATUSES = [
  { value: "regular", label: "Regular" },
  { value: "probationary", label: "Probationary" },
  { value: "contract", label: "Contract" },
  { value: "part_time", label: "Part-time" },
] as const;
