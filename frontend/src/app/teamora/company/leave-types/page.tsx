"use client";

import { useMemo, useState } from "react";
import { Plus, Pencil, Trash2 } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { PageHeader } from "@/modules/hr/components/page-header";
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

/** Base days come back as decimal(5,1); show one decimal to match the design (e.g. "15.0"). */
function formatDays(value: number | string): string {
  const n = Number(value);
  return Number.isFinite(n) ? n.toFixed(1) : String(value);
}

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
            className="size-2.5 shrink-0 rounded-full"
            style={{ backgroundColor: t.color ?? "#94a3b8" }}
          />
          <div>
            <p className="font-semibold text-foreground">{t.name}</p>
            <p className="text-xs text-muted-foreground">{t.code}</p>
          </div>
        </div>
      ),
    },
    {
      header: "Base days / year",
      className: "text-center",
      cell: (t) => formatDays(t.days_per_year),
    },
    {
      header: "Pay",
      className: "text-center",
      cell: (t) =>
        t.is_paid ? (
          <span className="rounded-full bg-success-subtle px-2.5 py-0.5 text-xs font-semibold text-success">
            Paid
          </span>
        ) : (
          <span className="rounded-full bg-danger-subtle px-2.5 py-0.5 text-xs font-semibold text-danger">
            Unpaid
          </span>
        ),
    },
    {
      header: "Actions",
      className: "text-center",
      cell: (t) => (
        <div className="flex justify-center gap-1">
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
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Leave Types"
        subtitle="Configure leave types and default allotments for roles. Changes apply prospectively and do not affect approved or used balances."
        actions={
          <Button onClick={openCreate}>
            <Plus />
            Add Leave Type
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
              searchable={false}
              filterable={false}
              pageSize={0}
            />
          )}
        </section>

        {/* Section B — allotment defaults */}
        <AllotmentDefaults types={types ?? []} />
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

// ---- Section B: allotment defaults (leave type × employment status) ----

type AllotmentMode = "shared" | "not_shared";

function AllotmentDefaults({ types }: { types: LeaveType[] }) {
  // "Not Shared" (default) = every leave type carries its own credits, keyed by
  // employment status. "Shared" mode is a separate model coming in a later slice.
  const [mode, setMode] = useState<AllotmentMode>("not_shared");

  return (
    <section className="space-y-3">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2 className="text-h3 font-bold text-foreground">Allotment defaults</h2>
          <p className="text-sm text-muted-foreground">
            Starting credits for a new hire, by employment status. Editing these only
            affects future hires — existing employees keep their own credits.
          </p>
        </div>
        <AllotmentModeToggle mode={mode} onChange={setMode} />
      </div>

      {mode === "not_shared" ? (
        <NotSharedGrid types={types} />
      ) : (
        <div className="rounded-card border border-border bg-card px-4 py-12 text-center text-sm text-muted-foreground">
          Shared allotment configuration is coming next.
        </div>
      )}
    </section>
  );
}

/**
 * Figma "segmented-toggle": a light-gray track holding two equal pills; the
 * active one is a white, subtly-shadowed pill with bold ink text.
 */
function AllotmentModeToggle({
  mode,
  onChange,
}: {
  mode: AllotmentMode;
  onChange: (mode: AllotmentMode) => void;
}) {
  const options: { value: AllotmentMode; label: string }[] = [
    { value: "shared", label: "Shared" },
    { value: "not_shared", label: "Not Shared" },
  ];

  return (
    <div
      role="tablist"
      className="flex w-full shrink-0 gap-1 rounded-xl bg-muted p-1 sm:w-[320px]"
    >
      {options.map((o) => {
        const active = mode === o.value;
        return (
          <button
            key={o.value}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(o.value)}
            className={cn(
              "flex-1 rounded-lg py-2.5 text-center text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50",
              active
                ? "bg-card font-bold text-foreground shadow-sm"
                : "font-semibold text-muted-foreground hover:text-foreground",
            )}
          >
            {o.label}
          </button>
        );
      })}
    </div>
  );
}

function NotSharedGrid({ types }: { types: LeaveType[] }) {
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
    <div className="overflow-x-auto rounded-card border border-border bg-card">
      <table className="w-full min-w-[640px] border-collapse text-sm">
        <thead>
          <tr className="border-b border-border bg-muted/50">
            <th className="px-4 py-3 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">
              Leave type
            </th>
            {EMPLOYMENT_STATUSES.map((s) => (
              <th
                key={s.value}
                className="px-4 py-3 text-center text-label font-bold uppercase tracking-wide text-muted-foreground"
              >
                {s.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {types.map((t) => (
            <tr key={t.id} className="border-b border-border last:border-0">
              <td className="px-4 py-3">
                <div className="flex items-center gap-2">
                  <span
                    className="size-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: t.color ?? "#94a3b8" }}
                  />
                  <span className="font-medium text-foreground">{t.name}</span>
                </div>
              </td>
              {EMPLOYMENT_STATUSES.map((s) => (
                <td key={s.value} className="px-4 py-3 text-center">
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
              <td
                colSpan={EMPLOYMENT_STATUSES.length + 1}
                className="px-4 py-10 text-center text-sm text-muted-foreground"
              >
                Add a leave type to set its allotments.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
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
      step="0.5"
      min="0"
      className="mx-auto h-8 w-16 text-center"
      value={local}
      onChange={(e) => setLocal(e.target.value)}
      onBlur={save}
    />
  );
}
