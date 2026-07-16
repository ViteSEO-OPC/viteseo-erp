export interface LeaveType {
  id: number;
  name: string;
  code: string;
  days_per_year: number;
  is_paid: boolean;
  color: string | null;
  /** Whether this type may be filed for past dates at all. */
  allow_backdated: boolean;
  /** Max days a past-dated request may reach back; null = no limit (only when allow_backdated). */
  backdate_limit_days: number | null;
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
  employee: { id: number; name: string | null };
  created_at: string;
}

export interface LeaveBalance {
  id: number;
  year: number;
  entitled_days: number;
  used_days: number;
  remaining_days: number;
  leave_type: { id: number; name: string; code: string; color: string | null };
}

export interface LeaveRequest {
  id: number;
  employee: { id: number; name: string | null };
  leave_type: { id: number; name: string; code: string; color: string | null };
  start_date: string;
  end_date: string;
  is_half_day: boolean;
  half_day_period: string | null;
  days: number;
  reason: string;
  status: "pending" | "approved" | "rejected";
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by: string | null;
  created_at: string;
}

export interface StoreLeaveInput {
  leave_type_id: number;
  start_date: string;
  end_date: string;
  reason: string;
}
