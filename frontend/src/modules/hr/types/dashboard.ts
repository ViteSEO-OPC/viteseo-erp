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

/**
 * Every kind DashboardService::recentActivity() emits. It listed three of them while the API
 * had long since been sending nine, so TypeScript believed the icon map was exhaustive and the
 * six it did not know about rendered as an unstyled grey circle.
 */
export type ActivityType =
  | "leave"
  | "reimbursement"
  | "hire"
  | "half_day"
  | "early_out"
  | "overtime"
  | "loan"
  | "separation";

export interface ActivityItem {
  type: ActivityType;
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
