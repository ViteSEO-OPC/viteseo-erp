"use client";

import { useState } from "react";
import { Plus, Trash2 } from "lucide-react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { StatusBadge } from "@/core/components/status-badge";
import { DASH, RequestDetailDialog } from "@/modules/hr/components/request-detail-dialog";
import { formatDate } from "@/core/lib/format";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import { CHANGE_FIELDS } from "@/modules/hr/types/request";
import {
  GENDERS,
  CIVIL_STATUSES,
  BANK_ACCOUNT_TYPES,
} from "@/modules/hr/types/employee";
import {
  useCreateChangeRequest,
  useMyChangeRequests,
} from "@/modules/hr/hooks/use-requests";

type Row = { field: string; value: string };

const newRow = (): Row => ({ field: CHANGE_FIELDS[0].value, value: "" });

// Fields that deserve a real control rather than free text, so the value HR
// receives is already in the shape the employee record expects.
const DATE_FIELDS = new Set<string>(["date_of_birth"]);
const OPTION_FIELDS: Record<string, readonly string[]> = {
  gender: GENDERS,
  civil_status: CIVIL_STATUSES,
  bank_account_type: BANK_ACCOUNT_TYPES,
};

/**
 * Employee self-service: request changes to several personal/bank fields at once.
 * Each field submits as its own request so HR can approve/reject them individually.
 */
export function ChangeRequestModal({
  open,
  onOpenChange,
  employee,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  employee: Record<string, unknown> | undefined;
}) {
  const createMut = useCreateChangeRequest();
  const { data: history } = useMyChangeRequests();
  const [rows, setRows] = useState<Row[]>([newRow()]);
  // A past request opened from the history list, stacked over this dialog.
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (history ?? []).find((c) => c.id === detailId) ?? null;

  // Reset whenever the dialog opens — during render (React's recommended pattern) rather
  // than in an effect. Starts false so a dialog mounted already-open resets too.
  const [wasOpen, setWasOpen] = useState(false);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setRows([newRow()]);
    }
  }

  function currentValue(field: string): string {
    const v = employee?.[field];
    return v === null || v === undefined || v === "" ? "—" : String(v);
  }
  function update(i: number, patch: Partial<Row>) {
    setRows((rs) => rs.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
  }
  function remove(i: number) {
    setRows((rs) => (rs.length === 1 ? [newRow()] : rs.filter((_, idx) => idx !== i)));
  }

  const valid = rows.filter((r) => r.value.trim() !== "");

  async function submit() {
    if (!valid.length) return;
    try {
      await createMut.mutateAsync({
        changes: valid.map((r) => ({ field: r.field, new_value: r.value.trim() })),
      });
      toast.success(
        `Submitted ${valid.length} change ${valid.length === 1 ? "request" : "requests"}`,
        "HR reviews each change individually.",
      );
      onOpenChange(false);
    } catch (err) {
      const msg =
        err instanceof AxiosError
          ? (err.response?.data?.message ?? "Could not submit. Please try again.")
          : "Could not submit. Please try again.";
      toast.error(msg);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto overflow-x-hidden sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Request profile changes</DialogTitle>
          <DialogDescription>
            Propose updates to your details. Each change is reviewed by HR individually.
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-[60vh] space-y-4 overflow-y-auto overflow-x-hidden px-0.5">
          <div className="space-y-3">
            {rows.map((r, i) => (
              <div key={i} className="rounded-lg border border-border p-3">
                <div className="flex items-center gap-2">
                  <SimpleSelect
                    value={r.field}
                    // Clear the value too: a date or option value means nothing
                    // once the field it belonged to has changed.
                    onValueChange={(v) => update(i, { field: v, value: "" })}
                    options={CHANGE_FIELDS.map((f) => ({ value: f.value, label: f.label }))}
                  />
                  <button
                    type="button"
                    onClick={() => remove(i)}
                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-danger"
                    aria-label="Remove change"
                  >
                    <Trash2 className="size-4" />
                  </button>
                </div>
                <p className="mt-2 text-caption text-muted-foreground">
                  Current: <span className="text-foreground">{currentValue(r.field)}</span>
                </p>
                <div className="mt-2">
                  {DATE_FIELDS.has(r.field) ? (
                    <DatePicker
                      value={r.value}
                      onChange={(v) => update(i, { value: v })}
                      max={manilaToday()}
                      placeholder="Pick the new date"
                    />
                  ) : OPTION_FIELDS[r.field] ? (
                    <SimpleSelect
                      value={r.value}
                      onValueChange={(v) => update(i, { value: v })}
                      placeholder="Select the new value"
                      options={OPTION_FIELDS[r.field].map((o) => ({ value: o, label: o }))}
                    />
                  ) : (
                    <Input
                      placeholder="New value"
                      value={r.value}
                      onChange={(e) => update(i, { value: e.target.value })}
                    />
                  )}
                </div>
              </div>
            ))}
          </div>

          <Button type="button" variant="ghost" size="sm" onClick={() => setRows((rs) => [...rs, newRow()])}>
            <Plus />
            Add another change
          </Button>

          {/* Recent change-request history */}
          {(history ?? []).length > 0 && (
            <div className="border-t border-border pt-3">
              <p className="mb-2 text-label font-bold uppercase tracking-wide text-muted-foreground">
                Recent requests
              </p>
              <div className="space-y-2">
                {(history ?? []).slice(0, 5).map((c) => (
                  <div
                    key={c.id}
                    role="button"
                    tabIndex={0}
                    onClick={() => setDetailId(c.id)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" || e.key === " ") {
                        e.preventDefault();
                        setDetailId(c.id);
                      }
                    }}
                    className="flex cursor-pointer items-center justify-between gap-2 rounded-md px-1 py-1 text-sm transition-colors hover:bg-muted/50"
                  >
                    <div className="min-w-0">
                      <p className="font-medium text-foreground">{c.field_label}</p>
                      <p className="truncate text-caption text-muted-foreground">
                        {c.old_value || "—"} → {c.new_value} · {formatDate(c.created_at)}
                      </p>
                    </div>
                    <StatusBadge status={c.status} />
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button disabled={!valid.length || createMut.isPending} onClick={submit}>
            {createMut.isPending ? "Submitting…" : `Submit ${valid.length || ""} change${valid.length === 1 ? "" : "s"}`.trim()}
          </Button>
        </DialogFooter>

        {detail && (
          <RequestDetailDialog
            open
            onOpenChange={(o) => !o && setDetailId(null)}
            title={`${detail.field_label} change`}
            status={detail.status}
            submitted={detail.created_at}
            fields={[
              { label: "Field", value: detail.field_label },
              { label: "Was", value: detail.old_value || DASH },
              {
                label: "Asked to be",
                value: <span className="font-medium">{detail.new_value || DASH}</span>,
              },
            ]}
            decision={{
              label: detail.status === "rejected" ? "Why it was declined" : "HR's decision",
              by: detail.reviewed_by,
              at: detail.reviewed_at,
              note: detail.review_note,
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}
