"use client";

import { useMemo, useState } from "react";
import { Controller } from "react-hook-form";
import { SelectShell } from "@/modules/crm/components/shared/select-shell/select-shell";
import { X } from "lucide-react";
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
import { useValidatedForm } from "@/core/forms/useValidatedForm";
import { useCreateLead } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";
import { NicheSelect } from "@/modules/crm/components/shared/niche-select/niche-select";
import {
  createLeadSchema,
  SERVER_FIELD_TO_FORM,
  STEP_COUNT,
  STEP_FIELDS,
  stepForField,
  type CreateLeadValues,
} from "@/modules/crm/features/leads/lib/create-lead-schema";
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

const EMPTY_VALUES: CreateLeadValues = {
  businessName: "",
  niche: "",
  website: "",
  address: "",
  ownerName: "",
  email: "",
  phone: "",
  acquisitionChannel: "",
  // Mirrors the leads.priority DB default, so an untouched wizard persists exactly what the backend
  // applied before.
  priority: "medium",
  amount: "",
  assignedTo: "",
  notes: "",
};

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

/**
 * Create Lead Wizard.
 *
 * Validation is schema-driven (see create-lead-schema.ts) rather than a single inline boolean on the
 * final step. Next validates only the fields belonging to the current step, so a required field can
 * no longer be skipped by clicking through, and every field renders its own message instead of one
 * sentence in the footer.
 *
 * Form state lives in react-hook-form, not in a dozen useState hooks. That is also what fixes the
 * Back navigation: steps are still conditionally rendered, but RHF keeps values for unmounted fields
 * (`shouldUnregister` defaults to false), so nothing is tied to the lifetime of a DOM node.
 */
export function CreateLeadWizard({ open, onOpenChange }: Props) {
  const [step, setStep] = useState(1);
  const [formError, setFormError] = useState("");
  const createLead = useCreateLead();

  // Admin/SA must pick the owning BD; BD creators auto-self on the backend (no selector).
  const { hasMinRole } = usePermissions();
  const isManager = hasMinRole("crm_admin");
  // Only Admin/SA need the BD list (BDs lack users.view — avoid a 403).
  const { bdUsers } = useBdUsers(isManager);

  const schema = useMemo(() => createLeadSchema(isManager), [isManager]);
  const {
    register,
    control,
    handleSubmit,
    trigger,
    reset,
    setError,
    formState: { errors },
  } = useValidatedForm(schema, {
    defaultValues: EMPTY_VALUES,
    // Validate a field once the user has interacted with it, then keep it live — so a message
    // appears where the problem is rather than only after pressing Next.
    mode: "onTouched",
  });

  const handleClose = () => {
    setStep(1);
    setFormError("");
    reset(EMPTY_VALUES);
    onOpenChange(false);
  };

  const isLastStep = step >= STEP_COUNT;

  const handleBack = () => {
    setFormError("");
    setStep((s) => Math.max(s - 1, 1));
  };

  /** Advance only if the CURRENT step is valid; invalid fields render their own messages. */
  const handleNext = async () => {
    setFormError("");
    const valid = await trigger(STEP_FIELDS[step - 1], { shouldFocus: true });
    if (valid) setStep((s) => Math.min(s + 1, STEP_COUNT));
  };

  /**
   * Map a 422 back onto the form. Laravel answers `{ message, errors: { field: [msg] } }`; each key
   * is attached to its field and the wizard jumps to the EARLIEST step that owns one, so the user
   * lands on the input that is actually wrong. Anything unrecognised falls back to the footer
   * message rather than being swallowed.
   */
  const applyServerErrors = (err: unknown): void => {
    const fallback = "Failed to create lead";
    if (!isAxiosError(err)) return setFormError(fallback);

    const data = err.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined;
    const fieldErrors = data?.errors ?? {};

    let earliestStep: number | null = null;
    let mapped = 0;

    for (const [serverField, messages] of Object.entries(fieldErrors)) {
      const formField = SERVER_FIELD_TO_FORM[serverField];
      const message = messages?.[0];
      if (!formField || !message) continue;
      setError(formField, { type: "server", message });
      mapped += 1;
      const owning = stepForField(formField);
      if (earliestStep === null || owning < earliestStep) earliestStep = owning;
    }

    if (earliestStep !== null) setStep(earliestStep);
    // Only show the banner when nothing could be attached to a field — otherwise it duplicates a
    // message the user can already see in place.
    setFormError(mapped > 0 ? "" : (data?.message ?? fallback));
  };

  const onSubmit = (values: CreateLeadValues) => {
    setFormError("");
    createLead.mutate(
      {
        business_name: values.businessName.trim(),
        contact_name: values.ownerName.trim(),
        priority: values.priority,
        ...(values.acquisitionChannel ? { acquisition_channel: values.acquisitionChannel } : {}),
        ...(values.email.trim() ? { email: values.email.trim() } : {}),
        ...(values.phone.trim() ? { phone: values.phone.trim() } : {}),
        ...(values.niche.trim() ? { industry: values.niche.trim() } : {}),
        ...(values.amount.trim() ? { amount: Number(values.amount) } : {}),
        ...(values.assignedTo ? { assigned_to: Number(values.assignedTo) } : {}),
        ...(values.website.trim() ? { website: values.website.trim() } : {}),
        ...(values.address.trim() ? { address: values.address.trim() } : {}),
        ...(values.notes.trim() ? { note: values.notes.trim() } : {}),
      },
      {
        onSuccess: handleClose,
        onError: applyServerErrors,
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
                <FormField label="Business Name" required error={errors.businessName?.message}>
                  <input
                    type="text"
                    placeholder="e.g. Global Tech Solutions"
                    className={FIELD}
                    aria-required="true"
                    {...register("businessName")}
                  />
                </FormField>
                {/* NOT required: `industry` is nullable in every server request class and no business
                    rule makes it mandatory. The asterisk this field used to carry was never enforced
                    on either side. */}
                <FormField label="Business Niche" error={errors.niche?.message}>
                  {/* The shared registry-backed selector — the same component the Edit Lead drawer
                      uses. `allowCreate` puts the inline "+ Add Business Niche…" row at the bottom.
                      It is a controlled component rather than an <input>, so it goes through
                      Controller instead of register(). */}
                  <Controller
                    control={control}
                    name="niche"
                    render={({ field }) => (
                      <NicheSelect
                        value={field.value}
                        onChange={field.onChange}
                        allowCreate
                        triggerClassName="h-10 w-full bg-background px-3.5 focus:border-teal-400 focus:ring-2 focus:ring-teal-400/30"
                      />
                    )}
                  />
                </FormField>
              </FormFieldGrid>

              <FormField label="Website URL" error={errors.website?.message}>
                <input
                  type="url"
                  placeholder="https://globaltech.com"
                  className={FIELD}
                  {...register("website")}
                />
              </FormField>

              <FormField label="Business Address" error={errors.address?.message}>
                <input
                  type="text"
                  placeholder="Enter full business address&hellip;"
                  className={FIELD}
                  {...register("address")}
                />
              </FormField>
            </div>
          )}

          {step === 2 && (
            <div className="flex flex-col gap-5">
              <SectionHeader title="Contact Information" className="mb-0" />

              {/* Required to match `contact_name` in StoreLeadRequest (and NOT NULL in crm_leads).
                  Previously unmarked and ungated, which turned a missing value into a 422 raised on
                  step 3 about a step 2 field. "Position / Title" used to sit beside this one; it was
                  removed because the backend has no column, no Fillable entry and no rule for it, so
                  anything typed there could never be saved. */}
              <FormField label="Owner Full Name" required error={errors.ownerName?.message}>
                <input
                  type="text"
                  placeholder="Alex Thompson"
                  className={FIELD}
                  aria-required="true"
                  {...register("ownerName")}
                />
              </FormField>

              <FormFieldGrid>
                <FormField label="Email Address" error={errors.email?.message}>
                  <input
                    type="email"
                    placeholder="alex@globaltech.com"
                    className={FIELD}
                    {...register("email")}
                  />
                </FormField>
                <FormField label="Phone Number" error={errors.phone?.message}>
                  <input
                    type="tel"
                    placeholder="+1 (555) 000-0000"
                    className={FIELD}
                    {...register("phone")}
                  />
                </FormField>
              </FormFieldGrid>
            </div>
          )}

          {step === 3 && (
            <div className="flex flex-col gap-5">
              <SectionHeader title="Lead Source" className="mb-0" />

              <FormFieldGrid>
                <FormField label="Source" error={errors.acquisitionChannel?.message}>
                  <SelectShell>
                    <select className={SELECT} {...register("acquisitionChannel")}>
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
                <FormField label="Priority" error={errors.priority?.message}>
                  <SelectShell>
                    <select className={SELECT} {...register("priority")}>
                      <option value="high">High</option>
                      <option value="medium">Medium</option>
                      <option value="low">Low</option>
                    </select>
                  </SelectShell>
                </FormField>
              </FormFieldGrid>

              {/* Estimated Deal Value (prospect-stage) — persisted to leads.amount, NOT revenue. */}
              <FormField label="Amount" error={errors.amount?.message}>
                <input
                  type="number"
                  min="0"
                  inputMode="numeric"
                  placeholder="Estimated deal value (₱)"
                  className={FIELD}
                  {...register("amount")}
                />
              </FormField>

              {isManager ? (
                <FormField
                  label="Assign to Business Development Manager"
                  required
                  error={errors.assignedTo?.message}
                >
                  <SelectShell>
                    <select className={SELECT} aria-required="true" {...register("assignedTo")}>
                      <option value="">Select a BD&hellip;</option>
                      {bdUsers.map((u) => (
                        <option key={u.id} value={u.id}>{u.name}</option>
                      ))}
                    </select>
                  </SelectShell>
                </FormField>
              ) : null}

              <FormField label="Notes" optional error={errors.notes?.message}>
                <textarea
                  placeholder="Anything useful about this lead&hellip;"
                  className={TEXTAREA}
                  maxLength={5000}
                  {...register("notes")}
                />
              </FormField>
            </div>
          )}
        </div>

        {/* Footer */}
        <DialogFooter className="mx-0 mb-0 flex-col gap-2 border-t-0 bg-transparent px-6 pb-6 pt-2">
          {formError ? (
            <p className="w-full text-right text-sm text-red-500">{formError}</p>
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
            {/* Not disabled on validity: a disabled button explains nothing. Pressing it runs the
                step's validation and surfaces the messages on the offending fields. */}
            <Button
              variant="default"
              size="sm"
              onClick={isLastStep ? handleSubmit(onSubmit) : handleNext}
              disabled={createLead.isPending}
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
