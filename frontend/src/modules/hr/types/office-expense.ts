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

export interface OfficeExpenseInput {
  category: string;
  amount: number;
  expense_date: string;
  description: string;
  vendor: string;
}

export function expenseCategoryLabel(c: string): string {
  return EXPENSE_CATEGORIES.find((x) => x.value === c)?.label ?? c;
}
