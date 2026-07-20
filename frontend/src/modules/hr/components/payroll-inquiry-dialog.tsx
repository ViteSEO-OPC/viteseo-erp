"use client";

import { useState } from "react";
import { Paperclip } from "lucide-react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

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
import { formatDate } from "@/core/lib/format";
import { useCreatePayrollInquiry } from "@/modules/hr/hooks/use-requests";
import type { PayslipSummary } from "@/modules/hr/types/payroll";

const TOPICS = [
  "Wrong deduction",
  "Missing pay",
  "Incorrect hours",
  "Reimbursement not reflected",
  "Tax question",
  "Other",
];

const selectClass =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring";

/**
 * "Raise an inquiry" about ONE specific payslip — an employee's payroll question for
 * HR (distinct from requesting a signed hard copy). Always tied to the payslip it was
 * opened from, so HR sees exactly which pay period it concerns. Carries a subject,
 * topic, description and an optional screenshot.
 */
export function PayrollInquiryDialog({
  open,
  onOpenChange,
  payslip,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The payslip this inquiry is about (the one currently open). */
  payslip?: PayslipSummary;
}) {
  const mut = useCreatePayrollInquiry();
  const [subject, setSubject] = useState("");
  const [topic, setTopic] = useState(TOPICS[0]);
  const [message, setMessage] = useState("");
  const [file, setFile] = useState<File | null>(null);

  // Reset on close so the next open starts fresh (no effect needed).
  function handleOpenChange(next: boolean) {
    if (!next) {
      setSubject("");
      setTopic(TOPICS[0]);
      setMessage("");
      setFile(null);
    }
    onOpenChange(next);
  }

  const period = payslip
    ? `${formatDate(payslip.cycle_start)} – ${formatDate(payslip.cycle_end)}`
    : null;
  const canSubmit = subject.trim() !== "" && message.trim() !== "";

  async function submit() {
    if (!canSubmit) return;
    try {
      await mut.mutateAsync({
        subject: subject.trim(),
        topic,
        message: message.trim(),
        payslip_id: payslip?.id ?? null,
        attachment: file,
      });
      toast.success("Inquiry submitted", "HR has been notified.");
      handleOpenChange(false);
    } catch (err) {
      const errors = (err instanceof AxiosError ? err.response?.data?.errors : undefined) as
        | Record<string, string[]>
        | undefined;
      const msg =
        (err instanceof AxiosError ? err.response?.data?.message : undefined) ??
        (errors ? Object.values(errors)[0]?.[0] : undefined) ??
        "Could not submit. Please try again.";
      toast.error(msg);
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto lg:max-w-lg">
        <DialogHeader>
          <DialogTitle>Raise an inquiry</DialogTitle>
          <DialogDescription>
            {period
              ? `About your payslip for ${period}. HR is notified the moment you submit.`
              : "Describe the concern. HR is notified the moment you submit."}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="inq-subject">Subject</Label>
            <Input
              id="inq-subject"
              placeholder="e.g. Overtime not reflected"
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="inq-topic">Topic</Label>
              <select
                id="inq-topic"
                className={selectClass}
                value={topic}
                onChange={(e) => setTopic(e.target.value)}
              >
                {TOPICS.map((t) => (
                  <option key={t} value={t}>{t}</option>
                ))}
              </select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="inq-period">Pay period</Label>
              <div
                id="inq-period"
                className="flex h-9 items-center rounded-lg border border-input bg-muted/40 px-2.5 text-sm text-muted-foreground"
              >
                {period ?? "—"}
              </div>
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="inq-desc">Description</Label>
            <textarea
              id="inq-desc"
              rows={3}
              placeholder="Explain what looks off and what you expected…"
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
            />
          </div>

          <div className="space-y-1.5">
            <Label>
              Attachment <span className="font-normal text-muted-foreground">(optional)</span>
            </Label>
            <label className="flex h-9 cursor-pointer items-center gap-2 rounded-lg border border-input px-2.5 text-sm hover:bg-muted/50">
              <Paperclip className="size-4 shrink-0 text-muted-foreground" />
              <span className={cn("truncate", file ? "text-foreground" : "text-muted-foreground")}>
                {file ? file.name : "Attach a screenshot of your payslip"}
              </span>
              <input
                type="file"
                accept=".jpg,.jpeg,.png,.pdf"
                className="hidden"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              />
            </label>
          </div>
        </div>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="ghost">Cancel</Button>
          </DialogClose>
          <Button disabled={!canSubmit || mut.isPending} onClick={submit}>
            {mut.isPending ? "Submitting…" : "Submit inquiry"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
