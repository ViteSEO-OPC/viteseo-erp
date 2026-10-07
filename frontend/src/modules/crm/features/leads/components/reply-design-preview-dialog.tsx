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
import type { ReplyDesignPreview } from "@/modules/crm/features/leads/hooks/use-reply-design";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { cn } from "@/modules/crm/lib/utils";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  subject: string;
  designName: string;
  preview: { isPending: boolean; error: unknown; data: ReplyDesignPreview | undefined };
  onRetry: () => void;
  trustedImageSources: readonly string[];
};

/**
 * The reply as this lead will receive it in the chosen Email Design: the design's header, logo, styles,
 * signature and footer around the BD's own words. The HTML is the server's render through the same call the
 * send makes, so nothing here can differ from what goes out. Shown in the same sandboxed, script-free frame
 * the thread uses for sent emails.
 */
export function ReplyDesignPreviewDialog({ open, onOpenChange, subject, designName, preview, onRetry, trustedImageSources }: Props) {
  const [device, setDevice] = useState<PreviewDevice>("desktop");

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90vh] w-full flex-col gap-4 overflow-y-auto px-6 py-5 sm:max-w-[720px]">
        <DialogHeader className="p-0 pr-8">
          <DialogTitle>Reply preview</DialogTitle>
          <DialogDescription>What this lead will receive if you send this reply now · Design · {designName}</DialogDescription>
        </DialogHeader>

        <PreviewDeviceToggle device={device} onDeviceChange={setDevice} />

        <div className="rounded-lg border border-border bg-card px-4 py-3">
          <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">Subject</p>
          <p className="mt-0.5 break-words text-sm font-semibold text-foreground">{subject.trim() || "(no subject)"}</p>
        </div>

        {preview.isPending ? (
          <Skeleton className="h-96 w-full" />
        ) : preview.data ? (
          <div className={cn("mx-auto w-full", PREVIEW_DEVICE_WIDTH[device])}>
            <EmailHtmlFrame html={preview.data.html} trustedImageSources={trustedImageSources} />
          </div>
        ) : (
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm text-muted-foreground">
            {getApiErrorMessage(preview.error, "The preview could not be loaded.")}
            <Button type="button" size="xs" variant="outline" onClick={onRetry}>
              <RotateCw aria-hidden />
              Try again
            </Button>
          </div>
        )}

        <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
          <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
          The design adds its own header, signature and footer around your reply. Gmail, Outlook and other email apps may
          display it slightly differently.
        </p>
      </DialogContent>
    </Dialog>
  );
}
