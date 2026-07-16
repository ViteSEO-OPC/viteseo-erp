"use client";

import { useEffect, useState } from "react";
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
 * "Request payroll inquiry" — an employee raises a payroll question for HR (distinct
 * from requesting a payslip copy). Carries a subject, topic, the related pay period,
 * a description and an optional payslip screenshot.
 */
export function PayrollInquiryDialog({
  open,
  onOpenChange,
  periods,
  defaultPayslipId,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  periods: PayslipSummary[];
  defaultPayslipId?: number;
}) {
  const mut = useCreatePayrollInquiry();
  const [subject, setSubject] = useState("");
  const [topic, setTopic] = useState(TOPICS[0]);
  const [payslipId, setPayslipId] = useState("");
  const [message, setMessage] = useState("");
  const [file, setFile] = useState<File | null>(null);

  useEffect(() => {
    if (open) {
      setSubject("");
      setTopic(TOPICS[0]);
      setPayslipId(defaultPayslipId ? String(defaultPayslipId) : "");
      setMessage("");
      setFile(null);
    }
  }, [open, defaultPayslipId]);

  const canSubmit = subject.trim() !== "" && message.trim() !== "";

  async function submit() {
    if (!canSubmit) return;
    try {
      await mut.mutateAsync({
        subject: subject.trim(),
        topic,
        message: message.trim(),
        payslip_id: payslipId ? Number(payslipId) : null,
        attachment: file,
      });
      toast.success("Inquiry submitted", "HR has been notified.");
      onOpenChange(false);
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
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Request payroll inquiry</DialogTitle>
          <DialogDescription>
            Describe the concern. HR is notified the moment you submit.
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

          <div className="grid grid-cols-2 gap-3">
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
              <Label htmlFor="inq-period">Related pay period</Label>
              <select
                id="inq-period"
                className={selectClass}
                value={payslipId}
                onChange={(e) => setPayslipId(e.target.value)}
              >
                <option value="">Not specific</option>
                {periods.map((p) => (
                  <option key={p.id} value={p.id}>
                    {formatDate(p.cycle_start)} – {formatDate(p.cycle_end)}
                  </option>
                ))}
              </select>
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
