"use client";

import { useEffect, useMemo, useState } from "react";
import { Trash2 } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DataTable, type Column } from "@/core/components/data-table";
import {
  useLeaveTypes,
  useLeaveSetting,
  useEmployeeBalances,
  useUpsertEmployeeBalance,
  useDeleteEmployeeBalance,
  useEmployeeSharedBalance,
  useUpsertEmployeeSharedBalance,
} from "@/modules/hr/hooks/use-leave-config";
import type { LeaveBalance } from "@/modules/hr/types/leave";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";

/**
 * HR-editable per-employee leave credits, mode-aware: in not-shared mode a
 * per-type table (the source of truth for that person); in shared mode a single
 * editable pool balance. Lives on the employee profile.
 */
export function EmployeeLeaveCreditsEditor({ employeeId }: { employeeId: number }) {
  const { data: setting } = useLeaveSetting();

  if (setting?.allotment_mode === "shared") {
    return <SharedPoolEditor employeeId={employeeId} />;
  }

  return <PerTypeCreditsEditor employeeId={employeeId} />;
}

/**
 * Shared mode: one pool balance per employee. HR edits the entitled days; used is
 * read-only (spent via approved leave) and remaining is derived.
 */
function SharedPoolEditor({ employeeId }: { employeeId: number }) {
  const { data, isLoading } = useEmployeeSharedBalance(employeeId);
  const upsert = useUpsertEmployeeSharedBalance(employeeId);
  const [local, setLocal] = useState<string>("");

  useEffect(() => {
    setLocal(data ? String(data.entitled_days) : "0");
  }, [data]);

  async function save() {
    const days = Number(local);
    if (Number.isNaN(days) || days === (data?.entitled_days ?? -1)) return;
    try {
      await upsert.mutateAsync({ entitled_days: days });
      toast.success("Shared leave pool updated");
    } catch {
      toast.error("Could not save.");
      setLocal(data ? String(data.entitled_days) : "0");
    }
  }

  if (isLoading) {
    return <p className="text-sm text-muted-foreground">Loading leave credits…</p>;
  }

  const used = data?.used_days ?? 0;
  const remaining = (Number(local) || 0) - used;

  return (
    <div className="max-w-md space-y-3 rounded-card border border-border bg-card p-4">
      <div>
        <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
          Shared Leave Pool
        </p>
        <p className="mt-1 text-caption text-muted-foreground">
          One pool spent across all leave types this year.
        </p>
      </div>

      <div className="flex items-end gap-3">
        <div className="space-y-1.5">
          <Label>Entitled days / year</Label>
          <Input
            type="number"
            step="1"
            min="0"
            className="h-9 w-28"
            value={local}
            onChange={(e) => setLocal(e.target.value)}
            onBlur={save}
          />
        </div>
        <Button size="sm" onClick={save} disabled={upsert.isPending}>
          {upsert.isPending ? "Saving…" : "Save"}
        </Button>
      </div>

      <div className="flex gap-6 text-sm">
        <span className="text-muted-foreground">
          Used: <span className="font-medium text-foreground">{used}</span>
        </span>
        <span className="text-muted-foreground">
          Remaining: <span className="font-medium text-foreground">{remaining}</span>
        </span>
      </div>
    </div>
  );
}

/**
 * Not-shared mode: HR-editable per-type credits — change entitled days, add a
 * leave type, or remove one. The per-person balance is the source of truth.
 */
function PerTypeCreditsEditor({ employeeId }: { employeeId: number }) {
  const { data: balances, isLoading } = useEmployeeBalances(employeeId);
  const { data: allTypes } = useLeaveTypes();
  const upsert = useUpsertEmployeeBalance(employeeId);
  const remove = useDeleteEmployeeBalance(employeeId);

  const [removing, setRemoving] = useState<LeaveBalance | null>(null);
  const [addTypeId, setAddTypeId] = useState<string>("");
  const [addDays, setAddDays] = useState<string>("");

  const columns: Column<LeaveBalance>[] = [
    {
      header: "Leave type",
      cell: (b) => (
        <div className="flex items-center gap-2">
          <span
            className="size-2.5 shrink-0 rounded-full"
            style={{ backgroundColor: b.leave_type.color ?? "#4FDBD7" }}
          />
          <span className="font-medium text-foreground">{b.leave_type.name}</span>
        </div>
      ),
    },
    {
      header: "Entitled",
      cell: (b) => (
        <EntitledCell
          value={b.entitled_days}
          onSave={(days) =>
            upsert.mutateAsync({ leave_type_id: b.leave_type.id, entitled_days: days })
          }
        />
      ),
    },
    { header: "Used", cell: (b) => b.used_days, className: "text-muted-foreground" },
    { header: "Remaining", cell: (b) => b.remaining_days, className: "text-muted-foreground" },
    {
      header: "",
      align: "right",
      cell: (b) => (
        <Button
          size="icon-sm"
          variant="ghost"
          onClick={() => setRemoving(b)}
        >
          <Trash2 />
          <span className="sr-only">Remove</span>
        </Button>
      ),
    },
  ];

  // Leave types this person doesn't have a balance for yet.
  const missingTypes = useMemo(() => {
    const have = new Set((balances ?? []).map((b) => b.leave_type.id));
    return (allTypes ?? []).filter((t) => !have.has(t.id));
  }, [balances, allTypes]);

  async function addType() {
    if (!addTypeId || addDays === "") return;
    try {
      await upsert.mutateAsync({
        leave_type_id: Number(addTypeId),
        entitled_days: Number(addDays),
      });
      toast.success("Leave type added");
      setAddTypeId("");
      setAddDays("");
    } catch {
      toast.error("Could not add the leave type.");
    }
  }

  if (isLoading) {
    return <p className="text-sm text-muted-foreground">Loading leave credits…</p>;
  }

  return (
    <div className="space-y-3">
      <DataTable
        columns={columns}
        rows={balances ?? []}
        rowKey={(b) => b.id}
        empty="No leave credits assigned to this employee yet."
        pageSize={10}
      />

      {/* Add a leave type this person doesn't have yet. */}
      {missingTypes.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-card border border-border bg-card p-3">
          <SimpleSelect
            className="h-9 w-48"
            value={addTypeId}
            onValueChange={setAddTypeId}
            placeholder="Add leave type…"
            options={missingTypes.map((t) => ({ value: String(t.id), label: t.name }))}
          />
          <Input
            type="number"
            step="1"
            min="0"
            placeholder="Days"
            className="h-9 w-24"
            value={addDays}
            onChange={(e) => setAddDays(e.target.value)}
          />
          <Button size="sm" onClick={addType} disabled={!addTypeId || addDays === ""}>
            Add
          </Button>
        </div>
      )}

      <DeleteConfirmDialog
        open={!!removing}
        onOpenChange={(o) => !o && setRemoving(null)}
        title="Remove leave type"
        question={`Remove “${removing?.leave_type.name ?? ""}” from this employee?`}
        description="This action cannot be undone. Their balance for this leave type will be discarded."
        confirmLabel="Remove"
        onConfirm={async () => {
          try {
            await remove.mutateAsync(removing!.id);
            toast.success("Leave type removed");
          } catch {
            toast.error("Could not remove it.");
          }
        }}
      />
    </div>
  );
}

function EntitledCell({
  value,
  onSave,
}: {
  value: number;
  onSave: (days: number) => Promise<unknown>;
}) {
  const [local, setLocal] = useState(String(value));

  async function save() {
    const days = Number(local);
    if (Number.isNaN(days) || days === value) return;
    try {
      await onSave(days);
    } catch {
      toast.error("Could not save.");
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
