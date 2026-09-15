"use client";

import { useMemo, useRef, useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Lock, Palette } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { Switch } from "@/modules/crm/components/ui/switch";
import { Textarea } from "@/modules/crm/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/modules/crm/components/ui/select";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/modules/crm/components/ui/sheet";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { cn } from "@/modules/crm/lib/utils";
import { EmailTemplatePreview } from "@/modules/crm/features/email-templates/components/email-template-preview";
import { AddCustomOptionDialog } from "@/modules/crm/features/email-templates/components/add-custom-option-dialog";
import {
  CustomPurposeItems,
  CustomToneItems,
  systemTones,
} from "@/modules/crm/features/email-templates/components/template-option-items";
import { ADD_CUSTOM_OPTION } from "@/modules/crm/features/email-templates/lib/template-options";
import {
  apiFieldErrors,
  useCreateEmailTemplate,
  useUpdateEmailTemplate,
} from "@/modules/crm/features/email-templates/hooks/use-email-templates-admin";
import {
  BODY_MAX,
  KIND_LABELS,
  SUBJECT_MAX,
  insertAt,
  placeholderProblem,
} from "@/modules/crm/features/email-templates/lib/email-template-text";
import type {
  EmailTemplate,
  EmailTemplateCatalog,
  TemplateOptionType,
  TemplateKind,
  TemplateToken,
} from "@/modules/crm/features/email-templates/types/email-template-types";

export type EditorTab = "edit" | "preview";

const FORM_FIELDS = ["purpose", "tone", "subject", "body", "is_active"] as const;
type EditableField = (typeof FORM_FIELDS)[number];

const KIND_GROUP_LABELS: Record<TemplateKind, string> = {
  composer: "Outreach composer",
  automation: "Automation (n8n)",
};

/** Client-side mirror of StoreEmailTemplateRequest, so most mistakes are caught before a round trip. */
function buildSchema(tokens: TemplateToken[]) {
  return z
    .object({
      purpose: z.string().min(1, "Choose a purpose."),
      tone: z.string().min(1, "Choose a tone."),
      subject: z
        .string()
        .refine((v) => v.trim().length > 0, "A subject is required.")
        .refine((v) => v.length <= SUBJECT_MAX, `Keep the subject under ${SUBJECT_MAX} characters.`),
      body: z
        .string()
        .refine((v) => v.trim().length > 0, "A body is required.")
        .refine((v) => v.length <= BODY_MAX, `Keep the body under ${BODY_MAX.toLocaleString()} characters.`),
      is_active: z.boolean(),
    })
    .superRefine((values, ctx) => {
      for (const field of ["subject", "body"] as const) {
        const problem = placeholderProblem(values[field], tokens);
        if (problem) ctx.addIssue({ code: "custom", path: [field], message: problem });
      }
    });
}

type FormValues = z.infer<ReturnType<typeof buildSchema>>;

type Props = {
  /** null opens the sheet on a new template. */
  template: EmailTemplate | null;
  initialTab: EditorTab;
  catalog: EmailTemplateCatalog;
  onClose: () => void;
  /**
   * Opens the visual designer with the current values, the body becoming its first text block. Nothing is
   * saved until the designer saves, so closing the designer leaves the template as it was.
   */
  onSwitchToDesigner?: (values: FormValues) => void;
};

/**
 * Create / edit one template, with a live sample-data preview.
 *
 * Purpose and tone are chosen from the server's catalog, never typed. Editing keeps a template inside
 * its kind — a composer template cannot become an automation one — and a template the automation
 * endpoint resolves has its purpose locked; the server enforces both, this only makes them visible.
 */
export function EmailTemplateEditor({ template, initialTab, catalog, onClose, onSwitchToDesigner }: Props) {
  const create = useCreateEmailTemplate();
  const update = useUpdateEmailTemplate();
  const pending = create.isPending || update.isPending;
  // Which "+ Add custom …" dialog is open, if any.
  const [addingOption, setAddingOption] = useState<TemplateOptionType | null>(null);
  const [tab, setTab] = useState<EditorTab>(initialTab);

  const automationBound = template !== null && catalog.automationTemplateIds.includes(template.id);
  const purposeGroups = (["composer", "automation"] as const)
    .filter((kind) => template === null || template.kind === kind)
    .map((kind) => ({ kind, items: catalog.purposes.filter((purpose) => purpose.kind === kind && !purpose.custom) }))
    .filter((group) => group.items.length > 0);

  const schema = useMemo(() => buildSchema(catalog.tokens), [catalog.tokens]);
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    mode: "onTouched",
    defaultValues: {
      purpose: template?.purpose ?? "",
      tone: template?.tone ?? catalog.tones[0] ?? "",
      subject: template?.subject ?? "",
      body: template?.body ?? "",
      is_active: template?.isActive ?? true,
    },
  });
  const { errors } = form.formState;
  const subject = useWatch({ control: form.control, name: "subject" });
  const body = useWatch({ control: form.control, name: "body" });

  // Placeholder chips insert at the caret of whichever text field was used last.
  const subjectRef = useRef<HTMLInputElement | null>(null);
  const bodyRef = useRef<HTMLTextAreaElement | null>(null);
  const lastFocused = useRef<"subject" | "body">("body");
  const subjectField = form.register("subject");
  const bodyField = form.register("body");

  function insertToken(key: string) {
    const field = lastFocused.current;
    const element = field === "subject" ? subjectRef.current : bodyRef.current;
    const current = form.getValues(field);
    const next = insertAt(
      current,
      element?.selectionStart ?? current.length,
      element?.selectionEnd ?? current.length,
      `{{${key}}}`,
    );

    form.setValue(field, next.value, { shouldDirty: true, shouldValidate: form.formState.isSubmitted });
    requestAnimationFrame(() => {
      element?.focus();
      element?.setSelectionRange(next.caret, next.caret);
    });
  }

  const submit = form.handleSubmit(
    (values) => {
      const onError = (err: unknown) => {
        // The server's own sentence for each rejected field, on that field. The summary toast is
        // raised by the mutation itself.
        for (const [field, message] of Object.entries(apiFieldErrors(err))) {
          if ((FORM_FIELDS as readonly string[]).includes(field)) {
            form.setError(field as EditableField, { type: "server", message });
          }
        }
        setTab("edit");
      };

      if (template === null) {
        create.mutate(values, { onSuccess: onClose, onError });
      } else {
        update.mutate({ id: template.id, ...values }, { onSuccess: onClose, onError });
      }
    },
    () => setTab("edit"),
  );

  return (
    <Sheet
      open
      onOpenChange={(open) => {
        if (!open && !pending) onClose();
      }}
    >
      <SheetContent side="right" className="w-full gap-0 p-0 data-[side=right]:sm:max-w-2xl">
        <SheetHeader className="border-b border-border px-6 py-4">
          <SheetTitle>{template === null ? "New email template" : "Edit email template"}</SheetTitle>
          <SheetDescription>
            {template === null
              ? "A reusable subject and body. Placeholders are filled in for each lead."
              : `${KIND_LABELS[template.kind]} · ${template.purpose} · ${template.tone}`}
          </SheetDescription>
        </SheetHeader>

        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col" noValidate>
          <div className="border-b border-border px-6 py-2">
            <div role="tablist" aria-label="Template editor" className="inline-flex items-center gap-1 rounded-lg border border-border bg-muted p-1">
              {(["edit", "preview"] as const).map((id) => (
                <button
                  key={id}
                  type="button"
                  role="tab"
                  aria-selected={tab === id}
                  onClick={() => setTab(id)}
                  className={cn(
                    "rounded-md px-3 py-1 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                    tab === id ? "bg-card text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground",
                  )}
                >
                  {id === "edit" ? "Edit" : "Preview"}
                </button>
              ))}
            </div>
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
            {tab === "preview" ? (
              <EmailTemplatePreview subject={subject} body={body} tokens={catalog.tokens} />
            ) : (
              <div className="flex flex-col gap-5">
                {automationBound ? (
                  <p className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                    <Lock className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                    Automated emails use this template, so its purpose is locked. Its wording can still be
                    edited. It cannot be deactivated or deleted while it is the only active template for
                    its purpose.
                  </p>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                  <FormField label="Purpose" required error={errors.purpose?.message}>
                    <Controller
                      control={form.control}
                      name="purpose"
                      render={({ field }) => (
                        <Select
                          value={field.value}
                          onValueChange={(value) => {
                            if (value === ADD_CUSTOM_OPTION) {
                              setAddingOption("purpose");
                              return;
                            }
                            field.onChange(typeof value === "string" ? value : "");
                          }}
                          disabled={automationBound || pending}
                        >
                          <SelectTrigger className="w-full" aria-label="Purpose">
                            <SelectValue placeholder="Choose a purpose">{field.value || undefined}</SelectValue>
                          </SelectTrigger>
                          <SelectContent>
                            {purposeGroups.map((group) => (
                              <SelectGroup key={group.kind}>
                                <SelectLabel>{KIND_GROUP_LABELS[group.kind]}</SelectLabel>
                                {group.items.map((purpose) => (
                                  <SelectItem key={purpose.value} value={purpose.value}>
                                    {purpose.value}
                                  </SelectItem>
                                ))}
                              </SelectGroup>
                            ))}
                            {template === null || template.kind === "composer" ? <CustomPurposeItems catalog={catalog} /> : null}
                          </SelectContent>
                        </Select>
                      )}
                    />
                  </FormField>

                  <FormField label="Tone" required error={errors.tone?.message}>
                    <Controller
                      control={form.control}
                      name="tone"
                      render={({ field }) => (
                        <Select
                          value={field.value}
                          onValueChange={(value) => {
                            if (value === ADD_CUSTOM_OPTION) {
                              setAddingOption("tone");
                              return;
                            }
                            field.onChange(typeof value === "string" ? value : "");
                          }}
                          disabled={pending}
                        >
                          <SelectTrigger className="w-full" aria-label="Tone">
                            <SelectValue placeholder="Choose a tone">{field.value || undefined}</SelectValue>
                          </SelectTrigger>
                          <SelectContent>
                            {systemTones(catalog).map((tone) => (
                              <SelectItem key={tone} value={tone}>
                                {tone}
                              </SelectItem>
                            ))}
                            <CustomToneItems catalog={catalog} />
                          </SelectContent>
                        </Select>
                      )}
                    />
                  </FormField>
                </div>

                <FormField label="Status" error={errors.is_active?.message}>
                  <Controller
                    control={form.control}
                    name="is_active"
                    render={({ field }) => (
                      <div className="flex min-h-9 items-center gap-2 text-sm text-foreground">
                        <Switch
                          checked={field.value}
                          onCheckedChange={(checked) => field.onChange(checked)}
                          disabled={pending}
                          aria-label="Active"
                        />
                        <span>
                          {field.value ? "Active — offered wherever this template is used" : "Inactive — kept, but not offered"}
                        </span>
                      </div>
                    )}
                  />
                </FormField>

                <FormField label="Subject" required error={errors.subject?.message}>
                  <input
                    {...subjectField}
                    ref={(element) => {
                      subjectField.ref(element);
                      subjectRef.current = element;
                    }}
                    onFocus={() => {
                      lastFocused.current = "subject";
                    }}
                    className={FIELD}
                    maxLength={SUBJECT_MAX}
                    placeholder="e.g. Following up, {{first_name}}"
                    disabled={pending}
                  />
                </FormField>

                <FormField label="Body" required error={errors.body?.message}>
                  <Textarea
                    {...bodyField}
                    ref={(element) => {
                      bodyField.ref(element);
                      bodyRef.current = element;
                    }}
                    onFocus={() => {
                      lastFocused.current = "body";
                    }}
                    className="min-h-[260px] leading-relaxed"
                    maxLength={BODY_MAX}
                    placeholder={"Hi {{first_name}},\n\n…"}
                    disabled={pending}
                  />
                </FormField>

                <div className="flex flex-col gap-2">
                  <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                    Insert placeholder
                  </p>
                  <div className="flex flex-wrap gap-1.5">
                    {catalog.tokens.map((token) => (
                      <button
                        key={token.key}
                        type="button"
                        // Keeps the caret in the field while the chip is pressed.
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => insertToken(token.key)}
                        disabled={pending}
                        title={`${token.label} — e.g. ${token.sample}`}
                        className="rounded-md border border-border bg-muted px-2 py-1 font-mono text-xs text-foreground transition-colors hover:bg-teal-400/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                      >
                        {`{{${token.key}}}`}
                      </button>
                    ))}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Filled in with each lead&apos;s details when the email is written. Only these placeholders
                    are allowed.
                  </p>
                </div>
              </div>
            )}
          </div>

          <SheetFooter className="flex-row justify-end gap-2 border-t border-border px-6 py-4">
            {onSwitchToDesigner ? (
              <Button
                type="button"
                variant="ghost"
                className="mr-auto"
                onClick={() => onSwitchToDesigner(form.getValues())}
                disabled={pending}
              >
                <Palette aria-hidden />
                Switch to visual designer
              </Button>
            ) : null}
            <Button type="button" variant="outline" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" disabled={pending}>
              {pending ? "Saving…" : template === null ? "Create template" : "Save changes"}
            </Button>
          </SheetFooter>
        </form>
        {addingOption ? (
          <AddCustomOptionDialog
            type={addingOption}
            existing={addingOption === "purpose" ? catalog.purposes.map((purpose) => purpose.value) : catalog.tones}
            onClose={() => setAddingOption(null)}
            onCreated={(value) => form.setValue(addingOption, value, { shouldDirty: true, shouldValidate: true })}
          />
        ) : null}
      </SheetContent>
    </Sheet>
  );
}
