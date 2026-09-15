"use client";

import { useMemo, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Info } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { Switch } from "@/modules/crm/components/ui/switch";
import { Checkbox } from "@/modules/crm/components/ui/checkbox";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/modules/crm/components/ui/select";
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from "@/modules/crm/components/ui/sheet";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { ApplyToAllDialog } from "@/modules/crm/features/email-templates/components/apply-to-all-dialog";
import { DesignBlockList } from "@/modules/crm/features/email-templates/components/designer/design-block-list";
import {
  AlignmentControl,
  ColorControl,
  TextLineControl,
} from "@/modules/crm/features/email-templates/components/designer/design-controls";
import { DesignPreviewPanel, type PreviewDevice } from "@/modules/crm/features/email-templates/components/designer/design-preview-panel";
import { useEmailPreview } from "@/modules/crm/features/email-templates/hooks/use-email-preview";
import { useCreateEmailDesign, useUpdateEmailDesign } from "@/modules/crm/features/email-templates/hooks/use-email-designs";
import { apiFieldErrors } from "@/modules/crm/features/email-templates/hooks/use-email-templates-admin";
import {
  ADDABLE_BLOCKS,
  BLOCK_LABELS,
  COLOR_STYLE_FIELDS,
  FONT_OPTIONS,
  MAX_REGION_BLOCKS,
  REGION_LABELS,
  STYLE_DEFAULTS,
  STYLE_LABELS,
  TEXT_LIMITS,
  blockIds,
  defaultLayout,
  documentProblems,
  documentRegions,
  effectiveStyle,
  mergeProblems,
  serializeDocument,
  withStyle,
} from "@/modules/crm/features/email-templates/lib/email-design";
import type {
  DesignBlock,
  DesignFont,
  DesignProblems,
  DesignStyleKey,
  EmailDesign,
  EmailDesignLayout,
  SignatureArea,
} from "@/modules/crm/features/email-templates/types/email-design-types";
import type { TemplateToken } from "@/modules/crm/features/email-templates/types/email-template-types";

const PREVIEW_ENDPOINT = "/settings/email-designs/preview";

/** The Font select's value for "not set here". Never sent: an unset font is simply absent. */
const DEFAULT_FONT = "default";

const SIGNATURE_LABELS: Record<string, string> = { enabled: "On or off", sign_off: "Sign-off", align: "Alignment" };

const schema = z.object({
  name: z
    .string()
    .refine((value) => value.trim().length > 0, "A name is required.")
    .refine((value) => value.length <= TEXT_LIMITS.designName, `Keep the name under ${TEXT_LIMITS.designName} characters.`),
});

type FormValues = z.infer<typeof schema>;

type Props = {
  /** null creates a new design. */
  design: EmailDesign | null;
  tokens: readonly TemplateToken[];
  onClose: () => void;
};

/**
 * Settings → Email Designs → the design editor.
 *
 * A design is the look designed templates share: the page style, a header above each template's content, a
 * signature area after it, and a footer. Its header and footer are built from blocks like a template's
 * content. On the right, the server renders the design with a sample message standing in for a template.
 *
 * A saved change reaches every template that uses the design. The server regenerates their plain-text
 * versions and refuses a change that would break any of them; that refusal is shown with the other problems.
 *
 * What leaves the browser is the layout JSON, and only the JSON. The server validates it and is the only thing
 * that writes HTML.
 */
export function EmailDesignEditor({ design, tokens, onClose }: Props) {
  const create = useCreateEmailDesign();
  const update = useUpdateEmailDesign();
  const pending = create.isPending || update.isPending;

  const [layout, setLayout] = useState<EmailDesignLayout>(() => design?.layout ?? defaultLayout());
  const [device, setDevice] = useState<PreviewDevice>("desktop");
  // A save's refusals, against the layout that was sent; cleared by the next edit.
  const [saveFailure, setSaveFailure] = useState<{ errors: Record<string, string>; layout: EmailDesignLayout } | null>(null);
  const [applyToAll, setApplyToAll] = useState(false);
  // The values waiting while the admin reviews what "Apply to all" will change.
  const [confirmingApply, setConfirmingApply] = useState<FormValues | null>(null);
  const [applyError, setApplyError] = useState<string | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    mode: "onTouched",
    defaultValues: { name: design?.name ?? "" },
  });
  const { errors } = form.formState;

  const preview = useEmailPreview({ endpoint: PREVIEW_ENDPOINT, field: "layout", document: layout });

  const problems: DesignProblems = useMemo(
    () =>
      saveFailure === null
        ? preview.problems
        : mergeProblems(documentProblems(saveFailure.errors, "layout", documentRegions(saveFailure.layout)), preview.problems),
    [preview.problems, saveFailure],
  );

  const styleChain = useMemo(() => [layout.styles], [layout.styles]);
  const headerIds = useMemo(() => blockIds(layout.header), [layout.header]);
  const footerIds = useMemo(() => blockIds(layout.footer), [layout.footer]);

  function changeLayout(next: (current: EmailDesignLayout) => EmailDesignLayout) {
    setLayout(next);
    setSaveFailure(null);
  }

  function changeStyle(key: DesignStyleKey, value: string | undefined) {
    changeLayout((current) => ({ ...current, styles: withStyle(current.styles, key, value) }));
  }

  function changeSignature(patch: Partial<SignatureArea>) {
    changeLayout((current) => ({ ...current, signature: { ...current.signature, ...patch } }));
  }

  const blockLabel = (id: string) => {
    for (const region of ["header", "footer"] as const) {
      const index = layout[region].findIndex((block: DesignBlock) => block.id === id);
      if (index !== -1) return `${REGION_LABELS[region]} · ${BLOCK_LABELS[layout[region][index].type]} (block ${index + 1})`;
    }
    return "A removed block";
  };

  const fieldLabel = (field: string) => {
    const [root, key = ""] = field.split(".");
    if (root === "styles") return key in STYLE_LABELS ? `Page style · ${STYLE_LABELS[key as DesignStyleKey]}` : "Page style";
    if (root === "signature") return SIGNATURE_LABELS[key] ? `Signature · ${SIGNATURE_LABELS[key]}` : "Signature";
    return field;
  };

  const defaultFontLabel = FONT_OPTIONS.find((option) => option.value === STYLE_DEFAULTS.font)?.label ?? STYLE_DEFAULTS.font;

  function save(values: FormValues, applyCount: number | null) {
    const sent = layout;
    const payload = {
      name: values.name,
      layout: serializeDocument(sent),
      ...(applyCount === null ? {} : { apply_to_all: true, apply_to_all_count: applyCount }),
    };

    const onError = (err: unknown) => {
      const fieldErrors = apiFieldErrors(err);
      if (fieldErrors.name) form.setError("name", { type: "server", message: fieldErrors.name });
      setApplyError(fieldErrors.apply_to_all_count ?? fieldErrors.apply_to_all ?? null);
      setSaveFailure({ errors: fieldErrors, layout: sent });
    };

    setApplyError(null);
    if (design === null) {
      create.mutate(payload, { onSuccess: onClose, onError });
    } else {
      update.mutate({ id: design.id, ...payload }, { onSuccess: onClose, onError });
    }
  }

  // "Apply to all" is confirmed against the server's counts before anything is sent.
  const submit = form.handleSubmit((values) => (applyToAll ? setConfirmingApply(values) : save(values, null)));

  const usedBy = design?.templatesCount ?? 0;

  return (
    <Sheet
      open
      onOpenChange={(open) => {
        if (!open && !pending) onClose();
      }}
    >
      <SheetContent side="right" className="w-full gap-0 p-0 data-[side=right]:sm:max-w-none">
        <SheetHeader className="border-b border-border px-6 py-4">
          <SheetTitle>{design === null ? "New email design" : "Edit email design"}</SheetTitle>
          <SheetDescription>
            {design === null
              ? "The page style, header, signature area and footer that designed templates are shown in."
              : design.isDefault
                ? "Your organization's default design, used by designed templates that do not choose another."
                : design.name}
          </SheetDescription>
        </SheetHeader>

        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col" noValidate>
          <div className="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:overflow-hidden">
            <div className="flex flex-col gap-6 px-6 py-5 lg:min-h-0 lg:overflow-y-auto">
              {usedBy > 0 ? (
                <p className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                  <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                  {usedBy === 1 ? "1 template uses" : `${usedBy} templates use`} this design. Saving changes how{" "}
                  {usedBy === 1 ? "it looks" : "they all look"}; a change that would break one of them is refused.
                </p>
              ) : null}

              <FormField label="Name" required error={errors.name?.message}>
                <input
                  {...form.register("name")}
                  className={FIELD}
                  maxLength={TEXT_LIMITS.designName}
                  placeholder="e.g. Proposal — teal"
                  disabled={pending}
                />
              </FormField>

              <section aria-labelledby="design-style" className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                  <h3 id="design-style" className="text-sm font-semibold text-foreground">
                    Page style
                  </h3>
                  <p className="text-xs text-muted-foreground">
                    Styles marked Default follow your organization&apos;s email branding once it is set up. A template can
                    still override them for itself.
                  </p>
                </div>
                <FormField label="Font" error={problems.fields["styles.font"]}>
                  <Select
                    value={layout.styles.font ?? DEFAULT_FONT}
                    onValueChange={(value) =>
                      typeof value === "string" && changeStyle("font", value === DEFAULT_FONT ? undefined : (value as DesignFont))
                    }
                    disabled={pending}
                  >
                    <SelectTrigger className="w-full sm:w-64" aria-label="Font">
                      <SelectValue>
                        {layout.styles.font
                          ? FONT_OPTIONS.find((option) => option.value === layout.styles.font)?.label
                          : `Default (${defaultFontLabel})`}
                      </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={DEFAULT_FONT}>Default ({defaultFontLabel})</SelectItem>
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
                      value={layout.styles[key]}
                      inherited={effectiveStyle([], key)}
                      onChange={(value) => changeStyle(key, value)}
                      disabled={pending}
                      error={problems.fields[`styles.${key}`]}
                    />
                  ))}
                </div>
              </section>

              <DesignBlockList
                title="Header"
                description="Shown above every template's content."
                blocks={layout.header}
                onChange={(next) => changeLayout((current) => ({ ...current, header: next(current.header) }))}
                addable={ADDABLE_BLOCKS.header}
                max={MAX_REGION_BLOCKS}
                otherIds={footerIds}
                problems={problems.blocks}
                tokens={tokens}
                styleChain={styleChain}
                disabled={pending}
                emptyMessage="No header. Emails start straight with the template's content."
              />

              <section aria-labelledby="design-signature" className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                  <h3 id="design-signature" className="text-sm font-semibold text-foreground">
                    Signature area
                  </h3>
                  <p className="text-xs text-muted-foreground">
                    After the content: a sign-off, then the sender&apos;s name. Their title and phone number will appear here
                    once sender profiles are available. A template may use its own sign-off.
                  </p>
                </div>
                <FormField label="Signature" error={problems.fields["signature.enabled"]}>
                  <div className="flex min-h-9 items-center gap-2 text-sm text-foreground">
                    <Switch
                      checked={layout.signature.enabled}
                      onCheckedChange={(checked) => changeSignature({ enabled: checked })}
                      disabled={pending}
                      aria-label="Show the signature area"
                    />
                    <span>{layout.signature.enabled ? "Shown after the content" : "Not shown"}</span>
                  </div>
                </FormField>
                {layout.signature.enabled ? (
                  <>
                    <TextLineControl
                      label="Sign-off"
                      value={layout.signature.sign_off}
                      onChange={(sign_off) => changeSignature({ sign_off })}
                      tokens={tokens}
                      maxLength={TEXT_LIMITS.signOff}
                      placeholder="Best regards,"
                      disabled={pending}
                      error={problems.fields["signature.sign_off"]}
                    />
                    <AlignmentControl
                      value={layout.signature.align}
                      onChange={(align) => changeSignature({ align })}
                      disabled={pending}
                      error={problems.fields["signature.align"]}
                    />
                  </>
                ) : null}
              </section>

              <DesignBlockList
                title="Footer"
                description="Shown at the bottom of every email, after the signature."
                blocks={layout.footer}
                onChange={(next) => changeLayout((current) => ({ ...current, footer: next(current.footer) }))}
                addable={ADDABLE_BLOCKS.footer}
                max={MAX_REGION_BLOCKS}
                otherIds={headerIds}
                problems={problems.blocks}
                tokens={tokens}
                styleChain={styleChain}
                disabled={pending}
                emptyMessage="No footer. Add your company name, address or contact details."
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
                note="A sample message stands in for each template's content. The logo is your organization logo; image blocks appear once image uploads are available."
              />
            </div>
          </div>

          <SheetFooter className="flex-row flex-wrap items-center justify-end gap-2 border-t border-border px-6 py-4">
            <div className="mr-auto flex min-w-0 flex-col gap-1">
              <label className="flex items-center gap-2 text-sm text-foreground">
                <Checkbox checked={applyToAll} onCheckedChange={(checked) => setApplyToAll(checked === true)} disabled={pending} />
                Apply to all designed templates
              </label>
              {applyError ? (
                <p role="alert" className="text-xs text-red-500">
                  {applyError}
                </p>
              ) : null}
            </div>
            <Button type="button" variant="outline" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" disabled={pending}>
              {pending
                ? "Saving…"
                : design === null
                  ? applyToAll
                    ? "Create and apply"
                    : "Create design"
                  : applyToAll
                    ? "Save and apply"
                    : "Save design"}
            </Button>
          </SheetFooter>
        </form>

        <ApplyToAllDialog
          open={confirmingApply !== null}
          designId={design?.id ?? null}
          designName={confirmingApply?.name.trim() || "this design"}
          loading={pending}
          onCancel={() => setConfirmingApply(null)}
          onConfirm={(count) => {
            const values = confirmingApply;
            setConfirmingApply(null);
            if (values) save(values, count);
          }}
        />
      </SheetContent>
    </Sheet>
  );
}
