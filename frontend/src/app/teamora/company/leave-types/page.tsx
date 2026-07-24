"use client";

import { useMemo, useState } from "react";
import { Plus, Pencil, Trash2, AlertTriangle } from "lucide-react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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
  useLeaveSetting,
  useUpdateLeaveSetting,
  useSharedAllotments,
  useUpsertSharedAllotment,
} from "@/modules/hr/hooks/use-leave-config";
import { EMPLOYMENT_STATUSES } from "@/modules/hr/types/leave-config";
import type { AllotmentMode } from "@/modules/hr/types/leave-config";
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

function AllotmentDefaults({ types }: { types: LeaveType[] }) {
  // "Not Shared" (default) = every leave type carries its own credits, keyed by
  // employment status. "Shared" = one pool per employment status, spent across
  // any leave type. The mode is a company-wide setting persisted server-side.
  const { data: setting } = useLeaveSetting();
  const updateMode = useUpdateLeaveSetting();

  // The mutation optimistically updates this cache, so the toggle is instant
  // and stable — no snap-back while the switch/backfill is in flight.
  const mode: AllotmentMode = setting?.allotment_mode ?? "not_shared";

  // Switching the model mid-year is significant (it changes how everyone's leave
  // is tracked), so we confirm first. `pendingMode` holds the target until then.
  const [pendingMode, setPendingMode] = useState<AllotmentMode | null>(null);

  function requestChange(next: AllotmentMode) {
    if (next === mode) return;
    setPendingMode(next);
  }

  async function confirmChange() {
    if (!pendingMode) return;
    const next = pendingMode;
    try {
      await updateMode.mutateAsync(next);
      toast.success(
        next === "shared"
          ? "Switched to a shared leave pool."
          : "Switched to per-leave-type credits.",
      );
    } catch (err) {
      // The server blocks a switch while requests are pending (guardrail) — surface
      // that exact message. The optimistic toggle rolls back on its own.
      const msg =
        err instanceof AxiosError
          ? (err.response?.data?.errors?.allotment_mode?.[0] ??
            err.response?.data?.message)
          : undefined;
      toast.error(msg ?? "Could not change the allotment mode.");
    } finally {
      setPendingMode(null);
    }
  }

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
        <AllotmentModeToggle mode={mode} onChange={requestChange} />
      </div>

      {mode === "not_shared" ? (
        <NotSharedGrid types={types} />
      ) : (
        <SharedGrid types={types} />
      )}

      <SwitchModeConfirmDialog
        open={pendingMode !== null}
        target={pendingMode}
        pending={updateMode.isPending}
        onOpenChange={(o) => !o && setPendingMode(null)}
        onConfirm={confirmChange}
      />
    </section>
  );
}

/**
 * Confirms switching the company-wide allotment model. Warns that it changes how
 * every employee's leave is tracked and is best done at a year boundary; the
 * server additionally blocks the switch while any request is pending.
 */
function SwitchModeConfirmDialog({
  open,
  target,
  pending,
  onOpenChange,
  onConfirm,
}: {
  open: boolean;
  target: AllotmentMode | null;
  pending: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}) {
  const toShared = target === "shared";

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2.5">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-warning-subtle text-warning">
              <AlertTriangle className="size-4" />
            </span>
            {toShared ? "Switch to a shared leave pool?" : "Switch to per-type credits?"}
          </DialogTitle>
          <DialogDescription>
            {toShared
              ? "Every employee's leave will be tracked as one shared pool instead of separate per-type credits."
              : "Every employee's leave will be tracked as separate per-type credits instead of one shared pool."}
          </DialogDescription>
        </DialogHeader>

        <ul className="list-disc space-y-1.5 pl-5 text-sm text-muted-foreground">
          <li>
            This changes how the whole company's leave is counted for the year — best
            done at the start of a leave year.
          </li>
          <li>
            Days already used don&apos;t carry across, so switching mid-year can hand
            back credits someone has already spent.
          </li>
          <li>You can&apos;t switch while any leave request is still pending.</li>
        </ul>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Keep current model</Button>
          </DialogClose>
          <Button onClick={onConfirm} disabled={pending}>
            {pending ? "Switching…" : "Switch model"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
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

/**
 * Shared mode: all leave types draw from ONE pool per employment status. The
 * first leave type row hosts the editable pool sizes; every other type is
 * disabled ("—") to signal it shares that single pool.
 */
function SharedGrid({ types }: { types: LeaveType[] }) {
  const { data: shared } = useSharedAllotments();

  const lookup = useMemo(() => {
    const m = new Map<string, number>();
    for (const a of shared ?? []) m.set(a.employment_status, a.days_per_year);
    return m;
  }, [shared]);

  // In the PH, Service Incentive Leave (SIL) is the statutory pool employees can
  // spend on any purpose — so SIL hosts the editable shared numbers. Fall back to
  // the first type if SIL isn't configured.
  const poolTypeId = useMemo(() => {
    const sil = types.find((t) => t.code === "SIL");
    return sil?.id ?? types[0]?.id ?? null;
  }, [types]);

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
          {types.map((t) => {
            const isPoolRow = t.id === poolTypeId;
            return (
              <tr key={t.id} className="border-b border-border last:border-0">
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <span
                      className={cn(
                        "size-2.5 shrink-0 rounded-full",
                        !isPoolRow && "opacity-40",
                      )}
                      style={{ backgroundColor: t.color ?? "#94a3b8" }}
                    />
                    <span
                      className={cn(
                        "font-medium",
                        isPoolRow ? "text-foreground" : "text-muted-foreground",
                      )}
                    >
                      {t.name}
                    </span>
                  </div>
                </td>
                {EMPLOYMENT_STATUSES.map((s) => (
                  <td key={s.value} className="px-4 py-3 text-center">
                    {isPoolRow ? (
                      <SharedAllotmentCell
                        key={`${s.value}:${lookup.get(s.value) ?? 0}`}
                        status={s.value}
                        value={lookup.get(s.value) ?? 0}
                      />
                    ) : (
                      <Input
                        disabled
                        aria-hidden
                        tabIndex={-1}
                        className="mx-auto h-8 w-16 text-center"
                        value=""
                        readOnly
                      />
                    )}
                  </td>
                ))}
              </tr>
            );
          })}
          {types.length === 0 && (
            <tr>
              <td
                colSpan={EMPLOYMENT_STATUSES.length + 1}
                className="px-4 py-10 text-center text-sm text-muted-foreground"
              >
                Add a leave type to set the shared pool.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function SharedAllotmentCell({
  status,
  value,
}: {
  status: string;
  value: number;
}) {
  const upsert = useUpsertSharedAllotment();
  const [local, setLocal] = useState(String(value));

  async function save() {
    const parsed = Number(local);
    if (Number.isNaN(parsed)) {
      setLocal(String(value));
      return;
    }
    const days = Math.max(0, Math.round(parsed));
    if (days === value) {
      setLocal(String(days));
      return;
    }
    try {
      await upsert.mutateAsync({ employment_status: status, days_per_year: days });
      setLocal(String(days));
    } catch {
      toast.error("Could not save the shared allotment.");
      setLocal(String(value));
    }
  }

  return (
    <Input
      type="number"
      step="1"
      min="0"
      className="mx-auto h-8 w-16 text-center"
      value={local}
      onChange={(e) => setLocal(e.target.value)}
      onBlur={save}
    />
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
    const parsed = Number(local);
    if (Number.isNaN(parsed)) {
      setLocal(String(value));
      return;
    }
    // Allotments are whole days only.
    const days = Math.max(0, Math.round(parsed));
    if (days === value) {
      setLocal(String(days));
      return;
    }
    try {
      await upsert.mutateAsync({
        leave_type_id: leaveTypeId,
        employment_status: status,
        days_per_year: days,
      });
      setLocal(String(days));
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
      className="mx-auto h-8 w-16 text-center"
      value={local}
      onChange={(e) => setLocal(e.target.value)}
      onBlur={save}
    />
  );
}
