"use client";

import { useState } from "react";
import { ImageUp, Trash2 } from "lucide-react";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Button } from "@/modules/crm/components/ui/button";
import { Card } from "@/modules/crm/components/ui/card";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { cn } from "@/modules/crm/lib/utils";
import { CHECKERBOARD } from "@/modules/crm/features/email-templates/components/branding/logo-cropper";
import { LogoUploadDialog } from "@/modules/crm/features/email-templates/components/branding/logo-upload-dialog";
import { useEmailBranding, useRemoveLogo } from "@/modules/crm/features/email-templates/hooks/use-email-branding";
import type { EmailDesign } from "@/modules/crm/features/email-templates/types/email-design-types";

/**
 * The organization logo: the branding asset every design's Logo block shows. No design stores the image or its
 * address, so replacing the logo here changes it in every design and template at once.
 */
export function OrganizationLogoCard({ designs }: { designs: readonly EmailDesign[] }) {
  const { logo, isLoading, isError, retry } = useEmailBranding();
  const remove = useRemoveLogo();
  const [uploading, setUploading] = useState(false);
  const [confirmingRemove, setConfirmingRemove] = useState(false);

  const showingIt = designs.filter((design) => [...design.layout.header, ...design.layout.footer].some((block) => block.type === "logo")).length;

  return (
    <Card className="border border-border bg-card p-6 shadow-card" aria-labelledby="organization-logo">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 flex-col gap-1">
          <h3 id="organization-logo" className="text-base font-semibold text-foreground">
            Organization logo
          </h3>
          <p className="text-sm text-muted-foreground">
            Shown by the Logo block of every design
            {showingIt > 0 ? ` — ${showingIt} ${showingIt === 1 ? "design has" : "designs have"} one` : ""}. Replacing it updates
            every design and template at once.
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <Button onClick={() => setUploading(true)} disabled={isLoading || isError}>
            <ImageUp aria-hidden />
            {logo ? "Replace logo" : "Upload logo"}
          </Button>
          {logo ? (
            <Button variant="outline" onClick={() => setConfirmingRemove(true)} disabled={remove.isPending}>
              <Trash2 aria-hidden />
              Remove
            </Button>
          ) : null}
        </div>
      </div>

      <div className="mt-4">
        {isError ? (
          <ErrorState message="Could not load the organization logo." onRetry={() => void retry()} />
        ) : isLoading ? (
          <Skeleton className="h-24 w-64" />
        ) : logo ? (
          <div className="flex flex-wrap items-center gap-4">
            <div className="flex h-24 min-w-40 items-center justify-center rounded-lg border border-border bg-white p-3">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={logo.url}
                alt="Organization logo"
                data-organization-logo={logo.id}
                className={cn("max-h-full max-w-64 object-contain", CHECKERBOARD)}
              />
            </div>
            <div className="flex flex-col gap-1 text-xs text-muted-foreground">
              <span className="flex items-center gap-2">
                <Badge variant="secondary">{logo.sourceFormat.toUpperCase()}</Badge>
                {logo.sourceFormat === "svg" ? "Kept as vector SVG" : "Re-encoded PNG"}
              </span>
              <span>
                Email image {logo.width} × {logo.height} px PNG
              </span>
              {logo.originalName ? <span>From {logo.originalName}</span> : null}
            </div>
          </div>
        ) : (
          <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
            No logo yet. Logo blocks are left out of emails until you upload one.
          </p>
        )}
      </div>

      {uploading ? <LogoUploadDialog replacing={logo !== null} onClose={() => setUploading(false)} /> : null}

      <ConfirmDialog
        open={confirmingRemove}
        onOpenChange={(open) => !open && setConfirmingRemove(false)}
        title="Remove the organization logo?"
        description="Logo blocks in every design will be left out until a new logo is uploaded. Emails already sent keep their logo."
        confirmLabel="Remove"
        destructive
        loading={remove.isPending}
        onConfirm={() => remove.mutate(undefined, { onSettled: () => setConfirmingRemove(false) })}
      />
    </Card>
  );
}
