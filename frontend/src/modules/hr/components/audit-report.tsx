"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, Download } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { useReport, useExportReport } from "@/modules/hr/hooks/use-report";
import { useDebouncedValue } from "@/core/use-debounced-value";
import { SimpleSelect } from "@/modules/hr/ui/select";
import type { AuditEntry } from "@/modules/hr/types/reports";

const RANGES = [
  { value: 3, label: "Last 3 Months" },
  { value: 6, label: "Last 6 Months" },
  { value: 9, label: "Last 9 Months" },
  { value: 12, label: "Last 12 Months" },
];

// Module → pill colors (token-based).
const MODULE_STYLE: Record<string, string> = {
  Benefits: "bg-success-subtle text-success",
  Leave: "bg-info-subtle text-info",
  Rules: "bg-warning-subtle text-warning",
  "Leave Types": "bg-violet-subtle text-violet",
  Schedules: "bg-brand-subtle text-dark-teal",
  Payroll: "bg-info-subtle text-info",
  Reimbursement: "bg-warning-subtle text-warning",
  Documents: "bg-brand-subtle text-dark-teal",
  Profile: "bg-violet-subtle text-violet",
  Concerns: "bg-danger-subtle text-danger",
  People: "bg-success-subtle text-success",
  Recruitment: "bg-info-subtle text-info",
  FAQ: "bg-brand-subtle text-dark-teal",
  Holidays: "bg-warning-subtle text-warning",
  Attendance: "bg-danger-subtle text-danger",
  Reports: "bg-violet-subtle text-violet",
  Auth: "bg-muted text-muted-foreground",
};

function ModuleBadge({ module }: { module: string }) {
  return (
    <span className={cn("inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold", MODULE_STYLE[module] ?? "bg-muted text-muted-foreground")}>
      {module}
    </span>
  );
}

// One page of the log. Ten rows, matching every other table on the platform.
const PAGE_SIZE = 10;

export function AuditReport() {
  const [range, setRange] = useState(9);

  // Item 17 — the audit trail is SERVER-PAGED. It is the one table with no ceiling: it gains a
  // row every time anybody does anything, so it is the first to outgrow "fetch it and slice it
  // in the browser". The screen used to take the server's first 100 entries and page through
  // them ten at a time, which meant the pager stopped at 100 with no indication there was more,
  // and the search box searched those 100 — reporting "No matches" for a record on page 40.
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  // The table fires on every keystroke; the request must not.
  const debouncedSearch = useDebouncedValue(search, 250);

  const { data: report, isLoading, isError, isFetching } = useReport("audit", {
    months: range,
    page,
    per_page: PAGE_SIZE,
    ...(debouncedSearch.trim() ? { search: debouncedSearch.trim() } : {}),
  }, { keepPreviousData: true });
  const exportMut = useExportReport("audit", {
    months: range,
    // The export follows the search on screen. Downloading a file that quietly disagrees with
    // the table it came from is worse than not offering the button.
    ...(debouncedSearch.trim() ? { search: debouncedSearch.trim() } : {}),
  });

  async function onExport() {
    try {
      await exportMut.mutateAsync();
      toast.success("Report downloaded");
    } catch {
      toast.error("Could not export the report.");
    }
  }

  const entries = report?.entries ?? [];
  const total = report?.pagination?.total ?? entries.length;

  const columns: Column<AuditEntry>[] = [
    { header: "Timestamp", cell: (e) => <span className="whitespace-nowrap text-muted-foreground">{e.timestamp}</span> },
    { header: "User", cell: (e) => <span className="font-semibold text-foreground">{e.user}</span> },
    { header: "Module", cell: (e) => <ModuleBadge module={e.module} /> },
    { header: "Action", cell: (e) => <span className="capitalize text-foreground">{e.action}</span> },
    { header: "Details", cell: (e) => <span className="text-muted-foreground">{e.details}</span> },
  ];

  return (
    <div className="p-8">
      <PageHeader
        title={report?.title ?? "Audit Trail"}
        subtitle={report?.description ?? "System activity log: who did what, when, and to which record. Restricted to admins and HR leadership."}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" asChild>
              <Link href="/teamora/reports">
                <ArrowLeft />
                Back to report library
              </Link>
            </Button>
            <Button variant="outline" onClick={onExport} disabled={exportMut.isPending || !report}>
              <Download />
              {exportMut.isPending ? "Exporting…" : "Export"}
            </Button>
          </div>
        }
      />

      {/* Filter: date range only */}
      <div className="mt-6 flex flex-wrap items-center gap-3">
        <SimpleSelect
          aria-label="Date range"
          className="w-48"
          value={String(range)}
          onValueChange={(v) => {
            setRange(Number(v));
            // A narrower range has fewer pages. Without this the reader stays on page 12 of a
            // result that now has three, and the table comes back empty.
            setPage(1);
          }}
          options={RANGES.map((r) => ({ value: String(r.value), label: r.label }))}
        />
      </div>

      <div className="mt-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading report…</p>
        ) : isError || !report ? (
          <p className="text-sm text-destructive">Could not load this report.</p>
        ) : (
          <DataTable
            columns={columns}
            rows={entries}
            rowKey={(_e, i) => i}
            pageSize={PAGE_SIZE}
            empty="No activity in this period."
            searchPlaceholder="Search user, action or details…"
            // Every entry is an action already taken — there is nothing to filter by status.
            filterable={false}
            server={{
              total,
              page,
              loading: isFetching,
              onParamsChange: ({ page: next, query }) => {
                setPage(next);
                setSearch(query);
              },
            }}
          />
        )}
      </div>
    </div>
  );
}
