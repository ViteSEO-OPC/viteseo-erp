"use client";

import { useState } from "react";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatusBadge } from "@/core/components/status-badge";
import { RejectionReasonDialog } from "@/core/components/rejection-reason-dialog";
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
    default:
      return null;
  }
}

export default function ClaimsPage() {
  const { data: claims, isLoading, isError } = useReimbursements();
  const [declined, setDeclined] = useState<Reimbursement | null>(null);

  const all = claims ?? [];

  const columns: Column<Reimbursement>[] = [
    { header: "Category", cell: (c) => categoryLabel(c.category) },
    {
      header: "Description",
      cell: (c) => {
        if (c.status === "rejected") {
          return (
            <div>
              <p className="font-medium text-foreground">{c.description}</p>
              <p className="text-caption text-danger">
                {formatDate(c.created_at)} · Tap to see why
              </p>
            </div>
          );
        }
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
    <div className="p-4 sm:p-8">
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
                rows={all}
                rowKey={(c) => c.id}
                pageSize={10}
                onRowClick={(c) => {
                  if (c.status === "rejected") setDeclined(c);
                }}
                rowClassName={(c) => (c.status === "rejected" ? "cursor-pointer" : undefined)}
                empty="No claims yet. File your first one."
              />
            </>
          )}
        </div>
      </div>

      <RejectionReasonDialog
        open={!!declined}
        onOpenChange={(o) => !o && setDeclined(null)}
        title="Claim declined"
        subject={declined ? declined.description : undefined}
        reason={declined?.review_note ?? null}
      />
    </div>
  );
}
