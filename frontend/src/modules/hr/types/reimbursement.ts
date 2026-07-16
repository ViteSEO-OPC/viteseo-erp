export const REIMBURSEMENT_CATEGORIES = [
  "travel",
  "meals",
  "supplies",
  "equipment",
  "software",
  "training",
  "other",
] as const;

export type ReimbursementStatus = "pending" | "approved" | "rejected" | "paid";

export interface Reimbursement {
  id: number;
  employee: { id: number; name: string | null };
  category: string;
  amount: string; // raw decimal; format for display
  expense_date: string;
  description: string;
  receipt_url: string | null;
  status: ReimbursementStatus;
  reviewed_at: string | null;
  reviewed_by: string | null;
  created_at: string;
}

export function categoryLabel(category: string): string {
  return category.charAt(0).toUpperCase() + category.slice(1);
}
