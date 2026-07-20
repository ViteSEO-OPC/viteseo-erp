"use client";

import { useEffect, useState } from "react";
import { notify as toast } from "@/core/lib/notify";

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
import { useScheduleEmployeeMeeting } from "@/modules/hr/hooks/use-concerns";

/**
 * HR schedules a meeting with an employee (from Attendance) about their lateness /
 * violations. On submit the employee is notified (bell + their meeting list).
 */
export function AttendanceMeetingDialog({
  employeeId,
  employeeName,
  open,
  onOpenChange,
}: {
  employeeId: number | null;
  employeeName: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const scheduleMut = useScheduleEmployeeMeeting();
  const [when, setWhen] = useState("");
  const [location, setLocation] = useState("");
  const [agenda, setAgenda] = useState("");

  useEffect(() => {
    if (open) {
      setWhen("");
      setLocation("");
      setAgenda(`Discuss recent attendance — lateness and violations with ${employeeName ?? "the employee"}.`);
    }
  }, [open, employeeName]);

  async function submit() {
    if (!employeeId || !when) return;
    try {
      await scheduleMut.mutateAsync({
        employeeId,
        input: { scheduled_at: when.replace("T", " "), location: location.trim() || null, agenda: agenda.trim() || null },
      });
      toast.success("Meeting scheduled", `${employeeName ?? "The employee"} has been notified.`);
      onOpenChange(false);
    } catch {
      toast.error("Could not schedule the meeting.");
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Schedule a meeting</DialogTitle>
          <DialogDescription>
            Set up a meeting with {employeeName ?? "this employee"}{" "}
            about their attendance. They&apos;ll be notified in-app.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="am-when">Date &amp; time</Label>
            <Input id="am-when" type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="am-loc">Location (optional)</Label>
            <Input
              id="am-loc"
              placeholder="e.g. HR Room 2 or a meeting link"
              value={location}
              onChange={(e) => setLocation(e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="am-agenda">Agenda</Label>
            <textarea
              id="am-agenda"
              rows={3}
              value={agenda}
              onChange={(e) => setAgenda(e.target.value)}
              className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            />
          </div>
        </div>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button disabled={scheduleMut.isPending || !when} onClick={submit}>
            {scheduleMut.isPending ? "Scheduling…" : "Schedule meeting"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
