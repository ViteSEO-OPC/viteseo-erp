export interface HeadcountPoint {
  month: string;
  joiners: number;
  leavers: number;
  net: number;
}

export interface TrendPoint {
  date: string;
  on_time: number;
  late: number;
}

export interface ActivityItem {
  type: "leave" | "reimbursement" | "hire";
  text: string;
  at: string;
}

export interface HrDashboardStats {
  headcount: number;
  on_leave_today: number;
  open_roles: number;
  next_payroll_date: string;
  pending_leave: number;
  pending_reimbursements: number;
  headcount_chart: HeadcountPoint[];
  attendance_trend: TrendPoint[];
  recent_activity: ActivityItem[];
}
