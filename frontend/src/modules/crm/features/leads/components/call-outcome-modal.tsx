"use client";

import { useId, useState } from "react";
import { CheckCircle2, Loader2, X } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/modules/crm/components/ui/select";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { useCompleteCall } from "@/modules/crm/features/leads/hooks/use-lead-calls";
import {
  COMPLETION_OUTCOMES,
  OUTCOME_META,
  type CallOutcome,
} from "@/modules/crm/features/leads/types/call-types";

const TEXTAREA =
  "min-h-[88px] w-full resize-none rounded-lg border border-border bg-background px-3 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  leadId: string;
  callId: string | null;
  defaultNotes: string;
};

/**
 * Complete-a-call modal. Captures the required disposition (+ optional notes carried over from the
 * Active Call panel) and PATCHes /calls/{id}/complete. `cancelled` is deliberately NOT offered — that
 * is the separate Discard action, and the backend rejects it here. The `{open && <Form/>}` idiom
 * remounts the form each open so state resets between calls.
 */
export function CallOutcomeModal({ open, onOpenChange, leadId, callId, defaultNotes }: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col gap-0 p-0 sm:max-w-[460px]">
        {open && callId ? (
          <OutcomeForm
            leadId={leadId}
            callId={callId}
            defaultNotes={defaultNotes}
            onClose={() => onOpenChange(false)}
          />
        ) : null}
      </DialogContent>
    </Dialog>
  );
}

function OutcomeForm({
  leadId,
  callId,
  defaultNotes,
  onClose,
}: {
  leadId: string;
  callId: string;
  defaultNotes: string;
  onClose: () => void;
}) {
  const complete = useCompleteCall(leadId);
  const [outcome, setOutcome] = useState<CallOutcome | "">("");
  const [notes, setNotes] = useState(defaultNotes);
  const [submitAttempted, setSubmitAttempted] = useState(false);
  const errorId = useId();

  const outcomeError = submitAttempted && !outcome ? "Please choose an outcome." : "";

  function handleSubmit() {
    setSubmitAttempted(true);
    if (!outcome) return;
    complete.mutate(
      { callId, outcome, notes: notes.trim() ? notes.trim() : null },
      { onSuccess: onClose },
    );
  }

  return (
    <>
      <DialogHeader className="px-6 pb-0 pt-6">
        <div className="flex items-start justify-between gap-4">
          <div className="flex flex-col gap-1">
            <DialogTitle className="text-xl font-bold text-card-foreground">Complete Call</DialogTitle>
            <DialogDescription>Log the outcome of this call.</DialogDescription>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label="Close"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      </DialogHeader>

      <div className="flex flex-col gap-4 px-6 py-4">
        <FormField label="Outcome" required>
          <Select
            value={outcome}
            onValueChange={(next) => setOutcome(typeof next === "string" ? (next as CallOutcome) : "")}
          >
            <SelectTrigger
              className="w-full"
              aria-invalid={outcomeError ? true : undefined}
              aria-describedby={outcomeError ? errorId : undefined}
            >
              <SelectValue placeholder="Select an outcome…">
                {outcome ? OUTCOME_META[outcome].label : undefined}
              </SelectValue>
            </SelectTrigger>
            <SelectContent>
              {COMPLETION_OUTCOMES.map((value) => (
                <SelectItem key={value} value={value}>
                  {OUTCOME_META[value].label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {outcomeError ? (
            <p id={errorId} role="alert" className="mt-1 text-xs font-medium text-red-600">
              {outcomeError}
            </p>
          ) : null}
        </FormField>

        <FormField label="Notes" optional>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="What was discussed…"
            className={TEXTAREA}
            aria-label="Call notes"
          />
        </FormField>
      </div>

      <DialogFooter className="mx-0 mb-0 flex-row items-center justify-end gap-2.5 border-t-0 bg-transparent px-6 pb-6 pt-2">
        <Button variant="ghost" size="sm" onClick={onClose} disabled={complete.isPending}>
          Cancel
        </Button>
        <Button variant="default" size="sm" onClick={handleSubmit} disabled={complete.isPending}>
          {complete.isPending ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
          ) : (
            <CheckCircle2 className="h-4 w-4" aria-hidden />
          )}
          Complete Call
        </Button>
      </DialogFooter>
    </>
  );
}
