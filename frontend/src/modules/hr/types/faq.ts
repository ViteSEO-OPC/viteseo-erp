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

export const FAQ_STATUSES = [
  { value: "draft", label: "Draft" },
  { value: "published", label: "Published" },
  { value: "archived", label: "Archived" },
] as const;

export interface FaqInput {
  question: string;
  answer: string;
  category: string;
  status: string;
}

export function faqCategoryLabel(c: string): string {
  return FAQ_CATEGORIES.find((x) => x.value === c)?.label ?? c;
}
