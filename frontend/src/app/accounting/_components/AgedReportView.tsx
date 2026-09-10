"use client";

import { PageHeader } from "@/core/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { Download, Printer } from "lucide-react";

import { formatPeso, formatDate } from "@/core/lib/format";
import { downloadExcel, printTable } from "@/core/lib/export";
import type { AgedItem, AgedReport } from "@/modules/accounting/types";

const exportBtn =
  "inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40";

const BUCKETS = [
  { key: "current", label: "Current" },
  { key: "d1_30", label: "1–30 days" },
  { key: "d31_60", label: "31–60 days" },
  { key: "d61_90", label: "61–90 days" },
  { key: "over_90", label: "90+ days" },
] as const;

type BucketKey = (typeof BUCKETS)[number]["key"];

/** Which aging bucket an item's overdue-days falls into. */
function bucketOf(daysOverdue: number): BucketKey {
  if (daysOverdue <= 0) return "current";
  if (daysOverdue <= 30) return "d1_30";
  if (daysOverdue <= 60) return "d31_60";
  if (daysOverdue <= 90) return "d61_90";
  return "over_90";
}

export function AgedReportView({
  title,
  subtitle,
  nameLabel,
  report,
  isLoading,
  isError,
}: {
  title: string;
  subtitle: string;
  nameLabel: string;
  report?: AgedReport;
  isLoading: boolean;
  isError: boolean;
}) {
  const columns: Column<AgedItem>[] = [
    { header: "Ref", cell: (r) => <span className="font-mono text-foreground">{r.ref}</span> },
    { header: nameLabel, cell: (r) => <span className="font-medium text-foreground">{r.name}</span> },
    { header: "Due", cell: (r) => <span className="text-muted-foreground">{r.due_date ? formatDate(r.due_date) : "—"}</span> },
    // One column per aging bucket — each item's amount lands in its own bucket.
    ...BUCKETS.map((b): Column<AgedItem> => ({
      header: b.label,
      align: "right",
      cell: (r) => (
        <span className="tabular-nums">
          {bucketOf(r.days_overdue) === b.key ? formatPeso(r.amount) : "—"}
        </span>
      ),
    })),
  ];

  const exportHeaders = ["Ref", nameLabel, "Due", ...BUCKETS.map((b) => b.label)];
  const exportRows = (): (string | number)[][] =>
    (report?.items ?? []).map((r) => [
      r.ref,
      r.name,
      r.due_date ?? "",
      ...BUCKETS.map((b) => (bucketOf(r.days_overdue) === b.key ? r.amount : "")),
    ]);

  return (
    <>
      <PageHeader
        eyebrow="Reports"
        title={title}
        subtitle={subtitle}
        actions={
          (report?.items.length ?? 0) > 0 ? (
            <div className="flex items-center gap-2">
              <button type="button" onClick={() => downloadExcel(title.toLowerCase().replace(/\s+/g, "-"), title, exportHeaders, exportRows())} className={exportBtn}>
                <Download className="size-4" /> Excel
              </button>
              <button type="button" onClick={() => printTable(title, subtitle, exportHeaders, exportRows())} className={exportBtn}>
                <Printer className="size-4" /> PDF
              </button>
            </div>
          ) : undefined
        }
      />

      {isError ? (
        <p className="mt-6 rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
          Couldn’t load this report. Please try again.
        </p>
      ) : (
        <>
          <div className="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {BUCKETS.map((b) => (
              <div key={b.key} className="rounded-card border border-border bg-card p-4">
                <p className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">
                  {b.label}
                </p>
                <p className="mt-1 text-h3 font-bold tabular-nums text-foreground">
                  {formatPeso(report?.buckets[b.key] ?? 0)}
                </p>
              </div>
            ))}
          </div>

          <div className="mt-6">
            <DataTable<AgedItem>
              columns={columns}
              rows={report?.items ?? []}
              rowKey={(r) => r.ref}
              pageSize={25}
              searchable={false}
              filterable={false}
              empty={isLoading ? "Loading…" : "Nothing outstanding — all settled. ✓"}
            />
          </div>

          <p className="mt-3 text-sm font-semibold text-foreground">
            Total outstanding: {formatPeso(report?.total ?? 0)}
          </p>
        </>
      )}
    </>
  );
}
