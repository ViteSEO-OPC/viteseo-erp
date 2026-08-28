import type { EmployeeRef } from "@/modules/hr/types/employee";

export const REIMBURSEMENT_CATEGORIES = [
  "travel",
  "meals",
  "supplies",
  "equipment",
  "software",
  "training",
  "other",
] as const;

/**
 * pending → approved → scheduled → paid.
 * `scheduled` = folded into a payslip that hasn't been disbursed yet. Computing
 * payroll no longer marks a claim paid; disbursing the payslip does.
 */
export type ReimbursementStatus =
  | "pending"
  | "approved"
  | "scheduled"
  | "rejected"
  | "paid"
  // Withdrawn by the employee before HR decided it — not a rejection.
  | "cancelled";

export interface Reimbursement {
  id: number;
  employee: EmployeeRef;
  category: string;
  amount: string; // raw decimal; format for display
  expense_date: string;
  description: string;
  receipt_urls: string[];
  receipt_url: string | null; // first receipt, or null (back-compat)
  status: ReimbursementStatus;
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancel_reason: string | null;
  created_at: string;
}

export function categoryLabel(category: string): string {
  return category.charAt(0).toUpperCase() + category.slice(1);
}

/**
 * The queue's figures per status, counted by the DATABASE — `{ pending: { count, amount } }`.
 *
 * A status nobody has filed is absent rather than zero, so read it through a helper that
 * supplies the zero. Counted server-side because the screen holds one page: filtering the rows
 * in hand would describe that page while looking exactly as authoritative.
 */
export type ReimbursementSummary = Record<string, { count: number; amount: number }>;
