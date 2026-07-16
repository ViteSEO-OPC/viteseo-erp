"use client";

import { useState, type ReactNode } from "react";
import { isAxiosError } from "axios";
import { X } from "lucide-react";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetFooter,
  SheetTitle,
} from "@/modules/crm/components/ui/sheet";
import { FormField, FormFieldGrid } from "@/modules/crm/components/forms/form-field/form-field";
import { DangerConfirmRegion } from "@/modules/crm/components/shared/confirm-dialog/danger-confirm-region";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { useUpdateLead, useDeleteLead } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";

const FIELD_STYLE =
  "h-9 w-full rounded-lg border border-border bg-muted px-3 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring";

const SELECT_STYLE =
  "h-9 w-full rounded-lg border border-border bg-muted px-3 text-sm text-foreground appearance-none focus:outline-none focus:ring-2 focus:ring-ring";

/** Presentation-only grouping wrapper — labels a related cluster of fields for scanability.
    Purely visual; introduces no state, validation, or field changes. */
function FormSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-4">
      <h4 className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
        {title}
      </h4>
      {children}
    </section>
  );
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  lead: Lead | null;
  /** Delete requires the leads.delete permission (BDMs lack it) — gates the danger region. */
  canDelete?: boolean;
  onDeleted?: () => void;
};

export function EditLeadDrawer({ open, onOpenChange, lead, canDelete, onDeleted }: Props) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        showCloseButton={false}
        className="flex w-full flex-col p-0 sm:max-w-md"
      >
        {lead ? (
          <EditLeadForm
            key={lead.id}
            lead={lead}
            canDelete={canDelete}
            onClose={() => onOpenChange(false)}
            onDeleted={onDeleted}
          />
        ) : null}
      </SheetContent>
    </Sheet>
  );
}

function EditLeadForm({
  lead,
  canDelete,
  onClose,
  onDeleted,
}: {
  lead: Lead;
  canDelete?: boolean;
  onClose: () => void;
  onDeleted?: () => void;
}) {
  const [businessName, setBusinessName] = useState(lead.business);
  const [industry, setIndustry] = useState(lead.industry ?? "");
  const [contactName, setContactName] = useState(lead.contact);
  const [email, setEmail] = useState(lead.email ?? "");
  const [phone, setPhone] = useState(lead.phone ?? "");
  const [websiteStatus, setWebsiteStatus] = useState(lead.websiteStatus);
  const [priority, setPriority] = useState(lead.priority);
  const [emailOutreach, setEmailOutreach] = useState(lead.emailOutreach);
  const [coldCalling, setColdCalling] = useState(lead.coldCalling);
  const [status, setStatus] = useState(lead.updateStatus);
  // Estimated Deal Value (prospect-stage) — leads.amount, NOT revenue.
  const [amount, setAmount] = useState(lead.amount !== null ? String(lead.amount) : "");
  const [error, setError] = useState("");
  const [confirmOpen, setConfirmOpen] = useState(false);

  const updateLead = useUpdateLead();
  const deleteLead = useDeleteLead();

  const handleSave = () => {
    setError("");
    updateLead.mutate(
      {
        id: lead.id,
        payload: {
          business_name: businessName.trim(),
          contact_name: contactName.trim(),
          email: email.trim() || null,
          phone: phone.trim() || null,
          industry: industry.trim() || null,
          website_status: websiteStatus,
          priority,
          email_outreach: emailOutreach,
          cold_calling: coldCalling,
          status,
          amount: amount.trim() === "" ? null : Number(amount),
        },
      },
      {
        onSuccess: onClose,
        onError: (err) =>
          setError(
            isAxiosError(err)
              ? (err.response?.data?.message ?? "Failed to save changes")
              : "Failed to save changes",
          ),
      },
    );
  };

  const handleDelete = () => {
    deleteLead.mutate(lead.id, {
      onSuccess: () => {
        setConfirmOpen(false);
        onClose();
        onDeleted?.();
      },
    });
  };

  return (
    <>
      <SheetHeader className="border-b border-border-subtle px-5 py-4">
        <div className="flex items-start justify-between">
          <div>
            <SheetTitle className="text-lg font-bold text-card-foreground">
              Edit Lead
            </SheetTitle>
            <p className="mt-0.5 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
              {lead.business}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-muted-foreground transition-colors hover:bg-secondary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label="Close"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      </SheetHeader>

      <div className="flex-1 overflow-y-auto px-5 py-5">
        <div className="flex flex-col gap-6">
          <FormSection title="Business">
            <FormField label="Business Name">
              <input
                type="text"
                value={businessName}
                onChange={(e) => setBusinessName(e.target.value)}
                className={FIELD_STYLE}
                aria-label="Business Name"
              />
            </FormField>

            <FormFieldGrid>
              <FormField label="Niche">
                <input
                  type="text"
                  value={industry}
                  onChange={(e) => setIndustry(e.target.value)}
                  className={FIELD_STYLE}
                  aria-label="Niche"
                />
              </FormField>
              <FormField label="Last Activity">
                <input
                  type="text"
                  value={formatActivityTimestamp(lead.lastActivity)}
                  readOnly
                  className={FIELD_STYLE}
                  aria-label="Last Activity"
                />
              </FormField>
            </FormFieldGrid>
          </FormSection>

          <div className="border-t border-border-subtle" />

          <FormSection title="Contact">
            <FormField label="Contact Name">
              <input
                type="text"
                value={contactName}
                onChange={(e) => setContactName(e.target.value)}
                className={FIELD_STYLE}
                aria-label="Contact Name"
              />
            </FormField>

            <FormFieldGrid>
              <FormField label="Email">
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className={FIELD_STYLE}
                  aria-label="Email"
                />
              </FormField>
              <FormField label="Phone Number">
                <input
                  type="tel"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  className={FIELD_STYLE}
                  aria-label="Phone Number"
                />
              </FormField>
            </FormFieldGrid>
          </FormSection>

          <div className="border-t border-border-subtle" />

          <FormSection title="Outreach & Status">
            <FormFieldGrid>
              <FormField label="Website Status">
                <select
                  value={websiteStatus}
                  onChange={(e) => setWebsiteStatus(e.target.value as Lead["websiteStatus"])}
                  className={SELECT_STYLE}
                  aria-label="Website Status"
                >
                  <option value="has-website">Has Website</option>
                  <option value="no-website">No Website</option>
                  <option value="needs-redesign">Needs Redesign</option>
                </select>
              </FormField>
              <FormField label="Priority">
                <select
                  value={priority}
                  onChange={(e) => setPriority(e.target.value as Lead["priority"])}
                  className={SELECT_STYLE}
                  aria-label="Priority"
                >
                  <option value="high">High</option>
                  <option value="medium">Medium</option>
                  <option value="low">Low</option>
                </select>
              </FormField>
            </FormFieldGrid>

            <FormFieldGrid>
              <FormField label="Email Outreach">
                <select
                  value={emailOutreach}
                  onChange={(e) => setEmailOutreach(e.target.value as Lead["emailOutreach"])}
                  className={SELECT_STYLE}
                  aria-label="Email Outreach"
                >
                  <option value="sent">Sent</option>
                  <option value="opened">Opened</option>
                  <option value="replied">Replied</option>
                  <option value="none">None</option>
                </select>
              </FormField>
              <FormField label="Cold Calling">
                <select
                  value={coldCalling}
                  onChange={(e) => setColdCalling(e.target.value as Lead["coldCalling"])}
                  className={SELECT_STYLE}
                  aria-label="Cold Calling"
                >
                  <option value="called">Called</option>
                  <option value="scheduled">Scheduled</option>
                  <option value="not-called">Not Started</option>
                </select>
              </FormField>
            </FormFieldGrid>

            <FormField label="Lead Status">
              <select
                value={status}
                onChange={(e) => setStatus(e.target.value as Lead["updateStatus"])}
                className={SELECT_STYLE}
                aria-label="Lead Status"
              >
                <option value="interested">Interested</option>
                <option value="awaiting-reply">Awaiting Reply</option>
                <option value="declined">Declined</option>
                <option value="call-back-req">Call-back Req.</option>
              </select>
            </FormField>
          </FormSection>

          <div className="border-t border-border-subtle" />

          <FormSection title="Deal">
            <FormField label="Amount">
              <input
                type="number"
                min="0"
                inputMode="numeric"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                className={FIELD_STYLE}
                placeholder="Estimated deal value (₱)"
                aria-label="Amount"
              />
            </FormField>
          </FormSection>

          {canDelete ? (
            <>
              <div className="border-t border-border-subtle" />
              <DangerConfirmRegion onDelete={() => setConfirmOpen(true)} />
            </>
          ) : null}
        </div>
      </div>

      <SheetFooter className="flex-col gap-2 border-t border-border-subtle px-5 py-4">
        {error ? <p className="w-full text-sm text-destructive">{error}</p> : null}
        <div className="flex w-full gap-3">
          <Button
            variant="outline"
            size="default"
            className="flex-1"
            onClick={onClose}
            disabled={updateLead.isPending}
          >
            Cancel
          </Button>
          <Button
            variant="default"
            size="default"
            className="flex-1"
            onClick={handleSave}
            disabled={updateLead.isPending}
          >
            {updateLead.isPending ? "Saving…" : "Save Changes"}
          </Button>
        </div>
      </SheetFooter>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title="Delete lead?"
        description={`"${lead.business}" will be deleted. This can't be undone.`}
        confirmLabel="Delete Lead"
        onConfirm={handleDelete}
        loading={deleteLead.isPending}
        destructive
      />
    </>
  );
}
