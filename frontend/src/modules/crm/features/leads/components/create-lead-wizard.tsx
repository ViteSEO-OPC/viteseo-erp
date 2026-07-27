"use client";

import { useState, type ReactNode } from "react";
import { ChevronDown, X } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { FormField, FormFieldGrid } from "@/modules/crm/components/forms/form-field/form-field";
import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import { WizardStepper } from "@/modules/crm/components/shared/wizard-stepper/wizard-stepper";
import { useCreateLead } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";
import { NicheSelect } from "@/modules/crm/components/shared/niche-select/niche-select";
import type { PriorityLevel } from "@/modules/crm/features/leads/types/lead-types";
import { isAxiosError } from "axios";

const FIELD =
  "h-10 w-full rounded-lg border border-border bg-background px-3.5 text-sm text-foreground placeholder:text-muted-foreground transition-colors focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";

const SELECT =
  "h-10 w-full appearance-none rounded-lg border border-border bg-background pl-3.5 pr-9 text-sm text-foreground transition-colors focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";

const TEXTAREA =
  "min-h-[96px] w-full resize-none rounded-lg border border-border bg-background px-3.5 py-2.5 text-sm text-foreground placeholder:text-muted-foreground transition-colors focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";

const STEPS = [
  { label: "Business Info" },
  { label: "Contact Details" },
  { label: "Lead Source" },
];

/** Wraps a native <select> to overlay a chevron (selects use appearance-none). */
function SelectShell({ children }: { children: ReactNode }) {
  return (
    <div className="relative">
      {children}
      <ChevronDown
        className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
        aria-hidden
      />
    </div>
  );
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function CreateLeadWizard({ open, onOpenChange }: Props) {
  const [step, setStep] = useState(1);
  const [businessName, setBusinessName] = useState("");
  const [niche, setNiche] = useState("");
  const [ownerName, setOwnerName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  // Estimated Deal Value (prospect-stage) — persisted to leads.amount; NOT revenue.
  const [amount, setAmount] = useState("");
  // Manual priority categorization. Default "medium" mirrors the leads.priority DB default, so an
  // untouched wizard persists exactly what the backend applied before (no aggregate change); an
  // explicit pick is now captured instead of silently dropped.
  const [priority, setPriority] = useState<PriorityLevel>("medium");
  // Lead Acquisition Channel (business "Source"). Bound to leads.acquisition_channel — NOT
  // leads.source (discovery provenance). Empty = unset → omitted from the payload (nullable).
  const [acquisitionChannel, setAcquisitionChannel] = useState("");
  // Website Domain → leads.website. The column and the StoreLeadRequest rule already existed; the
  // input was simply never bound, so anything typed here used to be discarded.
  const [website, setWebsite] = useState("");
  // Optional first internal note. Sent as `note` and persisted as a crm_notes row by the backend —
  // NOT a column on the lead — so it shows up in Lead Details → Internal Notes like any other note.
  const [notes, setNotes] = useState("");
  const [error, setError] = useState("");
  const createLead = useCreateLead();
  // Admin/SA must pick the owning BD; BD creators auto-self on the backend (no selector).
  const { hasMinRole } = usePermissions();
  const isManager = hasMinRole("crm_admin");
  // Only Admin/SA need the BD list (BDs lack users.view — avoid a 403).
  const { bdUsers } = useBdUsers(isManager);
  const [assignedTo, setAssignedTo] = useState("");

  const handleClose = () => {
    setStep(1);
    setBusinessName("");
    setNiche("");
    setOwnerName("");
    setEmail("");
    setPhone("");
    setAmount("");
    setPriority("medium");
    setAcquisitionChannel("");
    setAssignedTo("");
    setWebsite("");
    setNotes("");
    setError("");
    onOpenChange(false);
  };

  const isLastStep = step >= STEPS.length;

  const handleBack = () => {
    setError("");
    setStep((s) => Math.max(s - 1, 1));
  };

  const handlePrimary = () => {
    setError("");
    if (!isLastStep) {
      setStep((s) => Math.min(s + 1, STEPS.length));
      return;
    }
    // Final step → create. `website` and `notes` are now bound and sent; the remaining unbound inputs
    // (address/position/source) are still outside the create contract — `position` has no column at
    // all, and `address`/`source` have columns but no StoreLeadRequest rule — so they stay out until
    // that is decided.
    createLead.mutate(
      {
        business_name: businessName.trim(),
        contact_name: ownerName.trim(),
        priority,
        ...(acquisitionChannel ? { acquisition_channel: acquisitionChannel } : {}),
        ...(email.trim() ? { email: email.trim() } : {}),
        ...(phone.trim() ? { phone: phone.trim() } : {}),
        ...(niche.trim() ? { industry: niche.trim() } : {}),
        ...(amount.trim() ? { amount: Number(amount) } : {}),
        ...(assignedTo ? { assigned_to: Number(assignedTo) } : {}),
        ...(website.trim() ? { website: website.trim() } : {}),
        ...(notes.trim() ? { note: notes.trim() } : {}),
      },
      {
        onSuccess: handleClose,
        onError: (err) => {
          const message = isAxiosError(err)
            ? (err.response?.data?.message ?? "Failed to create lead")
            : "Failed to create lead";
          setError(message);
        },
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={(val) => { if (!val) handleClose(); }}>
      <DialogContent
        showCloseButton={false}
        className="flex max-h-[90vh] w-full flex-col gap-0 p-0 sm:max-w-[500px]"
      >
        {/* Header */}
        <DialogHeader className="px-6 pt-6 pb-0">
          <div className="flex items-start justify-between gap-4">
            <div className="flex flex-col gap-1">
              <DialogTitle className="text-xl font-bold text-card-foreground">
                Create New Lead
              </DialogTitle>
              <DialogDescription>
                Add a lead to your pipeline in three quick steps — fields marked{" "}
                <span className="text-red-500">*</span> are required.
              </DialogDescription>
            </div>
            <button
              type="button"
              onClick={handleClose}
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label="Close"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        {/* Stepper */}
        <div className="px-6 pt-5 pb-2">
          <WizardStepper steps={STEPS} current={step} />
        </div>

        {/* Step body */}
        <div className="flex-1 overflow-y-auto px-6 py-4">
          {step === 1 && (
            <div className="flex flex-col gap-5">
              <SectionHeader title="Business Information" className="mb-0" />

              <FormFieldGrid>
                <FormField label="Business Name" required>
                  <input
                    type="text"
                    placeholder="e.g. Global Tech Solutions"
                    className={FIELD}
                    aria-required="true"
                    value={businessName}
                    onChange={(e) => setBusinessName(e.target.value)}
                  />
                </FormField>
                <FormField label="Business Niche" required>
                  {/* The shared registry-backed selector — the same component the Edit Lead drawer
                      uses, replacing five hardcoded options. `allowCreate` puts the inline
                      "+ Add Business Niche…" row at the bottom of the list. */}
                  <NicheSelect
                    value={niche}
                    onChange={setNiche}
                    allowCreate
                    // Matches the SELECT constant on this wizard's sibling fields (h-10 /
                    // bg-background / px-3.5) and their teal focus: ring. No clear option — the
                    // field is marked required here.
                    triggerClassName="h-10 w-full bg-background px-3.5 focus:border-teal-400 focus:ring-2 focus:ring-teal-400/30"
                  />
                </FormField>
              </FormFieldGrid>

              <FormField label="Website URL">
                <input
                  type="url"
                  placeholder="https://globaltech.com"
                  className={FIELD}
                  value={website}
                  onChange={(e) => setWebsite(e.target.value)}
                />
              </FormField>

              <FormField label="Business Address">
                <input
                  type="text"
                  placeholder="Enter full business address&hellip;"
                  className={FIELD}
                />
              </FormField>
            </div>
          )}

          {step === 2 && (
            <div className="flex flex-col gap-5">
              <SectionHeader title="Contact Information" className="mb-0" />

              <FormFieldGrid>
                <FormField label="Owner Full Name">
                  <input
                    type="text"
                    placeholder="Alex Thompson"
                    className={FIELD}
                    value={ownerName}
                    onChange={(e) => setOwnerName(e.target.value)}
                  />
                </FormField>
                <FormField label="Position / Title">
                  <input
                    type="text"
                    placeholder="Managing Director"
                    className={FIELD}
                  />
                </FormField>
              </FormFieldGrid>

              <FormFieldGrid>
                <FormField label="Email Address">
                  <input
                    type="email"
                    placeholder="alex@globaltech.com"
                    className={FIELD}
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                  />
                </FormField>
                <FormField label="Phone Number">
                  <input
                    type="tel"
                    placeholder="+1 (555) 000-0000"
                    className={FIELD}
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                  />
                </FormField>
              </FormFieldGrid>
            </div>
          )}

          {step === 3 && (
            <div className="flex flex-col gap-5">
              <SectionHeader title="Lead Source" className="mb-0" />

              <FormFieldGrid>
                <FormField label="Source">
                  <SelectShell>
                    <select
                      className={SELECT}
                      value={acquisitionChannel}
                      onChange={(e) => setAcquisitionChannel(e.target.value)}
                    >
                      <option value="">Select channel&hellip;</option>
                      <option>Referral</option>
                      <option>Facebook</option>
                      <option>Website</option>
                      <option>Cold Outreach</option>
                      <option>LinkedIn</option>
                      <option>Walk-in</option>
                      <option>Event</option>
                      <option>Partner</option>
                      <option>Email Campaign</option>
                      <option>Other</option>
                    </select>
                  </SelectShell>
                </FormField>
                <FormField label="Priority">
                  <SelectShell>
                    <select
                      className={SELECT}
                      value={priority}
                      onChange={(e) => setPriority(e.target.value as PriorityLevel)}
                    >
                      <option value="high">High</option>
                      <option value="medium">Medium</option>
                      <option value="low">Low</option>
                    </select>
                  </SelectShell>
                </FormField>
              </FormFieldGrid>

              {/* Estimated Deal Value (prospect-stage) — persisted to leads.amount, NOT revenue. */}
              <FormField label="Amount">
                <input
                  type="number"
                  min="0"
                  inputMode="numeric"
                  placeholder="Estimated deal value (₱)"
                  className={FIELD}
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                />
              </FormField>

              {isManager ? (
                <FormField label="Assign to Business Development Manager" required>
                  <SelectShell>
                    <select
                      className={SELECT}
                      value={assignedTo}
                      onChange={(e) => setAssignedTo(e.target.value)}
                      aria-required="true"
                    >
                      <option value="" disabled>Select a BD&hellip;</option>
                      {bdUsers.map((u) => (
                        <option key={u.id} value={u.id}>{u.name}</option>
                      ))}
                    </select>
                  </SelectShell>
                </FormField>
              ) : null}

              <FormField label="Notes" optional>
                <textarea
                  placeholder="Anything useful about this lead&hellip;"
                  className={TEXTAREA}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  maxLength={5000}
                />
              </FormField>
            </div>
          )}
        </div>

        {/* Footer */}
        <DialogFooter className="mx-0 mb-0 flex-col gap-2 border-t-0 bg-transparent px-6 pb-6 pt-2">
          {error ? (
            <p className="w-full text-right text-sm text-red-500">{error}</p>
          ) : null}
          <div className="flex w-full items-center justify-end gap-2.5">
            {step > 1 ? (
              <Button
                variant="outline"
                size="sm"
                onClick={handleBack}
                disabled={createLead.isPending}
              >
                ← Back
              </Button>
            ) : null}
            <Button
              variant="ghost"
              size="sm"
              onClick={handleClose}
              disabled={createLead.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="default"
              size="sm"
              onClick={handlePrimary}
              disabled={createLead.isPending || (isLastStep && (businessName.trim() === "" || (isManager && !assignedTo)))}
            >
              {isLastStep
                ? createLead.isPending
                  ? "Creating…"
                  : "Create Lead"
                : "Next Step →"}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
