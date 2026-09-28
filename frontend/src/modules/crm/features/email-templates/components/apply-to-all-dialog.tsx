"use client";

import { Loader2 } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { useApplyToAllSummary } from "@/modules/crm/features/email-templates/hooks/use-email-designs";

const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? "" : "s"}`;

type Props = {
  open: boolean;
  /** The design being applied; null for one that is not saved yet. */
  designId: string | null;
  designName: string;
  loading: boolean;
  onCancel: () => void;
  /** Receives the number of designed templates the admin confirmed; the server refuses the change if it differs. */
  onConfirm: (count: number) => void;
};

/**
 * The confirmation for "Apply to all templates". It fetches the counts fresh from the server and states them
 * before anything changes: how many designed templates will use the design, how many of those switch from
 * another design, and that plain-text templates are left as they are.
 */
export function ApplyToAllDialog({ open, designId, designName, loading, onCancel, onConfirm }: Props) {
  const summary = useApplyToAllSummary(designId, open);
  const data = summary.data;

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => !next && onCancel()}
      title="Apply to all designed templates?"
      description={`Every template built with the visual designer will use “${designName}”. The design is stored once; templates only reference it.`}
      confirmLabel={data ? `Apply to ${plural(data.designedTemplates, "template")}` : "Apply"}
      loading={loading}
      confirmDisabled={!data || data.designedTemplates === 0 || summary.isFetching}
      onConfirm={() => data && onConfirm(data.designedTemplates)}
    >
      {summary.isFetching && !data ? (
        <p role="status" className="inline-flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="size-4 animate-spin" aria-hidden />
          Counting templates…
        </p>
      ) : summary.isError ? (
        <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
          Could not count the templates.
          <Button type="button" size="xs" variant="outline" onClick={() => void summary.refetch()}>
            Try again
          </Button>
        </div>
      ) : data ? (
        <ul aria-label="What will change" className="flex list-disc flex-col gap-1 pl-5 text-sm text-foreground">
          {data.designedTemplates === 0 ? (
            <li>There are no designed templates yet, so nothing would change.</li>
          ) : (
            <>
              <li>
                <strong>{plural(data.designedTemplates, "designed template")}</strong> will use this design, including inactive
                ones.
              </li>
              {data.switching > 0 ? (
                <li>
                  <strong>{data.switching}</strong> currently {data.switching === 1 ? "uses" : "use"} another design and will switch
                  to this one.
                </li>
              ) : null}
              {data.alreadyUsing > 0 ? <li>{data.alreadyUsing} already {data.alreadyUsing === 1 ? "uses" : "use"} it.</li> : null}
            </>
          )}
          <li>
            {plural(data.plainTextTemplates, "plain-text template")} will not change. Plain-text templates are never converted
            automatically.
          </li>
        </ul>
      ) : null}
    </ConfirmDialog>
  );
}
