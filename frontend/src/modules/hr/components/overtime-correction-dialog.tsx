"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import { useCreateOvertimeRequestForEmployee } from "@/modules/hr/hooks/use-overtime";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { TimePicker } from "@/modules/hr/ui/time-picker";

/** Mirrors StoreRetroactiveOvertimeRequest::MAX_BACKDATE_DAYS. */
const MAX_BACKDATE_DAYS = 90;

function daysAgo(days: number): string {
  const d = new Date(`${manilaToday()}T00:00:00`);
  d.setDate(d.getDate() - days);
  return d.toLocaleDateString("en-CA");
}

/**
 * HR files overtime for an employee on a date that has already passed (item 10).
 *
 * THE CASE: somebody worked late, never filed, and the cutoff is closing. Until now the only
 * remedies were to edit the attendance record — which changes what was CLOCKED, not what was
 * AUTHORISED — or to pay it outside the system entirely.
 *
 * IT DOES NOT APPROVE ANYTHING. The request lands pending in the queue below, exactly where an
 * employee's own would, and somebody still has to approve it. That is the point rather than an
 * oversight: approving is where the audit entry and the settled-period retro flag live, and a
 * dialog that filed and approved in one press would skip both. It also leaves room for the officer
 * who files and the one who signs off to be different people.
 *
 * Errors are surfaced from the server rather than re-validated here. The date bounds, the overlap
 * rule and "overtime starts after the shift ends" all live in one place on the server, and a second
 * copy in this dialog would be the copy that goes stale.
 */
export function OvertimeCorrectionDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { data: employees } = useEmployees();
  const create = useCreateOvertimeRequestForEmployee();

  const [employeeId, setEmployeeId] = useState("");
  const [date, setDate] = useState(manilaToday());
  const [from, setFrom] = useState("17:00");
  const [to, setTo] = useState("19:00");
  const [reason, setReason] = useState("");

  function reset() {
    setEmployeeId("");
    setDate(manilaToday());
    setFrom("17:00");
    setTo("19:00");
    setReason("");
  }

  async function submit() {
    if (!employeeId) {
      toast.error("Pick the employee this overtime belongs to.");
      return;
    }

    try {
      await create.mutateAsync({
        employee_id: Number(employeeId),
        work_date: date,
        time_from: from,
        time_to: to,
        reason,
      });
      toast.success("Filed — it is now waiting for approval below.");
      reset();
      onOpenChange(false);
    } catch (error) {
      // The server owns every rule here, so show what it actually said rather than a generic
      // failure — "this overlaps a request already filed" is the whole value of the message.
      const errors = (error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>)
        ?.response?.data;
      const first = errors?.errors ? Object.values(errors.errors)[0]?.[0] : undefined;
      toast.error(first ?? errors?.message ?? "Could not file this overtime.");
    }
  }

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) reset(); onOpenChange(o); }}>
      <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>File overtime for an employee</DialogTitle>
          <DialogDescription>
            For overtime that was worked but never filed. It goes into the queue below as
            pending — you still have to approve it.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="otc-employee">Employee</Label>
            <SimpleSelect
              id="otc-employee"
              className="w-full"
              value={employeeId}
              onValueChange={setEmployeeId}
              placeholder="Choose an employee"
              options={(employees ?? []).map((e) => ({
                value: String(e.id),
                label: e.name ?? `#${e.id}`,
              }))}
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="otc-date">Date worked</Label>
            <DatePicker
              id="otc-date"
              value={date}
              onChange={(v) => setDate(v ?? date)}
              // Bounds mirror the server's; it still has the final say.
              min={daysAgo(MAX_BACKDATE_DAYS)}
              max={manilaToday()}
              className="w-full"
            />
          </div>

          <div className="flex flex-wrap gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="otc-from">From</Label>
              <TimePicker id="otc-from" value={from} onChange={setFrom} className="w-36" />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="otc-to">To</Label>
              <TimePicker id="otc-to" value={to} onChange={setTo} className="w-36" />
            </div>
          </div>
          <p className="text-xs text-muted-foreground">
            Overtime starts after that employee&apos;s shift ends. An end time earlier than the
            start means the work carried past midnight.
          </p>

          <div className="space-y-1.5">
            <Label htmlFor="otc-reason">Why is this being filed now?</Label>
            <textarea
              id="otc-reason"
              rows={3}
              className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
              placeholder="e.g. Worked the release cutover on the 17th, forgot to file it"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              Recorded against your name — this is the only account of the correction anyone will
              have later.
            </p>
          </div>
        </div>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button onClick={submit} disabled={create.isPending}>
            {create.isPending ? "Filing…" : "File as pending"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
