"use client";

import { useMemo, useRef, useState } from "react";
import Link from "next/link";
import { Controller, useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { ChevronRight, ExternalLink, Info, Lock } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { Switch } from "@/modules/crm/components/ui/switch";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/modules/crm/components/ui/select";
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from "@/modules/crm/components/ui/sheet";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { cn } from "@/modules/crm/lib/utils";
import { DesignBlockList } from "@/modules/crm/features/email-templates/components/designer/design-block-list";
import { AddCustomOptionDialog } from "@/modules/crm/features/email-templates/components/add-custom-option-dialog";
import {
  CustomPurposeItems,
  CustomToneItems,
  systemTones,
} from "@/modules/crm/features/email-templates/components/template-option-items";
import { ADD_CUSTOM_OPTION } from "@/modules/crm/features/email-templates/lib/template-options";
import {
  ColorControl,
  TextLineControl,
  TokenChips,
} from "@/modules/crm/features/email-templates/components/designer/design-controls";
import { DesignPreviewPanel, type PreviewDevice } from "@/modules/crm/features/email-templates/components/designer/design-preview-panel";
import { useEmailDesigns } from "@/modules/crm/features/email-templates/hooks/use-email-designs";
import { useEmailPreview } from "@/modules/crm/features/email-templates/hooks/use-email-preview";
import {
  apiFieldErrors,
  useCreateEmailTemplate,
  useUpdateEmailTemplate,
} from "@/modules/crm/features/email-templates/hooks/use-email-templates-admin";
import {
  ADDABLE_BLOCKS,
  BLOCK_LABELS,
  COLOR_STYLE_FIELDS,
  FONT_OPTIONS,
  MAX_CONTENT_BLOCKS,
  STYLE_LABELS,
  TEXT_LIMITS,
  documentProblems,
  documentRegions,
  effectiveStyle,
  mergeProblems,
  serializeDocument,
  withPreheader,
  withSignOff,
  withStyleOverride,
} from "@/modules/crm/features/email-templates/lib/email-design";
import {
  KIND_LABELS,
  SUBJECT_MAX,
  insertAt,
  placeholderProblem,
} from "@/modules/crm/features/email-templates/lib/email-template-text";
import type {
  DesignFont,
  DesignProblems,
  DesignStyleKey,
  DesignStyles,
  EmailDesign,
  TemplateContent,
  TemplateEditorSeed,
} from "@/modules/crm/features/email-templates/types/email-design-types";
import type {
  EmailTemplate,
  EmailTemplateCatalog,
  TemplateOptionType,
  TemplateKind,
  TemplateToken,
} from "@/modules/crm/features/email-templates/types/email-template-types";

const PREVIEW_ENDPOINT = "/settings/email-templates/preview";

const FORM_FIELDS = ["purpose", "tone", "subject", "is_active"] as const;
type EditableField = (typeof FORM_FIELDS)[number];

const KIND_GROUP_LABELS: Record<TemplateKind, string> = {
  composer: "Outreach composer",
  automation: "Automation (n8n)",
};

/** The Font select's value for "use the design's font". Never sent: an unset override is simply absent. */
const DESIGN_FONT = "design";

const NO_STYLES: DesignStyles = {};

/** The template's own fields, mirroring StoreEmailTemplateRequest. The content is checked by the server. */
function buildSchema(tokens: TemplateToken[]) {
  return z
    .object({
      purpose: z.string().min(1, "Choose a purpose."),
      tone: z.string().min(1, "Choose a tone."),
      subject: z
        .string()
        .refine((v) => v.trim().length > 0, "A subject is required.")
        .refine((v) => v.length <= SUBJECT_MAX, `Keep the subject under ${SUBJECT_MAX} characters.`),
      is_active: z.boolean(),
    })
    .superRefine((values, ctx) => {
      const problem = placeholderProblem(values.subject, tokens);
      if (problem) ctx.addIssue({ code: "custom", path: ["subject"], message: problem });
    });
}

type FormValues = z.infer<ReturnType<typeof buildSchema>>;

function designName(design: EmailDesign): string {
  return design.isDefault ? `${design.name} (default)` : design.name;
}

type Props = {
  /** null creates a new template; otherwise the template being designed (possibly converted from plain text). */
  template: EmailTemplate | null;
  seed: TemplateEditorSeed;
  catalog: EmailTemplateCatalog;
  onClose: () => void;
};

/**
 * Settings → Email Templates → the visual template editor.
 *
 * A designed template owns its message — the content blocks, the inbox preview text, and optionally its own
 * sign-off and style overrides — and is shown in a reusable email design, which owns the page style, header,
 * signature area and footer. On the right, the server composes the two and renders them with sample CRM data.
 *
 * Styles are inherited unless set: a block color falls back to the template's overrides, those to the design's
 * styles, then to the organization's email branding and the system default. An override is only written when
 * someone chooses one, so a design change still reaches every template that did not override it.
 *
 * What leaves the browser is the content JSON, and only the JSON, with the id of the design chosen. The server
 * validates it in that design, derives the plain-text version, and is the only thing that writes HTML.
 */
export function EmailTemplateContentEditor({ template, seed, catalog, onClose }: Props) {
  const create = useCreateEmailTemplate();
  const update = useUpdateEmailTemplate();
  const pending = create.isPending || update.isPending;
  // Which "+ Add custom …" dialog is open, if any.
  const [addingOption, setAddingOption] = useState<TemplateOptionType | null>(null);
  const designList = useEmailDesigns();

  const [content, setContent] = useState<TemplateContent>(seed.content);
  // The design chosen; null until one is, which means the template's current design or the default.
  const [designId, setDesignId] = useState<string | null>(seed.emailDesignId);
  const [designError, setDesignError] = useState<string | null>(null);
  const [stylesOpen, setStylesOpen] = useState(() => Object.keys(seed.content.style_overrides ?? {}).length > 0);
  const [device, setDevice] = useState<PreviewDevice>("desktop");
  // A save's refusals, against the content that was sent; cleared by the next edit.
  const [saveFailure, setSaveFailure] = useState<{ errors: Record<string, string>; content: TemplateContent } | null>(null);

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
      purpose: seed.purpose,
      tone: seed.tone || catalog.tones[0] || "",
      subject: seed.subject,
      is_active: seed.isActive,
    },
  });
  const { errors } = form.formState;
  const subject = useWatch({ control: form.control, name: "subject" });
  const purpose = useWatch({ control: form.control, name: "purpose" });
  const kind = template?.kind ?? catalog.purposes.find((option) => option.value === purpose)?.kind ?? null;

  const selectedId = designId ?? designList.defaultDesign?.id ?? null;
  const design = designList.designs.find((item) => item.id === selectedId) ?? null;
  const designStyles = design?.layout.styles ?? NO_STYLES;
  const overrides = content.style_overrides ?? NO_STYLES;
  const styleChain = [overrides, designStyles];
  const designChain = [designStyles];

  const previewExtra = useMemo(() => ({ subject, email_design_id: designId }), [subject, designId]);
  const preview = useEmailPreview({ endpoint: PREVIEW_ENDPOINT, field: "content", document: content, extra: previewExtra });

  const problems: DesignProblems = useMemo(
    () =>
      saveFailure === null
        ? preview.problems
        : mergeProblems(documentProblems(saveFailure.errors, "content", documentRegions(saveFailure.content)), preview.problems),
    [preview.problems, saveFailure],
  );

  function changeContent(next: (current: TemplateContent) => TemplateContent) {
    setContent(next);
    setSaveFailure(null);
  }

  function changeOverride(key: DesignStyleKey, value: string | undefined) {
    changeContent((current) => withStyleOverride(current, key, value));
  }

  const blockLabel = (id: string) => {
    const index = content.blocks.findIndex((block) => block.id === id);
    return index === -1 ? "A removed block" : `${BLOCK_LABELS[content.blocks[index].type]} (block ${index + 1})`;
  };

  const fieldLabel = (field: string) => {
    const [root, key = ""] = field.split(".");
    if (root === "preheader") return "Preview text";
    if (root === "sign_off") return "Sign-off";
    if (root === "style_overrides") {
      return key in STYLE_LABELS ? `Template style · ${STYLE_LABELS[key as DesignStyleKey]}` : "Template style";
    }
    return field;
  };

  const designFontLabel = FONT_OPTIONS.find((option) => option.value === effectiveStyle(designChain, "font"))?.label ?? "";
  const overrideCount = Object.keys(overrides).length;
  const ownSignOff = content.sign_off !== undefined;
  const signatureOff = design !== null && !design.layout.signature.enabled;

  // Placeholder chips insert into the subject at its caret.
  const subjectRef = useRef<HTMLInputElement | null>(null);
  const subjectField = form.register("subject");

  function insertSubjectToken(key: string) {
    const element = subjectRef.current;
    const current = form.getValues("subject");
    const next = insertAt(current, element?.selectionStart ?? current.length, element?.selectionEnd ?? current.length, `{{${key}}}`);
    form.setValue("subject", next.value, { shouldDirty: true, shouldValidate: form.formState.isSubmitted });
    requestAnimationFrame(() => {
      element?.focus();
      element?.setSelectionRange(next.caret, next.caret);
    });
  }

  const submit = form.handleSubmit((values) => {
    const sent = content;
    // No `body`: a designed template's plain-text version is derived from its content and design on the server.
    const payload = { ...values, content: serializeDocument(sent), email_design_id: designId };

    const onError = (err: unknown) => {
      const fieldErrors = apiFieldErrors(err);
      for (const [field, message] of Object.entries(fieldErrors)) {
        if ((FORM_FIELDS as readonly string[]).includes(field)) {
          form.setError(field as EditableField, { type: "server", message });
        }
      }
      setDesignError(fieldErrors.email_design_id ?? null);
      setSaveFailure({ errors: fieldErrors, content: sent });
    };

    if (template === null) {
      create.mutate(payload, { onSuccess: onClose, onError });
    } else {
      update.mutate({ id: template.id, ...payload }, { onSuccess: onClose, onError });
    }
  });

  return (
    <Sheet
      open
      onOpenChange={(open) => {
        if (!open && !pending) onClose();
      }}
    >
      <SheetContent side="right" className="w-full gap-0 p-0 data-[side=right]:sm:max-w-none">
        <SheetHeader className="border-b border-border px-6 py-4">
          <SheetTitle>{template === null ? "New designed email template" : "Design email template"}</SheetTitle>
          <SheetDescription>
            {template === null
              ? "Write the message from blocks and choose the design it is shown in. Placeholders are filled in for each lead."
              : `${KIND_LABELS[template.kind]} · ${template.purpose} · ${template.tone}`}
          </SheetDescription>
        </SheetHeader>

        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col" noValidate>
          <div className="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:overflow-hidden">
            <div className="flex flex-col gap-6 px-6 py-5 lg:min-h-0 lg:overflow-y-auto">
              {automationBound ? (
                <p className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                  <Lock className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                  Automated emails use this template, so its purpose is locked. It cannot be deactivated or deleted
                  while it is the only active template for its purpose.
                </p>
              ) : null}
              <p className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                {kind === "automation"
                  ? "Until HTML sending is switched on, automated emails from this template use its plain-text version, which the server generates from its content and design."
                  : "Until HTML sending is switched on, the Outreach composer offers this template's plain-text version, which the server generates from its content and design."}
              </p>

              <section aria-labelledby="template-details" className="flex flex-col gap-4">
                <h3 id="template-details" className="text-sm font-semibold text-foreground">
                  Template
                </h3>
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
                                {group.items.map((option) => (
                                  <SelectItem key={option.value} value={option.value}>
                                    {option.value}
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

                <FormField label="Subject" required error={errors.subject?.message}>
                  <div className="flex flex-col gap-1.5">
                    <input
                      {...subjectField}
                      ref={(element) => {
                        subjectField.ref(element);
                        subjectRef.current = element;
                      }}
                      className={FIELD}
                      maxLength={SUBJECT_MAX}
                      placeholder="e.g. A quick idea for {{business_name}}"
                      disabled={pending}
                    />
                    <TokenChips tokens={catalog.tokens} onInsert={insertSubjectToken} disabled={pending} />
                  </div>
                </FormField>

                <div className="flex flex-col gap-1">
                  <TextLineControl
                    label="Preview text"
                    value={content.preheader ?? ""}
                    onChange={(value) => changeContent((current) => withPreheader(current, value))}
                    tokens={catalog.tokens}
                    maxLength={TEXT_LIMITS.preheader}
                    placeholder="Shown beside the subject in the inbox"
                    disabled={pending}
                    error={problems.fields.preheader}
                  />
                  <p className="text-xs text-muted-foreground">
                    Optional. Most inboxes show it next to the subject; it is not visible inside the email.
                  </p>
                </div>

                <FormField label="Status" error={errors.is_active?.message}>
                  <Controller
                    control={form.control}
                    name="is_active"
                    render={({ field }) => (
                      <div className="flex min-h-9 items-center gap-2 text-sm text-foreground">
                        <Switch checked={field.value} onCheckedChange={(checked) => field.onChange(checked)} disabled={pending} aria-label="Active" />
                        <span>{field.value ? "Active — offered wherever this template is used" : "Inactive — kept, but not offered"}</span>
                      </div>
                    )}
                  />
                </FormField>
              </section>

              <section aria-labelledby="template-design" className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                  <h3 id="template-design" className="text-sm font-semibold text-foreground">
                    Design
                  </h3>
                  <p className="text-xs text-muted-foreground">
                    The page style, header, signature area and footer come from the design, so a change to the design
                    reaches every template that uses it.{" "}
                    <Link
                      href="/zerem/settings/email-designs"
                      target="_blank"
                      rel="noopener"
                      className="inline-flex items-center gap-0.5 font-medium text-foreground underline underline-offset-2"
                    >
                      Manage designs
                      <ExternalLink className="size-3" aria-hidden />
                    </Link>
                  </p>
                </div>

                <FormField label="Email design" required error={designError ?? undefined}>
                  <div className="flex flex-col gap-1.5">
                    <Select
                      value={selectedId ?? ""}
                      onValueChange={(value) => {
                        if (typeof value !== "string" || value === "") return;
                        setDesignId(value);
                        setDesignError(null);
                        setSaveFailure(null);
                      }}
                      disabled={pending || designList.designs.length === 0}
                    >
                      <SelectTrigger className="w-full sm:w-80" aria-label="Email design">
                        <SelectValue placeholder={designList.isLoading ? "Loading designs…" : "Choose a design"}>
                          {design ? designName(design) : undefined}
                        </SelectValue>
                      </SelectTrigger>
                      <SelectContent>
                        {designList.designs.map((item) => (
                          <SelectItem key={item.id} value={item.id}>
                            {designName(item)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {designList.isError ? (
                      <p role="alert" className="text-xs text-red-500">
                        Could not load your email designs.{" "}
                        <button type="button" className="font-medium underline underline-offset-2" onClick={() => void designList.retry()}>
                          Try again
                        </button>
                      </p>
                    ) : null}
                  </div>
                </FormField>

                <div className="flex flex-col gap-3">
                  <FormField label="Sign-off" error={ownSignOff ? undefined : problems.fields.sign_off}>
                    <div className="flex min-h-9 items-center gap-2 text-sm text-foreground">
                      <Switch
                        checked={ownSignOff}
                        onCheckedChange={(checked) =>
                          changeContent((current) =>
                            withSignOff(current, checked ? (design?.layout.signature.sign_off ?? "") : undefined),
                          )
                        }
                        disabled={pending}
                        aria-label="Use a sign-off of this template's own"
                      />
                      <span>
                        {ownSignOff
                          ? "This template's own sign-off"
                          : design
                            ? `The design's sign-off${design.layout.signature.sign_off ? ` — “${design.layout.signature.sign_off}”` : ""}`
                            : "The design's sign-off"}
                      </span>
                    </div>
                  </FormField>
                  {ownSignOff ? (
                    <TextLineControl
                      label="Own sign-off"
                      value={content.sign_off ?? ""}
                      onChange={(value) => changeContent((current) => withSignOff(current, value))}
                      tokens={catalog.tokens}
                      maxLength={TEXT_LIMITS.signOff}
                      placeholder="Leave empty for no sign-off line"
                      disabled={pending}
                      error={problems.fields.sign_off}
                    />
                  ) : null}
                  {signatureOff ? (
                    <p className="text-xs text-muted-foreground">This design has no signature area, so no sign-off is shown.</p>
                  ) : null}
                </div>

                <div className="flex flex-col gap-3">
                  <button
                    type="button"
                    onClick={() => setStylesOpen((open) => !open)}
                    aria-expanded={stylesOpen}
                    aria-controls="template-style-overrides"
                    className="flex w-fit items-center gap-1.5 rounded-md text-sm font-medium text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <ChevronRight className={cn("size-4 text-muted-foreground transition-transform", stylesOpen && "rotate-90")} aria-hidden />
                    Override the design&apos;s style
                    {overrideCount > 0 ? <span className="text-xs font-normal text-muted-foreground">({overrideCount} set)</span> : null}
                  </button>
                  {stylesOpen ? (
                    <div id="template-style-overrides" className="flex flex-col gap-4 rounded-lg border border-border p-4">
                      <p className="text-xs text-muted-foreground">
                        Styles marked Default come from the design. Set one only when this template should look different.
                      </p>
                      <FormField label="Font" error={problems.fields["style_overrides.font"]}>
                        <Select
                          value={overrides.font ?? DESIGN_FONT}
                          onValueChange={(value) =>
                            typeof value === "string" && changeOverride("font", value === DESIGN_FONT ? undefined : (value as DesignFont))
                          }
                          disabled={pending}
                        >
                          <SelectTrigger className="w-full sm:w-64" aria-label="Template font">
                            <SelectValue>
                              {overrides.font
                                ? FONT_OPTIONS.find((option) => option.value === overrides.font)?.label
                                : `Default (${designFontLabel})`}
                            </SelectValue>
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value={DESIGN_FONT}>Default ({designFontLabel})</SelectItem>
                            {FONT_OPTIONS.map((option) => (
                              <SelectItem key={option.value} value={option.value}>
                                {option.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </FormField>
                      <div className="flex flex-wrap gap-4">
                        {COLOR_STYLE_FIELDS.map((key) => (
                          <ColorControl
                            key={key}
                            label={STYLE_LABELS[key]}
                            value={overrides[key]}
                            inherited={effectiveStyle(designChain, key)}
                            onChange={(value) => changeOverride(key, value)}
                            disabled={pending}
                            error={problems.fields[`style_overrides.${key}`]}
                          />
                        ))}
                      </div>
                    </div>
                  ) : null}
                </div>
              </section>

              <DesignBlockList
                title="Content"
                description="This template's message, between the design's header and its signature area."
                blocks={content.blocks}
                onChange={(next) => changeContent((current) => ({ ...current, blocks: next(current.blocks) }))}
                addable={ADDABLE_BLOCKS.blocks}
                max={MAX_CONTENT_BLOCKS}
                problems={problems.blocks}
                tokens={catalog.tokens}
                styleChain={styleChain}
                disabled={pending}
                emptyMessage="No content yet. Add a heading or some text below."
                initiallyExpanded={seed.content.blocks[0]?.id ?? null}
              />
            </div>

            <div className="border-t border-border bg-muted/30 px-6 py-5 lg:min-h-0 lg:overflow-y-auto lg:border-t-0 lg:border-l">
              <DesignPreviewPanel
                preview={preview.preview}
                isUpdating={preview.isUpdating}
                requestFailed={preview.requestFailed}
                problems={problems}
                blockLabel={blockLabel}
                fieldLabel={fieldLabel}
                device={device}
                onDeviceChange={setDevice}
                onRetry={preview.retry}
                note="Shown in the chosen design with sample CRM data and your organization logo. Image blocks appear once image uploads are available."
              />
            </div>
          </div>

          <SheetFooter className="flex-row justify-end gap-2 border-t border-border px-6 py-4">
            <Button type="button" variant="outline" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" disabled={pending}>
              {pending ? "Saving…" : template === null ? "Create template" : "Save template"}
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
