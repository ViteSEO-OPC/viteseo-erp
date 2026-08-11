export const EXPENSE_CATEGORIES = [
  { value: "utilities", label: "Utilities" },
  { value: "supplies", label: "Supplies" },
  { value: "equipment", label: "Equipment" },
  { value: "maintenance", label: "Maintenance" },
  { value: "events", label: "Events" },
  { value: "rent", label: "Rent" },
  { value: "other", label: "Other" },
] as const;

export interface OfficeExpense {
  id: number;
  category: string;
  amount: number;
  expense_date: string;
  description: string;
  vendor: string | null;
  receipt_url: string | null;
  status: string;
  review_note: string | null;
  recorded_by: string | null;
  reviewed_by: string | null;
  reviewed_at: string | null;
  created_at: string;
}

/** Review states, mirroring `OfficeExpenseService::STATUSES`. */
export const EXPENSE_STATUSES = [
  { value: "recorded", label: "Recorded — awaiting review" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Declined" },
] as const;

export interface OfficeExpenseInput {
  category: string;
  amount: number;
  expense_date: string;
  description: string;
  vendor: string;
  status: string;
  review_note: string;
}

export function expenseStatusLabel(s: string): string {
  return EXPENSE_STATUSES.find((x) => x.value === s)?.label ?? s;
}

export function expenseCategoryLabel(c: string): string {
  return EXPENSE_CATEGORIES.find((x) => x.value === c)?.label ?? c;
}
