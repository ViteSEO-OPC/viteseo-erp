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

const VALID_STATUSES = ["interested", "awaiting-reply", "declined", "call-back-req", "new"];

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
  const priority = ["high", "medium", "low"].includes(rawPriority) ? rawPriority : "";

  // Website-status filter — URL-driven & reactive (the overview cards link to
  // /leads?website_status=<status>). Independent of the priority filter above.
  const rawWebsiteStatus = searchParams.get("website_status") ?? "";
  const websiteStatus = ["has-website", "no-website", "needs-redesign"].includes(rawWebsiteStatus)
    ? rawWebsiteStatus
    : "";

  // Status filter — URL-driven & reactive; supports a comma-separated list so a summary tab can
  // map to multiple statuses (CONTACTED = awaiting-reply,call-back-req).
  const status = normalizeStatus(searchParams.get("status"));

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
  const filterSig = `${priority}|${status}|${search}|${websiteStatus}|${sort}|${industryKey}`;
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
    // Industry = Niche: the selected value is the raw industry; send it directly.
    ...(selectedIndustries.length > 0 ? { industry: industryKey } : {}),
  };

  const query = useQuery<LeadApiResponse>({
    queryKey: queryKeys.leads.list({ page: page + 1, perPage: 10, search: trimmedSearch, priority, status, websiteStatus, sort, industry: industryKey }),
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
    selectedIndustries.length > 0;

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
    status,
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
  { key: "total", label: "TOTAL", className: "text-foreground" },
  { key: "new", label: "NEW", className: "text-blue-500" },
  { key: "contacted", label: "CONTACTED", className: "text-yellow-800" },
  { key: "qualified", label: "QUALIFIED", className: "text-green-700" },
  { key: "declined", label: "DECLINED", className: "text-red-500" },
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
      href: joined ? `/leads?status=${joined}` : "/leads",
    };
  });

  return { data, isLoading: query.isLoading, isError: query.isError };
}
