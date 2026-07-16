// Company → Leave Type (Phase-2 Slice 13). Reuses LeaveType/LeaveBalance from
// types/leave.ts; adds the allotment-default and config-input shapes.

export interface LeaveTypeAllotment {
  id: number;
  leave_type_id: number;
  employment_status: string;
  days_per_year: number;
  leave_type?: { id: number; name: string; code: string; color: string | null };
}

export interface LeaveTypeInput {
  name: string;
  code: string;
  days_per_year: number;
  is_paid: boolean;
  color?: string | null;
}

// The employment statuses leave allotments are keyed on (mirrors the backend
// EmployeeService::STATUSES). Labels for display.
export const EMPLOYMENT_STATUSES = [
  { value: "regular", label: "Regular" },
  { value: "probationary", label: "Probationary" },
  { value: "contract", label: "Contract" },
  { value: "part_time", label: "Part-time" },
] as const;
