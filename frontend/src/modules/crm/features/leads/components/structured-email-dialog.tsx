"use client";

import { useMemo, useState } from "react";
import { notify as toast } from "@/core/lib/notify";
import { Button } from "@/modules/crm/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/modules/crm/components/ui/select";
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from "@/modules/crm/components/ui/sheet";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { DesignPreviewPanel, type PreviewDevice } from "@/modules/crm/features/email-templates/components/designer/design-preview-panel";
import { StructuredEmailEditor } from "@/modules/crm/features/email-templates/components/designer/structured-email-editor";
import { useEmailPreview } from "@/modules/crm/features/email-templates/hooks/use-email-preview";
import { apiFieldErrors } from "@/modules/crm/features/email-templates/hooks/use-email-templates-admin";
import { BLOCK_LABELS, documentProblems, documentRegions, mergeProblems } from "@/modules/crm/features/email-templates/lib/email-design";
import type { DesignProblems, TemplateContent } from "@/modules/crm/features/email-templates/types/email-design-types";
import { useEmailTemplates } from "@/modules/crm/features/follow-ups/hooks/use-email-templates";
import { useEmailDesignOptions } from "@/modules/crm/features/leads/hooks/use-reply-design";
import { useStructuredEmail, type StructuredEmail } from "@/modules/crm/features/leads/hooks/use-structured-email";

/** No placeholder chips here: the copy belongs to one lead, so its text is usually written for them already. */
const NO_TOKENS = [] as const;

/** A lead's copy is shown in a design the BD may not open in Settings, so its colors read as the defaults. */
const NO_STYLE_CHAIN = [] as const;

type Props = {
  leadId: string;
  /** A structured draft to keep editing; null starts a new one from a template. */
  existing: StructuredEmail | null;
  onClose: () => void;
};

/**
 * Lead Details → New designed email.
 *
 * Choosing a designed Email Template asks the server to copy its content, design and subject onto a new draft for
 * this lead. Everything after that edits the lead's copy only — with the same StructuredEmailEditor Settings uses —
 * so the reusable template every other lead is offered never changes. The preview is the server's rendering of the
 * copy for this lead and sender, the same call the send makes. Distinct from Reply, which frames a free-form reply
 * in a design.
 */
export function StructuredEmailDialog({ leadId, existing, onClose }: Props) {
  const [draft, setDraft] = useState<StructuredEmail | null>(existing);

  return (
    <Sheet
      open
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
    >
      <SheetContent side="right" className="w-full gap-0 p-0 data-[side=right]:sm:max-w-none">
        <SheetHeader className="border-b border-border px-6 py-4">
          <SheetTitle>New designed email</SheetTitle>
          <SheetDescription>
            Start from a designed Email Template, then change it for this lead only. The template itself is never changed.
          </SheetDescription>
        </SheetHeader>
        {draft === null ? (
          <StartStep leadId={leadId} onStarted={setDraft} onClose={onClose} />
        ) : (
          <EditStep key={draft.id} leadId={leadId} draft={draft} onClose={onClose} />
        )}
      </SheetContent>
    </Sheet>
  );
}

function StartStep({ leadId, onStarted, onClose }: { leadId: string; onStarted: (draft: StructuredEmail) => void; onClose: () => void }) {
  const { templates, isLoading } = useEmailTemplates(leadId);
  const designed = templates.filter((template) => template.isDesigned);
  const { start } = useStructuredEmail(leadId);
  const [templateId, setTemplateId] = useState("");
  const chosen = designed.find((template) => template.id === templateId);

  return (
    <>
      <div className="flex flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
        <FormField label="Email Template" required error={start.isError ? getApiErrorMessage(start.error, "This template could not be used.") : undefined}>
          <Select value={templateId} onValueChange={(value) => typeof value === "string" && setTemplateId(value)} disabled={start.isPending}>
            <SelectTrigger className="w-full sm:w-96" aria-label="Email Template">
              <SelectValue placeholder={isLoading ? "Loading templates…" : "Choose a designed template"}>
                {chosen ? `${chosen.purpose} · ${chosen.tone}` : undefined}
              </SelectValue>
            </SelectTrigger>
            <SelectContent>
              {designed.map((template) => (
                <SelectItem key={template.id} value={template.id}>
                  {template.purpose} · {template.tone}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </FormField>
        {!isLoading && designed.length === 0 ? (
          <p className="text-sm text-muted-foreground">There are no active templates made with the visual designer yet.</p>
        ) : null}
        {chosen ? (
          <p className="text-xs text-muted-foreground">
            Shown in {chosen.designName ?? "its design"}. Its content is copied for this lead; you can change the copy and the design before sending.
          </p>
        ) : null}
      </div>
      <SheetFooter className="flex-row justify-end gap-2 border-t border-border px-6 py-4">
        <Button type="button" variant="outline" onClick={onClose} disabled={start.isPending}>
          Cancel
        </Button>
        <Button type="button" disabled={templateId === "" || start.isPending} onClick={() => start.mutate(templateId, { onSuccess: onStarted })}>
          {start.isPending ? "Copying…" : "Use this template"}
        </Button>
      </SheetFooter>
    </>
  );
}

function EditStep({ leadId, draft, onClose }: { leadId: string; draft: StructuredEmail; onClose: () => void }) {
  const designs = useEmailDesignOptions();
  const { templates } = useEmailTemplates(leadId);
  const { save, send, schedule } = useStructuredEmail(leadId);
  const [content, setContent] = useState<TemplateContent>(draft.content);
  const [designId, setDesignId] = useState(draft.emailDesignId ?? "");
  const [subject, setSubject] = useState(draft.subject);
  const [scheduleAt, setScheduleAt] = useState("");
  const [device, setDevice] = useState<PreviewDevice>("desktop");
  // A save's refusals, against the content that was sent; cleared by the next edit.
  const [saveFailure, setSaveFailure] = useState<{ errors: Record<string, string>; content: TemplateContent } | null>(null);
  const pending = save.isPending || send.isPending || schedule.isPending;

  const template = templates.find((item) => item.id === draft.emailTemplateId);
  const design = designs.data?.find((item) => item.id === designId);

  const previewExtra = useMemo(() => ({ email_design_id: designId, subject }), [designId, subject]);
  const preview = useEmailPreview({
    endpoint: `/leads/${leadId}/emails/structured-preview`,
    field: "content",
    document: content,
    extra: previewExtra,
  });

  const problems: DesignProblems = useMemo(
    () =>
      saveFailure === null
        ? preview.problems
        : mergeProblems(documentProblems(saveFailure.errors, "content", documentRegions(saveFailure.content)), preview.problems),
    [preview.problems, saveFailure],
  );

  function changeContent(update: (current: TemplateContent) => TemplateContent) {
    setContent(update);
    setSaveFailure(null);
  }

  const blockLabel = (id: string) => {
    for (const [index, block] of content.blocks.entries()) {
      if (block.id === id) return `${BLOCK_LABELS[block.type]} (block ${index + 1})`;
      if (block.type === "card") {
        const inner = block.blocks.findIndex((child) => child.id === id);
        if (inner !== -1) return `Card (block ${index + 1}) · ${BLOCK_LABELS[block.blocks[inner].type]} (block ${inner + 1})`;
      }
    }
    return "A removed block";
  };

  /** Save the lead's copy; resolves with the saved draft, or rejects after placing the server's problems. */
  async function persist(): Promise<StructuredEmail> {
    const sent = content;
    try {
      return await save.mutateAsync({ id: draft.id, subject, emailDesignId: designId, content: sent });
    } catch (err) {
      setSaveFailure({ errors: apiFieldErrors(err), content: sent });
      throw err;
    }
  }

  async function onSave() {
    try {
      await persist();
      toast.success("Draft saved");
    } catch (err) {
      toast.error(getApiErrorMessage(err, "The draft could not be saved."));
    }
  }

  async function onSend() {
    try {
      await persist();
      await send.mutateAsync(draft.id);
      toast.success("Email sent");
      onClose();
    } catch (err) {
      toast.error(getApiErrorMessage(err, "The email could not be sent."));
    }
  }

  async function onSchedule() {
    if (scheduleAt === "") return;
    try {
      await persist();
      await schedule.mutateAsync({ id: draft.id, scheduledAt: new Date(scheduleAt).toISOString() });
      toast.success("Email scheduled");
      onClose();
    } catch (err) {
      toast.error(getApiErrorMessage(err, "The email could not be scheduled."));
    }
  }

  return (
    <>
      <div className="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:overflow-hidden">
        <div className="flex flex-col gap-5 px-6 py-5 lg:min-h-0 lg:overflow-y-auto">
          <FormField label="Email Template">
            <p className="flex min-h-9 items-center text-sm text-foreground">
              {template ? `${template.purpose} · ${template.tone}` : "A designed template"}
              <span className="ml-2 text-xs text-muted-foreground">copied for this lead</span>
            </p>
          </FormField>
          <FormField label="Email Design" required error={problems.general.find((message) => message.startsWith("Email design")) ?? undefined}>
            <Select
              value={designId}
              onValueChange={(value) => {
                if (typeof value !== "string" || value === "") return;
                setDesignId(value);
                setSaveFailure(null);
              }}
              disabled={pending}
            >
              <SelectTrigger className="w-full sm:w-80" aria-label="Email Design">
                <SelectValue placeholder="Choose a design">{design?.name}</SelectValue>
              </SelectTrigger>
              <SelectContent>
                {(designs.data ?? []).map((item) => (
                  <SelectItem key={item.id} value={item.id}>
                    {item.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FormField>
          <FormField label="Subject" required error={saveFailure?.errors.subject}>
            <input
              value={subject}
              onChange={(event) => setSubject(event.target.value)}
              maxLength={255}
              className={FIELD}
              aria-label="Subject"
              disabled={pending}
            />
          </FormField>
          <StructuredEmailEditor
            content={content}
            onChange={changeContent}
            problems={problems.blocks}
            tokens={NO_TOKENS}
            styleChain={NO_STYLE_CHAIN}
            disabled={pending}
            description="This lead's copy of the template. Changes here never change the template."
          />
        </div>
        <div className="border-t border-border bg-muted/30 px-6 py-5 lg:min-h-0 lg:overflow-y-auto lg:border-t-0 lg:border-l">
          <DesignPreviewPanel
            preview={preview.preview}
            isUpdating={preview.isUpdating}
            requestFailed={preview.requestFailed}
            problems={problems}
            blockLabel={blockLabel}
            fieldLabel={(field) => field}
            device={device}
            onDeviceChange={setDevice}
            onRetry={preview.retry}
            note="Exactly what this lead receives from you: rendered for this lead and signed by you, as the send renders it."
          />
        </div>
      </div>
      <SheetFooter className="flex-row flex-wrap items-center justify-end gap-2 border-t border-border px-6 py-4">
        <div className="mr-auto flex items-center gap-2">
          <input
            type="datetime-local"
            value={scheduleAt}
            onChange={(event) => setScheduleAt(event.target.value)}
            className={`${FIELD} h-9 w-auto`}
            aria-label="Schedule for"
            disabled={pending}
          />
          <Button type="button" variant="outline" onClick={onSchedule} disabled={pending || scheduleAt === ""}>
            {schedule.isPending ? "Scheduling…" : "Schedule"}
          </Button>
        </div>
        <Button type="button" variant="outline" onClick={onClose} disabled={pending}>
          Close
        </Button>
        <Button type="button" variant="outline" onClick={onSave} disabled={pending}>
          {save.isPending && !send.isPending && !schedule.isPending ? "Saving…" : "Save draft"}
        </Button>
        <Button type="button" onClick={onSend} disabled={pending}>
          {send.isPending ? "Sending…" : "Send"}
        </Button>
      </SheetFooter>
    </>
  );
}
