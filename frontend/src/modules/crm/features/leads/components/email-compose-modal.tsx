"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { X } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { Input } from "@/modules/crm/components/ui/input";
import { Textarea } from "@/modules/crm/components/ui/textarea";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { useSendEmail } from "@/modules/crm/features/follow-ups/hooks/use-outreach-mutations";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  leadId: string;
  leadName: string;
  /** Default recipient — the lead's email (may be blank if the lead has none). */
  leadEmail?: string;
};

/**
 * Draft & Send Email (Quick Actions). Reuses the existing outreach endpoint via `useSendEmail`
 * (POST /leads/{id}/emails) — which logs the `email.sent` Activity and invalidates the global
 * activity feeds — and additionally refreshes THIS lead's Activity Timeline so the sent email
 * appears immediately. No new endpoint, business logic, or API contract is introduced.
 *
 * Render with key={leadId} so switching leads resets the draft.
 */
export function EmailComposeModal({ open, onOpenChange, leadId, leadName, leadEmail }: Props) {
  const queryClient = useQueryClient();
  const sendEmail = useSendEmail();
  const [to, setTo] = useState(leadEmail ?? "");
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");

  const canSend = !!to.trim() && !!subject.trim() && !!body.trim() && !sendEmail.isPending;

  const handleSend = () => {
    if (!canSend) return;
    sendEmail.mutate(
      { leadId, subject: subject.trim(), body: body.trim(), to: to.trim() },
      {
        onSuccess: () => {
          // The email.sent Activity lands on this lead — refresh its timeline (nested under
          // leads.detail(id), so this precise key is enough).
          queryClient.invalidateQueries({ queryKey: queryKeys.leads.activities(leadId) });
          onOpenChange(false);
        },
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onOpenChange(false); }}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col p-0 sm:max-w-[520px]">
        <DialogHeader className="border-b border-border-subtle px-6 py-4">
          <div className="flex items-center justify-between">
            <DialogTitle className="text-lg font-bold text-card-foreground">
              Draft &amp; send email
            </DialogTitle>
            <button type="button" onClick={() => onOpenChange(false)} aria-label="Close" className={closeButtonClass}>
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        <div className="flex flex-col gap-4 px-6 py-5">
          <p className="text-sm text-muted-foreground">
            Send an outreach email to{" "}
            <span className="font-semibold text-foreground">{leadName}</span>.
          </p>
          <FormField label="To" required>
            <Input
              type="email"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              placeholder="recipient@example.com"
              aria-label="Recipient email"
            />
          </FormField>
          <FormField label="Subject" required>
            <Input
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
              maxLength={255}
              placeholder="Subject line"
              aria-label="Email subject"
            />
          </FormField>
          <FormField label="Message" required>
            <Textarea
              className="min-h-[180px]"
              value={body}
              onChange={(e) => setBody(e.target.value)}
              placeholder="Write your message…"
              aria-label="Email body"
            />
          </FormField>
        </div>

        <DialogFooter className="flex-row justify-end gap-3 border-t border-border-subtle px-6 py-4">
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)} disabled={sendEmail.isPending}>
            Cancel
          </Button>
          <Button variant="default" size="sm" onClick={handleSend} disabled={!canSend}>
            {sendEmail.isPending ? "Sending…" : "Send email"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
