"use client";

import { useState } from "react";
import { Info, RotateCw } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/modules/crm/components/ui/dialog";
import { EmailHtmlFrame } from "@/modules/crm/components/shared/email-body/email-html-frame";
import {
  PREVIEW_DEVICE_WIDTH,
  PreviewDeviceToggle,
  type PreviewDevice,
} from "@/modules/crm/features/email-templates/components/designer/design-preview-panel";
import { useEmailTemplatePreview, type EmailTemplatePreview } from "@/modules/crm/features/follow-ups/hooks/use-email-templates";
import { apiClient } from "@/modules/crm/lib/axios";
import { emailAssetImageSource } from "@/modules/crm/lib/email-body";
import { cn } from "@/modules/crm/lib/utils";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  leadId: string;
  /** The unedited template being previewed — the composer's `sendableTemplateId`. */
  templateId: string;
  designName: string | null;
  recipientName: string;
  /** The composer's current Subject: sent as typed, so it is shown as typed. */
  subject: string;
};

/** Why the send would go out as plain text instead — said plainly, since the design will not be sent. */
function plainTextReason(reason: EmailTemplatePreview["reason"]): string {
  switch (reason) {
    case "html_too_large":
      return "With this lead's details the email is too large for Gmail, which cuts off emails over about 102 KB, so it will be sent as plain text.";
    case "document_invalid":
      return "This template's design can't be rendered right now, so it will be sent as plain text. An admin can fix it in Settings → Email Templates.";
    default:
      return "This template has no design to render, so it will be sent as plain text.";
  }
}

/**
 * "This is the HTML this lead will receive if I send this unchanged right now."
 *
 * Read-only. The HTML is the server's — the send path's own rendering for this lead with the signed-in BD as
 * sender — shown in the same sandboxed, script-free EmailHtmlFrame Lead Details uses. The design belongs to
 * the template (Settings), so there is nothing to choose here. The CRM's own logo route is trusted exactly
 * as Lead Details trusts it for sent mail, so the logo shows from the same public URL the recipient's email
 * client will load.
 */
export function EmailDesignPreviewDialog({ open, onOpenChange, leadId, templateId, designName, recipientName, subject }: Props) {
  const [device, setDevice] = useState<PreviewDevice>("desktop");
  const preview = useEmailTemplatePreview(leadId, templateId, open);
  const assetSource = emailAssetImageSource(apiClient.defaults.baseURL ?? "");

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90vh] w-full flex-col gap-4 overflow-y-auto px-6 py-5 sm:max-w-[720px]">
        <DialogHeader className="p-0 pr-8">
          <DialogTitle>Email preview</DialogTitle>
          <DialogDescription>
            What {recipientName || "this lead"} will receive if you send this unchanged now · Designed · {designName ?? "Email design"}
          </DialogDescription>
        </DialogHeader>

        <PreviewDeviceToggle device={device} onDeviceChange={setDevice} />

        <div className="rounded-lg border border-border bg-card px-4 py-3">
          <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">Subject</p>
          <p className="mt-0.5 break-words text-sm font-semibold text-foreground">{subject.trim() || "(no subject)"}</p>
        </div>

        {preview.isPending ? (
          <Skeleton className="h-96 w-full" />
        ) : preview.isError ? (
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm text-muted-foreground">
            The preview could not be loaded.
            <Button type="button" size="xs" variant="outline" onClick={() => preview.refetch()}>
              <RotateCw aria-hidden />
              Try again
            </Button>
          </div>
        ) : preview.data.html === null ? (
          <p role="status" className="flex items-start gap-1.5 rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground">
            <Info className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
            {plainTextReason(preview.data.reason)}
          </p>
        ) : (
          <div className={cn("mx-auto w-full", PREVIEW_DEVICE_WIDTH[device])}>
            <EmailHtmlFrame html={preview.data.html} trustedImageSources={assetSource === null ? [] : [assetSource]} />
          </div>
        )}

        <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
          <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
          This is the HTML generated for this lead, with you as the sender. Gmail, Outlook and other email apps may display
          it slightly differently.
        </p>
      </DialogContent>
    </Dialog>
  );
}
