"use client";

import { useState } from "react";
import { AlertTriangle } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import { AxiosError } from "axios";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import {
  useDeclareWorkSuspension,
  useUpdateWorkSuspension,
} from "@/modules/hr/hooks/use-work-suspensions";
import type { WorkSuspension } from "@/modules/hr/types/holiday";

/**
 * Declare a day non-working, or amend one.
 *
 * The paid/unpaid choice is two large buttons rather than a checkbox or a select, and neither
 * is preselected on a new declaration. It is the only thing on this form that moves money, and
 * a default is how it gets chosen by not being read — the server refuses a create without it
 * for the same reason.
 */
export function WorkSuspensionFormDialog({
  open,
  onOpenChange,
  suspension,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  suspension?: WorkSuspension | null;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        {open && (
          <Body
            key={suspension?.id ?? "new"}
            suspension={suspension ?? null}
            onDone={() => onOpenChange(false)}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function Body({
  suspension,
  onDone,
}: {
  suspension: WorkSuspension | null;
  onDone: () => void;
}) {
  const isEdit = !!suspension;
  const declareMut = useDeclareWorkSuspension();
  const updateMut = useUpdateWorkSuspension();

  const [name, setName] = useState(suspension?.name ?? "");
  const [date, setDate] = useState(suspension?.date ?? "");
  const [note, setNote] = useState(suspension?.note ?? "");
  // null on a new declaration — nothing is chosen until HR chooses it.
  const [isPaid, setIsPaid] = useState<boolean | null>(suspension?.is_paid ?? null);

  const pending = declareMut.isPending || updateMut.isPending;
  const valid = name.trim() !== "" && (isEdit || date !== "") && isPaid !== null;

  async function submit() {
    if (!valid) return;

    try {
      const result = isEdit
        ? await updateMut.mutateAsync({
            id: suspension.id,
            name: name.trim(),
            is_paid: isPaid as boolean,
            note: note.trim() || null,
          })
        : await declareMut.mutateAsync({
            date,
            name: name.trim(),
            is_paid: isPaid as boolean,
            note: note.trim() || null,
          });

      toast.success(
        isEdit ? "Suspension updated" : "Day declared non-working",
        isPaid
          ? "Nobody needs to time in, and the day costs no one a day's pay."
          : "Nobody needs to time in. The day is deducted as one unpaid day.",
      );

      // The period was already priced without this day. Surfaced as its own warning toast
      // rather than folded into the success line, because it is an action for HR to take.
      if (result.warning) {
        toast.warning("Payroll needs re-running", result.warning);
      }

      onDone();
    } catch (err) {
      const msg =
        err instanceof AxiosError
          ? (err.response?.data?.errors?.date?.[0] ??
            err.response?.data?.message ??
            "Could not save the suspension.")
          : "Could not save the suspension.";
      toast.error(msg);
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{isEdit ? "Edit non-working day" : "Declare a non-working day"}</DialogTitle>
        <DialogDescription>
          For a typhoon, a flood, a power outage or a company shutdown. Nobody is expected to
          time in and nobody is flagged absent — you decide whether the day is still paid.
        </DialogDescription>
      </DialogHeader>

      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="ws-name">What happened</Label>
          <Input
            id="ws-name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Typhoon Egay — Signal No. 3"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="ws-date">Date</Label>
          <DatePicker id="ws-date" value={date} onChange={setDate} disabled={isEdit} />
          {isEdit && (
            <p className="text-xs text-muted-foreground">
              To move this to another date, withdraw it and declare the new one.
            </p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label>Is the day paid?</Label>
          <div className="grid grid-cols-2 gap-2">
            <button
              type="button"
              aria-pressed={isPaid === true}
              onClick={() => setIsPaid(true)}
              className={cn(
                "rounded-lg border p-3 text-left transition-colors",
                isPaid === true
                  ? "border-success bg-success-subtle"
                  : "border-input hover:bg-muted",
              )}
            >
              <span className="block text-sm font-semibold text-foreground">Paid</span>
              <span className="mt-0.5 block text-xs text-muted-foreground">
                Salary still covers it. Costs nobody a day.
              </span>
            </button>
            <button
              type="button"
              aria-pressed={isPaid === false}
              onClick={() => setIsPaid(false)}
              className={cn(
                "rounded-lg border p-3 text-left transition-colors",
                isPaid === false
                  ? "border-danger bg-danger-subtle"
                  : "border-input hover:bg-muted",
              )}
            >
              <span className="block text-sm font-semibold text-foreground">Unpaid</span>
              <span className="mt-0.5 block text-xs text-muted-foreground">
                Deducted as one unpaid day.
              </span>
            </button>
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="ws-note">
            Note <span className="font-normal text-muted-foreground">(optional)</span>
          </Label>
          <textarea
            id="ws-note"
            rows={2}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            maxLength={1000}
            className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            placeholder="e.g. PAGASA raised Signal No. 3 over Metro Manila"
          />
        </div>

        {isPaid === false && (
          <div className="flex items-start gap-2 rounded-lg bg-warning-subtle px-3 py-2 text-sm text-warning">
            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
            <span>
              An unpaid suspension deducts a full day from everyone who didn&apos;t work it.
              Anyone who did come in is still paid normally.
            </span>
          </div>
        )}
      </div>

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button disabled={pending || !valid} onClick={submit}>
          {pending ? "Saving…" : isEdit ? "Save changes" : "Declare non-working"}
        </Button>
      </DialogFooter>
    </>
  );
}
