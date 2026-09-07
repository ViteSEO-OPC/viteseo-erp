"use client";

import { useState } from "react";

import { cn } from "@/lib/utils";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { DataTable, type Column } from "@/core/components/data-table";
import {
  usePayrollRuns,
  usePostPayrollRun,
  useImportPayroll,
} from "@/modules/accounting/hooks/usePayrollRuns";
import type { PayrollRun } from "@/modules/accounting/types";
import { useAccountingPerms } from "@/modules/accounting/permissions";
import { formatPeso } from "@/core/lib/format";
import { manilaToday } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const dateFmt = new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric", year: "numeric" });
function fmtDate(v: string): string {
  return dateFmt.format(new Date(`${v.slice(0, 10)}T00:00:00`));
}

export default function PayrollLedgerPage() {
  const { canPostPayroll } = useAccountingPerms();
  const [mode, setMode] = useState<"hris" | "import">("hris");

  return (
    <>
      <PageHeader
        eyebrow="Connections"
        title="Payroll → Ledger"
        subtitle="Post each payroll run and see its true cost — salaries plus the employer share of SSS, PhilHealth & Pag-IBIG."
      />

      <div className="mt-6 flex gap-1 rounded-lg border border-border p-1 sm:w-fit">
        {(
          [
            ["hris", "From HRIS"],
            ["import", "Import CSV"],
          ] as const
        ).map(([m, label]) => (
          <button
            key={m}
            type="button"
            onClick={() => setMode(m)}
            className={cn(
              "flex-1 rounded-md px-4 py-1.5 text-sm font-medium transition-colors sm:flex-none",
              mode === m ? "bg-brand text-brand-foreground" : "text-muted-foreground hover:text-foreground",
            )}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="mt-6">
        {mode === "hris" ? (
          <HrisRuns canPostPayroll={canPostPayroll} />
        ) : (
          <ImportPayroll canPostPayroll={canPostPayroll} />
        )}
      </div>
    </>
  );
}

function HrisRuns({ canPostPayroll }: { canPostPayroll: boolean }) {
  const { data, isLoading, isError, error } = usePayrollRuns();
  const post = usePostPayrollRun();
  const runs = data ?? [];

  const columns: Column<PayrollRun>[] = [
    { header: "Period", cell: (r) => <span className="font-medium text-foreground">{fmtDate(r.cycle_start)} – {fmtDate(r.cycle_end)}</span> },
    { header: "Staff", align: "right", cell: (r) => <span className="tabular-nums">{r.employees}</span> },
    { header: "Gross", align: "right", cell: (r) => <span className="tabular-nums">{formatPeso(r.gross)}</span> },
    { header: "Employer share", align: "right", cell: (r) => <span className="tabular-nums text-muted-foreground">{formatPeso(r.employer_contributions)}</span> },
    { header: "True cost", align: "right", cell: (r) => <span className="tabular-nums font-semibold text-foreground">{formatPeso(r.total_cost)}</span> },
    {
      header: "",
      align: "right",
      cell: (r) =>
        r.posted ? (
          <span className="rounded-full bg-brand-subtle px-2.5 py-0.5 text-caption font-semibold uppercase tracking-wide text-dark-teal">Posted</span>
        ) : canPostPayroll ? (
          <button type="button" disabled={post.isPending} onClick={() => post.mutate(r.id)} className="inline-flex items-center rounded-lg bg-brand px-3 py-1.5 text-caption font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50">
            {post.isPending ? "Posting…" : "Post to ledger"}
          </button>
        ) : (
          <span className="text-caption text-muted-foreground">—</span>
        ),
    },
  ];

  if (isError) {
    /*
     * This bridge is gated on BOTH halves — `accounting.payroll_posting` AND
     * `hr` — so an accounting-only org is refused here with a 403 that says
     * exactly which half is missing ("Your organization hasn't availed this
     * feature (hr)."). Printing "Couldn't load payroll runs" over that turns a
     * correct, explicable gate into what looks like a broken screen, on the one
     * page whose whole subject is another module.
     */
    const reason = (error as { response?: { status?: number; data?: { message?: string } } })
      ?.response;
    const gated = reason?.status === 403;

    return (
      <div className="rounded-card border border-border bg-card p-6">
        <p className="text-sm text-foreground">
          {gated
            ? "Payroll → Ledger needs the HRIS system as well as Accounting."
            : "Couldn’t load payroll runs."}
        </p>
        <p className="mt-1 text-caption text-muted-foreground">
          {gated
            ? (reason?.data?.message ??
              "Your organisation hasn’t availed HRIS. Payroll runs are posted from there.")
            : "Try again, or contact your administrator if this keeps happening."}
        </p>
      </div>
    );
  }

  return (
    <DataTable<PayrollRun>
      columns={columns}
      rows={runs}
      rowKey={(r) => r.id}
      pageSize={0}
      searchable={false}
      filterable={false}
      empty={isLoading ? "Loading…" : "No payroll runs from HRIS."}
    />
  );
}

function ImportPayroll({ canPostPayroll }: { canPostPayroll: boolean }) {
  const doImport = useImportPayroll();
  const [file, setFile] = useState<File | null>(null);
  const [date, setDate] = useState(() => manilaToday());
  const [memo, setMemo] = useState("");

  const submit = () => {
    if (!file || !date) return;
    doImport.mutate(
      { file, entry_date: date, memo: memo || undefined },
      { onSuccess: () => { setFile(null); setMemo(""); } },
    );
  };

  return (
    <div className="max-w-2xl rounded-card border border-border bg-card p-6">
      <SectionHeading eyebrow="Standalone" title="Import payroll (CSV)" />
      <p className="mt-2 text-caption text-muted-foreground">
        For standalone use or an external HRIS. Columns (any order): <span className="font-mono">gross</span>, <span className="font-mono">sss_employee/employer</span>, <span className="font-mono">philhealth_employee/employer</span>, <span className="font-mono">pagibig_employee/employer</span>, <span className="font-mono">withholding_tax</span>, <span className="font-mono">net_pay</span>. Blank columns count as 0.
      </p>

      {!canPostPayroll ? (
        <p className="mt-4 text-sm text-muted-foreground">You don’t have permission to post payroll.</p>
      ) : (
        <>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <label className="grid gap-1.5">
              <span className="text-sm font-medium">CSV file</span>
              <input type="file" accept=".csv,.txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium" />
            </label>
            <label className="grid gap-1.5">
              <span className="text-sm font-medium">Entry date</span>
              <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className={inputCls} />
            </label>
            <label className="grid gap-1.5 sm:col-span-2">
              <span className="text-sm font-medium">Memo (optional)</span>
              <input value={memo} onChange={(e) => setMemo(e.target.value)} placeholder="e.g. July 2026 payroll" className={inputCls} />
            </label>
          </div>

          {doImport.isError ? (
            <p className="mt-3 text-caption text-destructive">Couldn’t import — check the CSV columns and try again.</p>
          ) : null}
          {doImport.isSuccess ? (
            <p className="mt-3 text-caption text-brand">Imported and posted to the ledger ✓</p>
          ) : null}

          <button type="button" disabled={!file || !date || doImport.isPending} onClick={submit} className="mt-4 inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50">
            {doImport.isPending ? "Importing…" : "Import & post"}
          </button>
        </>
      )}
    </div>
  );
}
