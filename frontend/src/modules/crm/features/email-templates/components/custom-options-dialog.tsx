"use client";

import { useState } from "react";
import { Loader2, Plus, Trash2 } from "lucide-react";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Button } from "@/modules/crm/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/modules/crm/components/ui/dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { AddCustomOptionDialog } from "@/modules/crm/features/email-templates/components/add-custom-option-dialog";
import { useDeleteTemplateOption, useTemplateOptions } from "@/modules/crm/features/email-templates/hooks/use-email-template-options";
import type { TemplateOptionType } from "@/modules/crm/features/email-templates/types/email-template-types";

const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? "" : "s"}`;

type Row = { value: string; optionId?: string | null; templatesCount: number | null };

/**
 * Settings → Email Templates → Custom purposes & tones: the organization's own values, how many templates use each,
 * and adding or deleting them. A value a template still uses cannot be deleted (the server refuses too); values are
 * not renamed.
 */
export function CustomOptionsDialog({ onClose }: { onClose: () => void }) {
  const options = useTemplateOptions(true);
  const remove = useDeleteTemplateOption();
  const [adding, setAdding] = useState<TemplateOptionType | null>(null);
  const [confirming, setConfirming] = useState<string | null>(null);

  const data = options.data;
  const sections: { type: TemplateOptionType; title: string; rows: Row[]; all: string[] }[] = data
    ? [
        {
          type: "purpose",
          title: "Custom purposes",
          rows: data.purposes.filter((purpose) => purpose.custom),
          all: data.purposes.map((purpose) => purpose.value),
        },
        { type: "tone", title: "Custom tones", rows: data.tones.filter((tone) => tone.custom), all: data.tones.map((tone) => tone.value) },
      ]
    : [];

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Custom purposes &amp; tones</DialogTitle>
          <DialogDescription>
            Your organization&apos;s own values, offered beside the built-in ones in every template editor. Templates with a
            custom purpose appear in the Outreach composer like any other.
          </DialogDescription>
        </DialogHeader>

        {options.isError ? (
          <ErrorState message="Could not load the custom values." onRetry={() => void options.refetch()} />
        ) : !data ? (
          <p role="status" className="inline-flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" aria-hidden />
            Loading…
          </p>
        ) : (
          sections.map((section) => (
            <section key={section.type} aria-label={section.title} className="flex flex-col gap-2">
              <div className="flex items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-foreground">{section.title}</h3>
                <Button type="button" size="xs" variant="outline" onClick={() => setAdding(section.type)}>
                  <Plus aria-hidden />
                  Add custom {section.type}
                </Button>
              </div>
              {section.rows.length === 0 ? (
                <p className="rounded-lg border border-dashed border-border px-3 py-3 text-center text-xs text-muted-foreground">
                  No custom {section.type}s yet.
                </p>
              ) : (
                <ul className="divide-y divide-border rounded-lg border border-border">
                  {section.rows.map((row) => {
                    const used = row.templatesCount ?? 0;
                    const id = row.optionId ?? "";
                    return (
                      <li key={id} data-option-row={row.value} className="flex items-center justify-between gap-2 px-3 py-2">
                        <div className="flex min-w-0 items-center gap-2">
                          <span className="truncate text-sm font-medium text-foreground">{row.value}</span>
                          <Badge variant="secondary">Custom</Badge>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                          <span className="text-xs text-muted-foreground">{used === 0 ? "Not used" : `Used by ${plural(used, "template")}`}</span>
                          {confirming === id ? (
                            <Button
                              type="button"
                              size="xs"
                              variant="destructive"
                              disabled={remove.isPending}
                              onClick={() => remove.mutate(id, { onSettled: () => setConfirming(null) })}
                            >
                              Confirm delete
                            </Button>
                          ) : (
                            <Button
                              type="button"
                              size="xs"
                              variant="ghost"
                              disabled={used > 0 || remove.isPending}
                              title={used > 0 ? `Give ${used === 1 ? "that template" : "those templates"} a different ${section.type} first` : undefined}
                              aria-label={`Delete custom ${section.type} ${row.value}`}
                              onClick={() => setConfirming(id)}
                            >
                              <Trash2 aria-hidden />
                            </Button>
                          )}
                        </div>
                      </li>
                    );
                  })}
                </ul>
              )}
            </section>
          ))
        )}

        {adding && data ? (
          <AddCustomOptionDialog
            type={adding}
            existing={sections.find((section) => section.type === adding)?.all ?? []}
            onClose={() => setAdding(null)}
            onCreated={() => undefined}
          />
        ) : null}
      </DialogContent>
    </Dialog>
  );
}
