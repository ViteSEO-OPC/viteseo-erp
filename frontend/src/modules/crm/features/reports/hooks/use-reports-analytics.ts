"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import {
  scopeKey,
  scopeToParams,
  usePerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";

/**
 * Reports & Performance management analytics — the deeper aggregate the Management surface renders
 * below the KPI row. ONE ownership-scoped read (`GET /dashboard/reports-analytics`) drives every
 * section, so they all follow the active scope chip (All Reports, or a specific BD via `?user_id`)
 * and are cached separately per scope. Every figure is server-computed; the frontend only formats and
 * lays out — no client-side business rule. Definitions live in the controller docblock.
 */

export type FunnelStage = { key: string; label: string; value: number };
export type BiggestDropOff = { from: string; to: string; dropPct: number } | null;

export type OutreachChannel = {
  completed: number;
  inProgress: number;
  notStarted: number;
  total: number;
};

export type PendingFollowUpRow = {
  id: number;
  status: "overdue" | "today" | "upcoming";
  company: string;
  description: string;
  dueDate: string | null;
};

export type ReportsAnalytics = {
  funnel: { stages: FunnelStage[]; biggestDropOff: BiggestDropOff };
  /** Raw counts keyed by status; the UI maps labels/colors from the central LEAD_STATUS_CONFIG. */
  statusMix: Record<string, number>;
  outreachByChannel: { email: OutreachChannel; call: OutreachChannel };
  niche: { niche: string; value: number }[];
  followThrough: {
    leadsContacted: number;
    consultations: number;
    confirmedClients: number;
    missedFollowUps: number;
    missedMeetings: number;
    missedCalls: number;
    /** Completed outreach ÷ total across both channels; `null` when there is no outreach yet. */
    outreachCompletionPct: number | null;
    /** true → goal denominators are intentionally omitted (Goal Management is a later sprint). */
    targetsPending: boolean;
  };
  pendingFollowUps: {
    counts: { overdue: number; today: number; upcoming: number };
    rows: PendingFollowUpRow[];
  };
};

export function useReportsAnalytics() {
  const scope = usePerformanceScope();
  return useQuery<ReportsAnalytics>({
    queryKey: queryKeys.reports.analytics(scopeKey(scope)),
    queryFn: () =>
      apiClient
        .get("/dashboard/reports-analytics", { params: scopeToParams(scope) })
        .then((r) => r.data.data),
  });
}
