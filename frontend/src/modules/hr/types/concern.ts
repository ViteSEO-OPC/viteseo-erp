// Phase-2 Slice 21 — concerns + meeting scheduling (privacy-critical).

export const CONCERN_CATEGORIES = [
  { value: "conduct", label: "Workplace Conduct" },
  { value: "harassment", label: "Harassment" },
  { value: "safety", label: "Safety & Security" },
  { value: "policy", label: "Policy Violation" },
  { value: "other", label: "Other" },
] as const;

export const CONCERN_STATUSES = [
  { value: "pending", label: "Pending" },
  { value: "under_review", label: "Under review" },
  { value: "resolved", label: "Resolved" },
] as const;

export interface Meeting {
  id: number;
  scheduled_at: string;
  location: string | null;
  agenda: string | null;
  status: string;
  invite_response_at: string | null;
  concern: {
    id: number;
    reference_no: string;
    category: string;
    is_anonymous: boolean;
  } | null;
}

export interface ConcernAttachment {
  name: string;
  url: string;
  size: number | null;
  mime: string | null;
}

export interface Concern {
  id: number;
  reference_no: string;
  category: string;
  description: string;
  reported_person: string | null;
  attachments: ConcernAttachment[];
  is_anonymous: boolean;
  is_safety: boolean;
  status: string;
  submitted_at: string;
  resolved_at: string | null;
  // null for anonymous concerns — the backend never emits identity for them.
  employee: { id: number; name: string | null } | null;
  meetings: Meeting[];
  created_at: string;
}

export function concernCategoryLabel(c: string): string {
  return CONCERN_CATEGORIES.find((x) => x.value === c)?.label ?? c;
}
