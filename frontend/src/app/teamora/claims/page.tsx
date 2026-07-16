"use client";

import { useState } from "react";
import { PageHeader } from "@/core/components/page-header";
import { StatusBadge } from "@/core/components/status-badge";
import { DataTable, type Column } from "@/core/components/data-table";
import { formatPeso, formatDate } from "@/core/lib/format";
import { ClaimForm } from "@/modules/hr/components/claim-form";
import { useReimbursements } from "@/modules/hr/hooks/use-reimbursements";
import {
  categoryLabel,
  type Reimbursement,
} from "@/modules/hr/types/reimbursement";

// A short line under the description reflecting where the claim is in its lifecycle.
function statusNote(status: string): string | null {
  switch (status) {
    case "approved":
      return "Queued for payroll";
    case "paid":
      return "Paid in your payslip";
    case "rejected":
      return "Declined by HR";
    default:
      return null;
  }
}

export default function ClaimsPage() {
  const { data: claims, isLoading, isError } = useReimbursements();
  const [showAll, setShowAll] = useState(false);

  const all = claims ?? [];
  const rows = showAll ? all : all.slice(0, 5);

  const columns: Column<Reimbursement>[] = [
    { header: "Category", cell: (c) => categoryLabel(c.category) },
    {
      header: "Description",
      cell: (c) => {
        const note = statusNote(c.status);
        return (
          <div>
            <p className="font-medium text-foreground">{c.description}</p>
            {note && <p className="text-caption text-muted-foreground">{note}</p>}
          </div>
        );
      },
    },
    { header: "Amount", cell: (c) => formatPeso(c.amount) },
    { header: "Submitted", cell: (c) => formatDate(c.created_at) },
    {
      header: "Status",
      align: "right",
      cell: (c) => <StatusBadge status={c.status} />,
    },
  ];

  return (
    <div className="p-8">
      <PageHeader title="Claims" subtitle="File and track your expense claims." />

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[2fr_3fr]">
        <ClaimForm />

        <div className="rounded-card border border-border bg-card p-6">
          <h2 className="mb-4 text-h3 font-bold text-foreground">My Claims</h2>
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading claims…</p>
          ) : isError ? (
            <p className="text-sm text-destructive">Could not load claims.</p>
          ) : (
            <>
              <DataTable
                columns={columns}
                rows={rows}
                rowKey={(c) => c.id}
                empty="No claims yet. File your first one."
              />
              {all.length > 5 && (
                <div className="mt-4 border-t border-border pt-3 text-center">
                  <button
                    type="button"
                    onClick={() => setShowAll((s) => !s)}
                    className="text-sm font-semibold text-brand hover:underline"
                  >
                    {showAll ? "Show less" : "View full history"}
                  </button>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
