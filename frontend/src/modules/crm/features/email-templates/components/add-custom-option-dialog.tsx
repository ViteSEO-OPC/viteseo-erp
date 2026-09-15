"use client";

import { useState } from "react";
import { Button } from "@/modules/crm/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/modules/crm/components/ui/dialog";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { apiFieldErrors } from "@/modules/crm/features/email-templates/hooks/use-email-templates-admin";
import { useCreateTemplateOption } from "@/modules/crm/features/email-templates/hooks/use-email-template-options";
import { OPTION_MAX_LENGTH, optionValueProblem } from "@/modules/crm/features/email-templates/lib/template-options";
import type { TemplateOptionType } from "@/modules/crm/features/email-templates/types/email-template-types";

type Props = {
  type: TemplateOptionType;
  /** Every value already in that list, built-in and custom, for the check before sending. */
  existing: readonly string[];
  onClose: () => void;
  /** The value as the server stored it, so the caller can select it. */
  onCreated: (value: string) => void;
};

/**
 * Add a custom purpose or tone for the organization. It is saved to the organization's catalog — reusable by every
 * template — and handed back so the dropdown that opened this can select it. The server has the final word on
 * duplicates and on values reserved for built-in purposes, tones and automation keys; its message is shown here.
 */
export function AddCustomOptionDialog({ type, existing, onClose, onCreated }: Props) {
  const create = useCreateTemplateOption();
  const [value, setValue] = useState("");
  const [error, setError] = useState<string | null>(null);

  function submit(event: React.FormEvent<HTMLFormElement>) {
    // The dialog renders in a portal, but React still bubbles submit to the template editor's form around it.
    event.preventDefault();
    event.stopPropagation();

    const problem = optionValueProblem(value, type, existing);
    if (problem) {
      setError(problem);
      return;
    }

    create.mutate(
      { type, value },
      {
        onSuccess: (option) => {
          onCreated(option.value);
          onClose();
        },
        onError: (err) => {
          const fields = apiFieldErrors(err);
          setError(fields.value ?? fields.type ?? getApiErrorMessage(err, `Could not add the custom ${type}.`));
        },
      },
    );
  }

  const label = type === "purpose" ? "purpose" : "tone";

  return (
    <Dialog open onOpenChange={(open) => !open && !create.isPending && onClose()}>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <DialogHeader>
            <DialogTitle>Add custom {label}</DialogTitle>
            <DialogDescription>
              Saved for your organization and available to every template. It can be deleted while no template uses
              it, but not renamed.
            </DialogDescription>
          </DialogHeader>

          <FormField label={`Custom ${label}`} required error={error ?? undefined}>
            <input
              autoFocus
              value={value}
              onChange={(event) => {
                setValue(event.target.value);
                setError(null);
              }}
              maxLength={OPTION_MAX_LENGTH}
              placeholder={type === "purpose" ? "e.g. Sales Pitch" : "e.g. Upbeat"}
              aria-label={`Custom ${label} name`}
              aria-invalid={error ? true : undefined}
              disabled={create.isPending}
              className={FIELD}
            />
          </FormField>

          <DialogFooter className="flex-row justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={create.isPending}>
              Cancel
            </Button>
            <Button type="submit" disabled={create.isPending}>
              {create.isPending ? "Adding…" : `Add ${label}`}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
