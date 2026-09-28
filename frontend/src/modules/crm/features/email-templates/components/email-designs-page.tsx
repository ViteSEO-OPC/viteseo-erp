"use client";

import { useState } from "react";
import dynamic from "next/dynamic";
import { CheckCheck, Loader2, Pencil, Plus, Trash2 } from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Button } from "@/modules/crm/components/ui/button";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { ApplyToAllDialog } from "@/modules/crm/features/email-templates/components/apply-to-all-dialog";
import { OrganizationLogoCard } from "@/modules/crm/features/email-templates/components/branding/organization-logo-card";
import {
  useDeleteEmailDesign,
  useEmailDesigns,
  useUpdateEmailDesign,
} from "@/modules/crm/features/email-templates/hooks/use-email-designs";
import { useEmailTemplatesAdmin } from "@/modules/crm/features/email-templates/hooks/use-email-templates-admin";
import type { EmailDesign, EmailDesignLayout } from "@/modules/crm/features/email-templates/types/email-design-types";

/**
 * The design editor — Tiptap, dnd-kit and the preview frame — is loaded only when an admin opens it, so none
 * of it weighs on the list.
 */
const EmailDesignEditor = dynamic(
  () =>
    import("@/modules/crm/features/email-templates/components/designer/email-design-editor").then(
      (mod) => mod.EmailDesignEditor,
    ),
  {
    ssr: false,
    loading: () => (
      <div role="status" className="fixed inset-0 z-50 flex items-center justify-center bg-black/10">
        <span className="inline-flex items-center gap-2 rounded-lg bg-card px-4 py-2 text-sm text-foreground shadow-lg">
          <Loader2 className="size-4 animate-spin" aria-hidden />
          Opening the design editor…
        </span>
      </div>
    ),
  },
);

const NO_TOKENS: never[] = [];

function plural(count: number, word: string): string {
  return `${count} ${word}${count === 1 ? "" : "s"}`;
}

function layoutSummary(layout: EmailDesignLayout): string {
  return [
    `Header: ${plural(layout.header.length, "block")}`,
    layout.signature.enabled ? "Signature area" : "No signature area",
    `Footer: ${plural(layout.footer.length, "block")}`,
  ].join(" · ");
}

/**
 * Settings → Email Designs: the reusable looks designed email templates are shown in — page style, header,
 * signature area and footer. Many templates can use one design, and a change to it reaches all of them.
 *
 * The server creates the organization's default design the first time the list loads, never deletes it, and
 * refuses to delete a design templates still use. This page shows those rules — no delete for the default, a
 * disabled delete while a design is in use — and the server's refusal if they are hit anyway.
 */
export function EmailDesignsPage() {
  const { designs, isLoading, isError, retry } = useEmailDesigns();
  const { catalog } = useEmailTemplatesAdmin();
  const remove = useDeleteEmailDesign();
  const update = useUpdateEmailDesign();

  const [editing, setEditing] = useState<{ design: EmailDesign | null } | null>(null);
  const [pendingDelete, setPendingDelete] = useState<EmailDesign | null>(null);
  const [applying, setApplying] = useState<EmailDesign | null>(null);

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-1">
          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h2 className="text-2xl font-bold text-foreground">Email Designs</h2>
            {!isLoading && !isError ? (
              <span className="text-sm font-medium text-muted-foreground">{plural(designs.length, "design")}</span>
            ) : null}
          </div>
          <p className="text-sm text-muted-foreground">
            The page style, header, signature area and footer that designed email templates are shown in. Several
            templates can share one design, and changing it changes all of them.
          </p>
        </div>
        <Button className="shrink-0" onClick={() => setEditing({ design: null })} disabled={isLoading || isError}>
          <Plus aria-hidden />
          New design
        </Button>
      </header>

      <OrganizationLogoCard designs={designs} />

      <Card className="border border-border bg-card p-0 shadow-card">
        {isError ? (
          <ErrorState className="p-6" message="Could not load email designs." onRetry={() => void retry()} />
        ) : isLoading ? (
          <div className="flex flex-col gap-3 p-6">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        ) : (
          <ul className="divide-y divide-border">
            {designs.map((design) => (
              <li key={design.id} className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 flex-col gap-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <button
                      type="button"
                      onClick={() => setEditing({ design })}
                      className="min-w-0 truncate rounded-sm text-left text-sm font-semibold text-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      {design.name}
                    </button>
                    {design.isDefault ? <Badge variant="secondary">Default</Badge> : null}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {design.templatesCount === 0
                      ? "Not used by any template"
                      : `Used by ${plural(design.templatesCount, "template")}`}
                    {" · "}
                    {layoutSummary(design.layout)}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <Button variant="outline" size="sm" onClick={() => setApplying(design)}>
                    <CheckCheck aria-hidden />
                    Apply to all templates
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => setEditing({ design })}>
                    <Pencil aria-hidden />
                    Edit
                  </Button>
                  {design.isDefault ? null : (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setPendingDelete(design)}
                      disabled={design.templatesCount > 0}
                      title={
                        design.templatesCount > 0
                          ? "Choose another design for the templates that use this one before deleting it"
                          : undefined
                      }
                    >
                      <Trash2 aria-hidden />
                      Delete
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {editing ? (
        <EmailDesignEditor
          key={editing.design?.id ?? "new"}
          design={editing.design}
          tokens={catalog?.tokens ?? NO_TOKENS}
          onClose={() => setEditing(null)}
        />
      ) : null}

      <ApplyToAllDialog
        open={applying !== null}
        designId={applying?.id ?? null}
        designName={applying?.name ?? ""}
        loading={update.isPending}
        onCancel={() => setApplying(null)}
        onConfirm={(count) =>
          applying &&
          update.mutate({ id: applying.id, apply_to_all: true, apply_to_all_count: count }, { onSettled: () => setApplying(null) })
        }
      />

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && setPendingDelete(null)}
        title="Delete this design?"
        description={
          pendingDelete
            ? `“${pendingDelete.name}” will no longer be offered to templates. It is kept on record but cannot be restored from this screen.`
            : undefined
        }
        confirmLabel="Delete"
        destructive
        loading={remove.isPending}
        onConfirm={() => pendingDelete && remove.mutate(pendingDelete.id, { onSettled: () => setPendingDelete(null) })}
      />
    </div>
  );
}
