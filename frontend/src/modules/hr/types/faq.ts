export const FAQ_CATEGORIES = [
  { value: "payroll", label: "Payroll" },
  { value: "leave", label: "Leave" },
  { value: "attendance", label: "Attendance" },
  { value: "benefits", label: "Benefits" },
  { value: "general", label: "General" },
] as const;

export interface Faq {
  id: number;
  question: string;
  answer: string;
  category: string;
  tags: string[];
  status: string;
  is_pinned: boolean;
  view_count: number;
  updated_by: string | null;
  updated_at: string;
}

export interface FaqInput {
  question: string;
  answer: string;
  category: string;
  tags: string[];
  is_pinned: boolean;
}

export function faqCategoryLabel(c: string): string {
  return FAQ_CATEGORIES.find((x) => x.value === c)?.label ?? c;
}
