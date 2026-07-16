"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { EmailThreadMessage } from "@/modules/crm/features/leads/types/lead-detail-types";

/**
 * The Email API shape returned by GET /leads/{lead}/emails (EmailResource). Only the fields the
 * read-only thread renders are typed here.
 */
type EmailApi = {
  id: string;
  direction: "inbound" | "outbound";
  subject: string;
  body: string;
  status: string;
  timestamp: string | null;
  sentAt: string | null;
  receivedAt: string | null;
  fromName: string | null;
  fromEmail: string | null;
  to: string | null;
  senderName: string | null;
};

/**
 * Live, read-only Email Thread for a single lead. Reuses the per-lead endpoint
 * `GET /leads/{lead}/emails` (ownership-enforced by the route's `view` policy; chronologically
 * ordered server-side) and the EXISTING Email model / EmailResource — no mapping logic is
 * duplicated on the backend. The thread belongs to Lead Detail only; it never composes or mutates.
 *
 * Keyed under `leads.emails(id)` (nested below `leads.detail(id)`), so invalidating the lead's
 * detail also refreshes the thread by prefix match.
 */
export function useLeadEmails(id: string) {
  const query = useQuery<{ data: EmailApi[]; meta?: { total: number; per_page: number } }>({
    queryKey: queryKeys.leads.emails(id),
    queryFn: () => apiClient.get(`/leads/${id}/emails`).then((r) => r.data),
    enabled: !!id,
  });

  // Server returns messages oldest → newest (a natural conversation order); keep it.
  const data: EmailThreadMessage[] | undefined = query.data?.data.map((api) => ({
    id: api.id,
    direction: api.direction,
    subject: api.subject,
    body: api.body,
    status: api.status,
    timestamp: api.timestamp ?? api.receivedAt ?? api.sentAt ?? "",
    fromName: api.fromName,
    fromEmail: api.fromEmail,
    to: api.to,
    senderName: api.senderName,
  }));

  // The endpoint paginates at 20; if a lead has more, the first (oldest) page renders and
  // `hasMore` signals that a future "load more" affordance can page further (RC2 shows page 1).
  const total = query.data?.meta?.total ?? data?.length ?? 0;
  const hasMore = data !== undefined && total > data.length;

  return { data, hasMore, isLoading: query.isLoading, isError: query.isError };
}
