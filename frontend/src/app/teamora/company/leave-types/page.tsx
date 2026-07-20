"use client";

import { useMemo, useState } from "react";
import { Plus, Pencil, Trash2 } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/core/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { LeaveTypeFormDialog } from "@/modules/hr/components/leave-type-form-dialog";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import {
  useLeaveTypes,
  useDeleteLeaveType,
  useAllotments,
  useUpsertAllotment,
} from "@/modules/hr/hooks/use-leave-config";
import { EMPLOYMENT_STATUSES } from "@/modules/hr/types/leave-config";
import type { LeaveType } from "@/modules/hr/types/leave";

export default function LeaveTypesPage() {
  const { data: types, isLoading, isError } = useLeaveTypes();
  const deleteMut = useDeleteLeaveType();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<LeaveType | null>(null);
  const [deleting, setDeleting] = useState<LeaveType | null>(null);

  function openCreate() {
    setEditing(null);
    setDialogOpen(true);
  }
  function openEdit(t: LeaveType) {
    setEditing(t);
    setDialogOpen(true);
  }
  async function performDelete(t: LeaveType) {
    try {
      await deleteMut.mutateAsync(t.id);
      toast.success("Leave type deleted");
    } catch {
      toast.error("Could not delete the leave type.");
    }
  }

  const columns: Column<LeaveType>[] = [
    {
      header: "Leave type",
      cell: (t) => (
        <div className="flex items-center gap-2.5">
          <span
            className="size-3 shrink-0 rounded-full"
            style={{ backgroundColor: t.color ?? "#94a3b8" }}
          />
          <div>
            <p className="font-semibold text-foreground">{t.name}</p>
            <p className="text-xs text-muted-foreground">{t.code}</p>
          </div>
        </div>
      ),
    },
    { header: "Base days / year", cell: (t) => t.days_per_year },
    {
      header: "Pay",
      cell: (t) =>
        t.is_paid ? (
          <span className="rounded-full bg-success-subtle px-2 py-0.5 text-xs font-semibold text-success">
            Paid
          </span>
        ) : (
          <span className="rounded-full bg-danger-subtle px-2 py-0.5 text-xs font-semibold text-danger">
            Unpaid
          </span>
        ),
    },
    {
      header: "Actions",
      align: "right",
      cell: (t) => (
        <div className="flex justify-end gap-1">
          <Button size="icon-sm" variant="ghost" onClick={() => openEdit(t)}>
            <Pencil />
            <span className="sr-only">Edit</span>
          </Button>
          <Button size="icon-sm" variant="ghost" onClick={() => setDeleting(t)}>
            <Trash2 />
            <span className="sr-only">Delete</span>
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div className="p-8">
      <PageHeader
        title="Leave Type"
        subtitle="Leave types and per-status allotment defaults. Set individual credits in each employee's profile."
        actions={
          <Button onClick={openCreate}>
            <Plus />
            Add leave type
          </Button>
        }
      />

      <div className="mt-8 space-y-10">
        {/* Section A — leave type catalogue */}
        <section>
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading leave types…</p>
          ) : isError ? (
            <p className="text-sm text-destructive">Could not load leave types. Please refresh.</p>
          ) : (
            <DataTable
              columns={columns}
              rows={types ?? []}
              rowKey={(t) => t.id}
              empty="No leave types yet. Add your first one."
            />
          )}
        </section>

        {/* Section B — allotment defaults */}
        <AllotmentGrid types={types ?? []} />
      </div>

      <LeaveTypeFormDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        leaveType={editing}
      />

      <DeleteConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Delete leave type"
        entityName={deleting?.name}
        description="This action cannot be undone. Employees will no longer be able to file this leave type."
        onConfirm={() => performDelete(deleting!)}
      />
    </div>
  );
}

// ---- Section B: allotment defaults grid (leave type × employment status) ----

function AllotmentGrid({ types }: { types: LeaveType[] }) {
  const { data: allotments } = useAllotments();

  // Lookup: `${leaveTypeId}:${status}` -> days.
  const lookup = useMemo(() => {
    const m = new Map<string, number>();
    for (const a of allotments ?? []) {
      m.set(`${a.leave_type_id}:${a.employment_status}`, a.days_per_year);
    }
    return m;
  }, [allotments]);

  return (
    <section className="space-y-3">
      <div>
        <h2 className="text-sm font-semibold text-foreground">Allotment defaults</h2>
        <p className="text-xs text-muted-foreground">
          Starting credits for a new hire, by employment status. Editing these only
          affects future hires — existing employees keep their own credits (edit
          those below).
        </p>
      </div>

      <div className="overflow-x-auto rounded-card border border-border bg-card">
        <table className="w-full min-w-[560px] text-sm">
          <thead>
            <tr className="border-b border-border text-left">
              <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Leave type
              </th>
              {EMPLOYMENT_STATUSES.map((s) => (
                <th
                  key={s.value}
                  className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                >
                  {s.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {types.map((t) => (
              <tr key={t.id} className="border-b border-border last:border-0">
                <td className="px-4 py-2.5">
                  <div className="flex items-center gap-2">
                    <span
                      className="size-2.5 shrink-0 rounded-full"
                      style={{ backgroundColor: t.color ?? "#94a3b8" }}
                    />
                    <span className="font-medium text-foreground">{t.name}</span>
                  </div>
                </td>
                {EMPLOYMENT_STATUSES.map((s) => (
                  <td key={s.value} className="px-4 py-2.5">
                    <AllotmentCell
                      leaveTypeId={t.id}
                      status={s.value}
                      value={lookup.get(`${t.id}:${s.value}`) ?? 0}
                    />
                  </td>
                ))}
              </tr>
            ))}
            {types.length === 0 && (
              <tr>
                <td colSpan={EMPLOYMENT_STATUSES.length + 1} className="px-4 py-6 text-center text-sm text-muted-foreground">
                  Add a leave type to set its allotments.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function AllotmentCell({
  leaveTypeId,
  status,
  value,
}: {
  leaveTypeId: number;
  status: string;
  value: number;
}) {
  const upsert = useUpsertAllotment();
  const [local, setLocal] = useState(String(value));

  async function save() {
    const days = Number(local);
    if (Number.isNaN(days) || days === value) return;
    try {
      await upsert.mutateAsync({
        leave_type_id: leaveTypeId,
        employment_status: status,
        days_per_year: days,
      });
    } catch {
      toast.error("Could not save the allotment.");
      setLocal(String(value));
    }
  }

  return (
    <Input
      type="number"
      step="1"
      min="0"
      className="h-8 w-20"
      value={local}
      onChange={(e) => setLocal(e.target.value)}
      onBlur={save}
    />
  );
}

