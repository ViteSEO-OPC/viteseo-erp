// Phase-2 — concerns + meeting scheduling (privacy-critical).

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

/** HR asking an anonymous reporter to identify themselves — and their answer. */
export interface ConcernRevealRequest {
  id: number;
  status: "pending" | "accepted" | "declined";
  reason: string;
  requested_by: string | null;
  requested_at: string;
  responded_at: string | null;
  decline_reason: string | null;
}

export interface Concern {
  id: number;
  reference_no: string;
  category: string;
  description: string;
  reported_person: string | null;
  attachments: ConcernAttachment[];
  is_anonymous: boolean;
  /**
   * When the reporter agreed to be named. Null means HR sees `Anonymous` — including when
   * nobody has asked yet, and when the reporter said no.
   */
  identity_revealed_at: string | null;
  is_safety: boolean;
  status: string;
  submitted_at: string;
  resolved_at: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancel_reason: string | null;
  /**
   * Null for an anonymous concern until its reporter has ACCEPTED a reveal request. The old
   * rule handed every anonymous identity to HR for free; now the eye asks rather than reveals.
   */
  employee: { id: number; name: string | null } | null;
  /** The newest ask on this concern, or null — for a named concern, or one nobody has asked about. */
  reveal_request: ConcernRevealRequest | null;
  meetings: Meeting[];
  created_at: string;
}

export function concernCategoryLabel(c: string): string {
  return CONCERN_CATEGORIES.find((x) => x.value === c)?.label ?? c;
}
