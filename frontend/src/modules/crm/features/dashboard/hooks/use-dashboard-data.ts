"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { LEAD_STATUS_CONFIG, type LeadStatusKey } from "@/modules/crm/lib/lead-status";
import {
  useMyPerformanceSummary,
  formatConversionRate,
} from "@/modules/crm/features/performance/hooks/use-my-performance";
import type {
  KpiStat,
  PriorityBucket,
  PriorityLevel,
  LeadRow,
  ActivityItem,
  ActivityType,
} from "@/modules/crm/features/dashboard/types/dashboard-types";
import type { WebsiteStatus } from "@/modules/crm/features/leads/types/lead-types";

// KPI card metadata (labels + styling preserved from the approved Figma). Values come from the
// backend; honest static captions replace the previous fabricated trend chips (no invented
// numbers/trends). Metrics with no approved backend source show an honest Pending state.
const KPI_BASE: KpiStat[] = [
  { id: "total-leads", label: "TOTAL LEADS", value: "0", valueVariant: "neutral", caption: "In your pipeline" },
  { id: "high-priority", label: "HIGH PRIORITY", value: "0", valueVariant: "neutral", caption: "Flagged high priority" },
  // Planner-backed: the whole scoped pending follow-up set. An outbound email is NOT a follow-up
  // (PO ruling) — only real Planner `follow-up` events count.
  { id: "follow-ups-due", label: "FOLLOW-UPS DUE", value: "0", valueVariant: "neutral", caption: "Overdue, today and upcoming" },
  // Outbound emails the system actually sent — an effort count, not lead coverage (coverage is
  // M-04 Engagement Coverage, a deliberately separate metric). Emailing one lead three times
  // counts three times.
  { id: "completed-outreach", label: "COMPLETED OUTREACH", value: "0", valueVariant: "neutral", caption: "Outbound emails sent" },
  { id: "interested-leads", label: "INTERESTED LEADS", value: "0", valueVariant: "neutral", caption: "Marked interested" },
  { id: "conversion-rate", label: "CONVERSION RATE", value: "—", valueVariant: "neutral", caption: "Of engaged leads" },
];

type KpiApi = {
  totalLeads: number;
  highPriority: number;
  interested: number;
  followUpsDue: number;
  completedOutreach: number;
};

export function useDashboardKpis() {
  const query = useQuery<KpiApi>({
    queryKey: queryKeys.dashboard.kpis(),
    queryFn: () => apiClient.get("/dashboard/kpis").then((r) => r.data.data),
  });
  // Conversion Rate reuses the ownership-scoped GET /dashboard/performance-summary (converted ÷
  // engaged) — for a BD, their own portfolio. Shared query key → no duplicate request/computation.
  const { data: summary } = useMyPerformanceSummary();

  const api = query.data;
  const data: KpiStat[] = KPI_BASE.map((kpi) => {
    switch (kpi.id) {
      case "total-leads":
        return { ...kpi, value: api ? String(api.totalLeads) : kpi.value, href: "/zerem/leads" };
      case "high-priority":
        return { ...kpi, value: api ? String(api.highPriority) : kpi.value, href: "/zerem/leads?priority=high" };
      case "interested-leads":
        return { ...kpi, value: api ? String(api.interested) : kpi.value, href: "/zerem/leads?status=interested" };
      case "follow-ups-due":
        // Straight from the backend — the count is never recomputed here, so this card and the
        // reports page's overdue/today/upcoming buckets always agree.
        return { ...kpi, value: api ? String(api.followUpsDue) : kpi.value, href: "/zerem/planner" };
      case "completed-outreach":
        return { ...kpi, value: api ? String(api.completedOutreach) : kpi.value };
      case "conversion-rate":
        return summary ? { ...kpi, value: formatConversionRate(summary.conversionRate) } : kpi;
      default:
        return kpi;
    }
  });

  return { data, isLoading: query.isLoading, isError: query.isError };
}

// Real lead-status counts (the actual `status` enum — DB is the source of truth). The backend
// already emits these as `statusCounts` on GET /dashboard/kpis (a GROUP BY status over
// Lead::visibleTo). Admin/SA see the whole CRM. Keyed by the centralized status taxonomy.
export type StatusCounts = Record<LeadStatusKey, number>;

/**
 * Live "Leads by Status" breakdown for the Management Dashboard. Reuses the SAME query as
 * `useDashboardKpis` (identical `queryKeys.dashboard.kpis()` + `/dashboard/kpis`), so React Query
 * dedupes to a single request and the GROUP BY aggregation is never duplicated. Order, labels,
 * and colors come from the centralized `LEAD_STATUS_CONFIG` (single source of truth); every count
 * comes straight from the API.
 */
export function useLeadStatusBreakdown() {
  const query = useQuery<{ statusCounts: StatusCounts }>({
    queryKey: queryKeys.dashboard.kpis(),
    queryFn: () => apiClient.get("/dashboard/kpis").then((r) => r.data.data),
  });

  const counts = query.data?.statusCounts;
  const data = counts
    ? LEAD_STATUS_CONFIG.map((s) => ({ label: s.label, value: counts[s.value], color: s.color }))
    : undefined;

  return { data, isLoading: query.isLoading, isError: query.isError };
}

// Static per-website_status card copy (from the approved Figma); merged with backend metrics.
// `tone` is the existing colour/chip slot, kept so the cards look identical to before.
const WEBSITE_STATUS_META: Record<WebsiteStatus, { title: string; description: string; tone: PriorityLevel }> = {
  "no-website": { title: "No existing website", description: "Businesses with no web presence—ideal for full web and SEO packages.", tone: "high" },
  "needs-redesign": { title: "Website needs improvement", description: "Outdated or slow sites. Ideal for redesign plus retainer-based SEO offers.", tone: "medium" },
  "has-website": { title: "Has website, open to a new one", description: "Functional site owners exploring a refresh. Nurture with case studies.", tone: "low" },
};

type PriorityBucketApi = { websiteStatus: WebsiteStatus; count: number; avgScore: number; pipelinePct: number };

/**
 * Website-opportunity buckets (GET /dashboard/priority-buckets) — the SINGLE source for the Lead
 * Priority Overview cards on BOTH the Dashboard (entry point) and the Leads page (workspace).
 *
 * `filters` is optional: the Dashboard passes nothing and gets the unfiltered segmentation, while
 * the Leads page passes its current table filters so the cards stay in step with the list. The
 * endpoint deliberately ignores `website_status` itself, so drilling into one bucket never zeroes
 * the other two.
 */
export function usePriorityBuckets(filters: Record<string, string> = {}) {
  const query = useQuery<PriorityBucketApi[]>({
    queryKey: queryKeys.dashboard.priorityBuckets(filters),
    queryFn: () =>
      apiClient
        .get("/dashboard/priority-buckets", { params: filters })
        .then((r) => r.data.data),
    // Keep the previous counts on screen while a filter change refetches (no card flicker).
    placeholderData: (prev) => prev,
  });

  const data: PriorityBucket[] | undefined = query.data?.map((bucket) => ({
    ...bucket,
    ...WEBSITE_STATUS_META[bucket.websiteStatus],
  }));

  return { data, isLoading: query.isLoading, isError: query.isError };
}

type RecentLeadApi = {
  id: string;
  business: string;
  industry: string | null;
  score: number;
  priority: PriorityLevel;
  updateStatus: string;
};

export function useRecentLeads() {
  const query = useQuery<{ data: RecentLeadApi[]; meta: { total: number } }>({
    queryKey: queryKeys.dashboard.recentLeads(),
    queryFn: () => apiClient.get("/dashboard/recent-leads").then((r) => r.data),
  });

  const data: LeadRow[] | undefined = query.data?.data.map((lead) => ({
    id: lead.id,
    business: lead.business,
    industry: lead.industry ?? "",
    score: lead.score,
    priority: lead.priority,
    status: lead.updateStatus as LeadRow["status"],
  }));

  return {
    data,
    total: query.data?.meta?.total,
    isLoading: query.isLoading,
    isError: query.isError,
  };
}

// Live activity feed from the generic GET /activities endpoint (append-only Activity log
// written by model observers). Sprint 6 exposes only lead lifecycle events.
// Exported so the full-page Activity Logs reuses the exact same activity shape + mapping
// (mapActivity) as the Dashboard Recent Activity widget — one source of truth, no duplication.
export type ActivityApi = {
  id: string;
  event:
    | "created"
    | "updated"
    | "deleted"
    | "email.sent"
    | "email.received"
    | "lead.assigned"
    | "auth.login"
    | "auth.logout"
    | "note.created"
    | "note.updated"
    | "note.deleted"
    | "call.started"
    | "call.completed"
    // Sales outcome written by LeadOutcomeController::won alongside the Deal.
    | "lead.won";
  description: string;
  actor: string | null;
  changes: { changed?: string[]; from?: string; from_name?: string } | null;
  timestamp: string;
  subject: { type: string; id: string; label: string | null } | null;
};

// Per-event icon, derived from the existing `event` field (no backend/API change): email
// events use the envelope tile, authentication events (login/logout) use their own session
// tile, everything else keeps the shared AI/lifecycle glyph.
// Exported so the per-lead Activity Timeline reuses the SAME event→icon/color mapping as the
// Dashboard Recent Activity and Activity Logs surfaces (one source of truth for activity glyphs).
export function activityIcon(event: ActivityApi["event"]): ActivityType {
  if (event === "email.sent" || event === "email.received") return "email";
  if (event === "auth.login" || event === "auth.logout") return "auth";
  if (event === "note.created" || event === "note.updated" || event === "note.deleted") return "note";
  if (event === "call.started" || event === "call.completed") return "call";
  // Sales outcome — the Deal-creating event behind the Won badge on Lead Details.
  if (event === "lead.won") return "won";

  return "ai";
}

// Human labels for the fields we currently log changes on (properties.changed).
const CHANGED_FIELD_LABELS: Record<string, string> = {
  website_status: "Website Status",
  status: "Lead Status",
  priority: "Priority",
};

function humanizeField(field: string): string {
  return (
    CHANGED_FIELD_LABELS[field] ??
    field.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
  );
}

// Generic event → human verb map. New Activity event types (lead.*, email.*, call.logged,
// meeting.scheduled, note.added, task.completed, …) render by adding one entry here — the
// Dashboard stays consumer-agnostic and needs no structural change. Unknown events fall back
// to a humanized form of the event key so nothing ever renders blank.
const EVENT_VERBS: Record<string, string> = {
  created: "created",
  updated: "updated",
  deleted: "deleted",
  "email.sent": "emailed",
  "email.received": "replied to",
  "lead.assigned": "assigned",
  "auth.login": "logged in",
  "auth.logout": "logged out",
  "note.created": "added a note on",
  "note.updated": "edited a note on",
  "note.deleted": "deleted a note on",
  "call.started": "started a call with",
  "call.completed": "logged a call with",
};

function eventVerb(event: string): string {
  return EVENT_VERBS[event] ?? event.replace(/[._]/g, " ");
}

// Produces the middle clause of "{actor} {action} {object}".
function activityAction(api: ActivityApi): string {
  // Field-level lead updates keep their detailed clause ("changed Priority on").
  if (api.event === "updated") {
    const changed = api.changes?.changed ?? [];
    if (changed.length > 0) return `changed ${changed.map(humanizeField).join(", ")} on`;
  }
  return eventVerb(api.event);
}

export function mapActivity(api: ActivityApi): ActivityItem {
  // Inbound replies have no CRM causer — attribute them to the sender ("Prospect replied to
  // {Company}") rather than the generic "System". Every other event keeps causer-as-actor.
  const actor =
    api.event === "email.received"
      ? api.changes?.from_name ?? api.changes?.from ?? "A contact"
      : api.actor ?? "System";

  return {
    id: api.id,
    type: activityIcon(api.event),
    actor,
    action: activityAction(api),
    // Lead events always have a subject (fall back to a generic noun if unlabeled);
    // subject-less events (auth login/logout) render as "{actor} {action}" with no object.
    object: api.subject ? api.subject.label ?? "a lead" : "",
    timestamp: api.timestamp,
  };
}

// NOTE: the "Copilot AI summary" row (GET /dashboard/copilot-summary) is intentionally NOT consumed.
// That endpoint returns fixed placeholder counts for an AI-scoring run that does not exist yet, so
// surfacing it would display fabricated business information. It stays hidden until a real AI scoring
// run produces genuine metrics — Recent Activity shows only the real, ownership-scoped activity feed.

/**
 * Shared live activity feed over the ownership-scoped `GET /activities` endpoint — the SAME
 * query + `Activity::scopeVisibleTo` the Activity Logs page uses, so there is one source of
 * truth and no separate filtering logic. Returns mapped, newest-first `ActivityItem`s
 * (optionally capped to `limit`). Reused by both the BD Dashboard widget and the Admin/SA
 * Management Dashboard widget; the shared query key keeps it in sync with lead/outreach
 * mutation invalidations.
 */
// `userId` scopes the feed to one BD for the Reports & Performance "Recent Activity" widget (Admin/SA
// only, enforced server-side). Dashboard/My-Performance callers omit it → the requester's own feed,
// unchanged. Keyed by the scope so per-BD feeds never collide with the default.
export function useActivityFeed(limit?: number, userId?: string) {
  const query = useQuery<{ data: ActivityApi[] }>({
    queryKey: queryKeys.dashboard.recentActivity(userId ? `bd:${userId}` : "self"),
    queryFn: () =>
      apiClient
        .get("/activities", { params: userId ? { user_id: userId } : {} })
        .then((r) => r.data),
  });

  const rows = query.data?.data;
  const data = rows
    ? (limit === undefined ? rows : rows.slice(0, limit)).map(mapActivity)
    : undefined;

  return { data, isLoading: query.isLoading, isError: query.isError };
}

export function useRecentActivity() {
  // Only the real, ownership-scoped activity feed. The Copilot summary row was removed — it showed
  // fixed placeholder counts (no real AI-scoring run exists), and fabricated metrics must not appear.
  // Intentional dashboard scope: the 12 most-recent activities (GET /activities is newest-first).
  // The fixed-height list shows ~5 and scrolls the rest; "Full history" remains the path to the
  // complete Activity Logs. This is a deliberate widget cap, not a backend limit.
  const feed = useActivityFeed(12);

  return {
    data: feed.data,
    isLoading: feed.isLoading,
    isError: feed.isError,
  };
}
