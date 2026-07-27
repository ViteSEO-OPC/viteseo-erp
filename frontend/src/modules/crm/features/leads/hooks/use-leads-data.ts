"use client";

import { useState, useCallback } from "react";
import { useSearchParams, useRouter, usePathname } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";

type LeadApiResponse = {
  data: Lead[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  // Real per-industry lead totals across the whole dataset (from the API's SQL GROUP BY) —
  // industry IS the niche. Powers the accurate, page-independent chip counts + distribution bar.
  industry_counts?: Record<string, number>;
};

// Canonical filter values — these mirror the backend whitelists EXACTLY and are the only values any
// filter UI may offer:
//   status   → IndexLeadRequest::STATUSES
//   priority → IndexLeadRequest::rules()['priority'] (Rule::in)
// Exported so the column filters render their options from this single source rather than restating
// them. If the backend whitelist changes, changing it here updates every filter surface at once.
export const LEAD_STATUS_VALUES = [
  "interested",
  "awaiting-reply",
  "declined",
  "call-back-req",
  "new",
] as const;

export const LEAD_PRIORITY_VALUES = ["high", "medium", "low"] as const;

// Communication-lifecycle columns. Same contract: these mirror IndexLeadRequest's `email_outreach`
// and `cold_calling` rules, which in turn reuse the write-side whitelists in
// StoreLeadRequest/UpdateLeadRequest. Single-value filters, like `priority`.
export const LEAD_EMAIL_OUTREACH_VALUES = ["sent", "opened", "replied", "none"] as const;

export const LEAD_COLD_CALLING_VALUES = ["called", "scheduled", "not-called"] as const;

// Sentinel for "no owner" on the assigned_to filter, mirroring IndexLeadRequest::UNASSIGNED.
// Maps to `assigned_to IS NULL`, which the table already renders as "Unassigned".
export const LEAD_UNASSIGNED = "unassigned";

const VALID_STATUSES: readonly string[] = LEAD_STATUS_VALUES;
const VALID_PRIORITIES: readonly string[] = LEAD_PRIORITY_VALUES;
const VALID_EMAIL_OUTREACH: readonly string[] = LEAD_EMAIL_OUTREACH_VALUES;
const VALID_COLD_CALLING: readonly string[] = LEAD_COLD_CALLING_VALUES;

// Sort options for the toolbar dropdown. `value` keys MUST match the backend whitelist
// (IndexLeadRequest::SORTS + LeadController@index mapping). `recent` is the default and is
// omitted from the URL to keep links clean.
export const LEAD_SORT_OPTIONS = [
  { value: "recent", label: "Most recent" },
  { value: "oldest", label: "Oldest first" },
  { value: "score-desc", label: "Highest score" },
  { value: "score-asc", label: "Lowest score" },
  { value: "name-asc", label: "Business A–Z" },
  { value: "name-desc", label: "Business Z–A" },
] as const;

export const DEFAULT_LEAD_SORT = "recent";
const VALID_SORTS: readonly string[] = LEAD_SORT_OPTIONS.map((o) => o.value);

// Normalize a raw ?status= value (possibly comma-separated) → canonical comma-joined list
// of valid statuses ("" = none). Shared by the list filter and the Lead Status Summary tabs.
function normalizeStatus(raw: string | null): string {
  return (raw ?? "")
    .split(",")
    .map((s) => s.trim())
    .filter((s) => VALID_STATUSES.includes(s))
    .join(",");
}

export function useLeads() {
  // All list filters (search/priority/status) are URL-driven and reactive — the URL is the
  // single source of truth. Summary widgets navigate to a bare drill-down URL, so any filter
  // they don't set is absent from the URL and therefore cleared → displayed count = table.
  const searchParams = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const [page, setPage] = useState(0);

  const search = searchParams.get("search") ?? "";

  // Typing updates ?search= via router.replace (replace = no history spam), preserving the
  // current priority/status. Reads window.location for the current value so the callback stays
  // stable; guarded to skip redundant navigations.
  const setSearch = useCallback(
    (value: string) => {
      if (typeof window === "undefined") return;
      const params = new URLSearchParams(window.location.search);
      if (value === (params.get("search") ?? "")) return;
      if (value.trim()) params.set("search", value);
      else params.delete("search");
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname],
  );

  // Priority filter — URL-driven & reactive. Still supported independently (e.g. toolbar/other links).
  const rawPriority = searchParams.get("priority") ?? "";
  const priority = VALID_PRIORITIES.includes(rawPriority) ? rawPriority : "";

  // Website-status filter — URL-driven & reactive (the overview cards link to
  // /leads?website_status=<status>). Independent of the priority filter above.
  const rawWebsiteStatus = searchParams.get("website_status") ?? "";
  const websiteStatus = ["has-website", "no-website", "needs-redesign"].includes(rawWebsiteStatus)
    ? rawWebsiteStatus
    : "";

  // Status filter — URL-driven & reactive; supports a comma-separated list so a summary tab can
  // map to multiple statuses (CONTACTED = awaiting-reply,call-back-req).
  const status = normalizeStatus(searchParams.get("status"));

  // Email Outreach / Cold Calling filters — URL-driven & reactive, single-value like priority.
  const rawEmailOutreach = searchParams.get("email_outreach") ?? "";
  const emailOutreach = VALID_EMAIL_OUTREACH.includes(rawEmailOutreach) ? rawEmailOutreach : "";

  const rawColdCalling = searchParams.get("cold_calling") ?? "";
  const coldCalling = VALID_COLD_CALLING.includes(rawColdCalling) ? rawColdCalling : "";

  // Assigned BD filter — a user id or the UNASSIGNED sentinel. The valid ids are not a fixed
  // whitelist (they are live accounts), so this accepts the sentinel or any numeric id and lets the
  // API reject an unknown one, exactly as it does for a hand-edited URL.
  const rawAssignedTo = (searchParams.get("assigned_to") ?? "").trim();
  const assignedTo =
    rawAssignedTo === LEAD_UNASSIGNED || /^\d+$/.test(rawAssignedTo) ? rawAssignedTo : "";

  // Niche filter — URL-driven & reactive. SINGLE-SELECT this phase (the chip list is derived from
  // the filtered dataset, so it naturally collapses to one industry once selected — see sprint
  // notes). Industry = Niche: the filter value IS the raw `industry` value (no taxonomy/mapping).
  // Kept as an array (length 0 or 1) so the panel's shared chip-selection rendering is unchanged.
  const industryKey = (searchParams.get("industry") ?? "").trim();
  const selectedIndustries = industryKey === "" ? [] : [industryKey];

  // Select a niche/industry chip: single-select (replace), or clear when the active one is
  // clicked again. Reads the current value from the URL so the callback keeps stable deps.
  const toggleIndustry = useCallback(
    (industry: string) => {
      if (typeof window === "undefined") return;
      const params = new URLSearchParams(window.location.search);
      if ((params.get("industry") ?? "").trim() === industry) params.delete("industry");
      else params.set("industry", industry);
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname],
  );

  // Column-filter setters for Priority and Status. Same URL-driven contract as setSort/setSearch —
  // the URL stays the single source of truth, so the column filters, the Priority Summary cards and
  // the Status Summary tabs all read and write the SAME params and can never disagree.
  // Empty value clears the param (the "All" option).
  // Shared writer for the single-value column filters. A value outside the whitelist (including the
  // empty "All" selection) clears the param, so an unknown value can never reach the API.
  const setSingleValueParam = useCallback(
    (key: string, value: string, allowed: readonly string[]) => {
      if (typeof window === "undefined") return;
      const params = new URLSearchParams(window.location.search);
      if (allowed.includes(value)) params.set(key, value);
      else params.delete(key);
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname],
  );

  const setPriority = useCallback(
    (value: string) => setSingleValueParam("priority", value, VALID_PRIORITIES),
    [setSingleValueParam],
  );

  const setEmailOutreach = useCallback(
    (value: string) => setSingleValueParam("email_outreach", value, VALID_EMAIL_OUTREACH),
    [setSingleValueParam],
  );

  const setColdCalling = useCallback(
    (value: string) => setSingleValueParam("cold_calling", value, VALID_COLD_CALLING),
    [setSingleValueParam],
  );

  // Owner ids are live data, not a fixed whitelist, so this can't reuse setSingleValueParam.
  // Anything that is neither the sentinel nor a numeric id clears the param ("All").
  const setAssignedTo = useCallback(
    (value: string) => {
      if (typeof window === "undefined") return;
      const params = new URLSearchParams(window.location.search);
      if (value === LEAD_UNASSIGNED || /^\d+$/.test(value)) params.set("assigned_to", value);
      else params.delete("assigned_to");
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname],
  );

  // Status accepts a comma-separated list (the backend validates each token), which is what lets a
  // summary tab map to several statuses. Normalized here so an invalid token can never be sent.
  const setStatus = useCallback(
    (value: string) => {
      if (typeof window === "undefined") return;
      const params = new URLSearchParams(window.location.search);
      const normalized = normalizeStatus(value);
      if (normalized) params.set("status", normalized);
      else params.delete("status");
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname],
  );

  // Sort — URL-driven & reactive; falls back to the default when absent/invalid.
  const rawSort = searchParams.get("sort") ?? "";
  const sort = VALID_SORTS.includes(rawSort) ? rawSort : DEFAULT_LEAD_SORT;

  const setSort = useCallback(
    (value: string) => {
      if (typeof window === "undefined") return;
      const params = new URLSearchParams(window.location.search);
      // Default sort is implicit → drop ?sort= so links stay clean.
      if (value && value !== DEFAULT_LEAD_SORT) params.set("sort", value);
      else params.delete("sort");
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname],
  );

  // Reset to page 1 whenever any filter (search/priority/status/website_status) changes — "adjust
  // state during render", React's recommended alternative to a state-setting effect.
  const filterSig = `${priority}|${status}|${search}|${websiteStatus}|${sort}|${industryKey}|${emailOutreach}|${coldCalling}|${assignedTo}`;
  const [pagedFilters, setPagedFilters] = useState(filterSig);
  if (filterSig !== pagedFilters) {
    setPagedFilters(filterSig);
    setPage(0);
  }

  const trimmedSearch = search.trim();

  // Current filter/sort params (no pagination). Single source shared by the list query AND the
  // CSV export, so an export always mirrors exactly the filter state the user is viewing.
  const listParams: Record<string, string> = {
    ...(trimmedSearch ? { search: trimmedSearch } : {}),
    ...(priority ? { priority } : {}),
    ...(status ? { status } : {}),
    ...(websiteStatus ? { website_status: websiteStatus } : {}),
    ...(sort !== DEFAULT_LEAD_SORT ? { sort } : {}),
    ...(emailOutreach ? { email_outreach: emailOutreach } : {}),
    ...(coldCalling ? { cold_calling: coldCalling } : {}),
    ...(assignedTo ? { assigned_to: assignedTo } : {}),
    // Industry = Niche: the selected value is the raw industry; send it directly.
    ...(selectedIndustries.length > 0 ? { industry: industryKey } : {}),
  };

  const query = useQuery<LeadApiResponse>({
    queryKey: queryKeys.leads.list({ page: page + 1, perPage: 10, search: trimmedSearch, priority, status, websiteStatus, sort, industry: industryKey, emailOutreach, coldCalling, assignedTo }),
    queryFn: () =>
      apiClient
        .get("/leads", { params: { page: page + 1, per_page: 10, ...listParams } })
        .then((r) => r.data),
    placeholderData: (prev) => prev,
  });

  const data = query.data?.data ?? [];
  const total = query.data?.meta?.total ?? 0;
  const totalPages = query.data?.meta?.last_page ?? 0;
  const industryCounts = query.data?.industry_counts ?? {};

  const goToPage = useCallback(
    (p: number) => {
      if (p >= 0 && p < totalPages) {
        setPage(p);
      }
    },
    [totalPages],
  );

  // Clear all list filters/sort → bare /leads. Dropping every query param resets
  // search/priority/status/website_status (and future sort/niche); the URL→header-search sync
  // effect clears the search input, and page resets via the filterSig change above.
  const clearFilters = useCallback(() => {
    router.replace(pathname, { scroll: false });
  }, [router, pathname]);

  // True when any filter/search is active — lets the toolbar disable Clear when there's nothing
  // to clear (website_status is included even though it isn't returned to the table).
  const hasActiveFilters =
    trimmedSearch.length > 0 ||
    priority.length > 0 ||
    status.length > 0 ||
    websiteStatus.length > 0 ||
    sort !== DEFAULT_LEAD_SORT ||
    selectedIndustries.length > 0 ||
    emailOutreach.length > 0 ||
    coldCalling.length > 0 ||
    assignedTo.length > 0;

  return {
    data,
    total,
    page,
    totalPages,
    goToPage,
    clearFilters,
    hasActiveFilters,
    listParams,
    industryCounts,
    search,
    setSearch,
    sort,
    setSort,
    selectedIndustries,
    toggleIndustry,
    priority,
    setPriority,
    status,
    setStatus,
    emailOutreach,
    setEmailOutreach,
    coldCalling,
    setColdCalling,
    assignedTo,
    setAssignedTo,
    isLoading: query.isLoading,
    isError: query.isError,
    retry: () => query.refetch(),
  };
}

// Priority Summary cards now use the Dashboard's live usePriorityBuckets hook
// (GET /dashboard/priority-buckets). The former hardcoded PRIORITY_BUCKETS mock was
// removed here to avoid duplicate aggregation logic.

// Lead Summary status counts (TOTAL/NEW/CONTACTED/QUALIFIED/DECLINED). Counts are LIVE
// from the extended /dashboard/kpis response; the tab metadata (order/label/color/active)
// is preserved verbatim from the approved Figma. Labels are presentation-only.
export type LeadStatusSummaryItem = {
  key: string;
  label: string;
  count: number;
  className: string;
  active: boolean;
  href: string;
};

// Static tab metadata (unchanged UI) — count/active/href are derived from live data + URL below.
const STATUS_SUMMARY_META: { key: string; label: string; className: string }[] = [
  // Sentence-case labels per the approved design (the previous all-caps form was applied in CSS).
  // Display strings only — `key` drives the counts, the ?status= href, and the active state.
  { key: "total", label: "Total", className: "text-foreground" },
  { key: "new", label: "New", className: "text-blue-500" },
  { key: "contacted", label: "Contacted", className: "text-yellow-800" },
  { key: "qualified", label: "Qualified", className: "text-green-700" },
  { key: "declined", label: "Declined", className: "text-red-500" },
];

// ⚠ TEMPORARY presentation mapping (tab key → CRM status values) — pending BD terminology.
// SINGLE SOURCE reused by BOTH the live counts and the tab click/active state below. If BD
// approves different wording later, change ONLY this map. `total` = all leads (no status filter).
export const STATUS_TAB_MAP: Record<string, string[]> = {
  total: [],
  new: ["new"],
  contacted: ["awaiting-reply", "call-back-req"],
  qualified: ["interested"],
  declined: ["declined"],
};

type KpiStatusApi = {
  totalLeads: number;
  statusCounts: {
    new: number;
    interested: number;
    "awaiting-reply": number;
    "call-back-req": number;
    declined: number;
  };
};

// Count for a tab = sum of its mapped statuses (TOTAL = all leads). Uses STATUS_TAB_MAP.
function statusTabCount(key: string, kpi: KpiStatusApi): number {
  if (key === "total") return kpi.totalLeads;
  const counts = kpi.statusCounts as Record<string, number>;
  return (STATUS_TAB_MAP[key] ?? []).reduce((sum, st) => sum + (counts[st] ?? 0), 0);
}

export function useLeadStatusSummary() {
  const searchParams = useSearchParams();
  // Reuse the extended /dashboard/kpis aggregation. The shared query key dedupes with the
  // Dashboard's useDashboardKpis (one request). No new endpoint, no duplicate aggregation.
  const query = useQuery<KpiStatusApi>({
    queryKey: queryKeys.dashboard.kpis(),
    queryFn: () => apiClient.get("/dashboard/kpis").then((r) => r.data.data),
  });

  const currentStatus = normalizeStatus(searchParams.get("status"));

  const data: LeadStatusSummaryItem[] = STATUS_SUMMARY_META.map((meta) => {
    const joined = (STATUS_TAB_MAP[meta.key] ?? []).join(",");
    return {
      key: meta.key,
      label: meta.label,
      className: meta.className,
      count: query.data ? statusTabCount(meta.key, query.data) : 0,
      active: joined === currentStatus,
      // Bare drill-down: only ?status= (drops any active priority/search) so the table always
      // matches the displayed count — same overview→drill-down model as the Priority cards.
      href: joined ? `/zerem/leads?status=${joined}` : "/zerem/leads",
    };
  });

  return { data, isLoading: query.isLoading, isError: query.isError };
}
