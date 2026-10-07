"use client";

import { FileText, Send as SendIcon, Trash2 } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import { EmptyPanel } from "@/modules/crm/features/follow-ups/components/empty-panel";
import type { DraftEmail } from "@/modules/crm/features/follow-ups/hooks/use-outreach-mutations";

type DraftsListProps = {
  drafts: DraftEmail[];
  isLoading?: boolean;
  onOpen: (draft: DraftEmail) => void;
  onSend: (id: string) => void;
  onDiscard: (id: string) => void;
  /** Id of the draft with an in-flight send/discard, to disable its row actions. */
  pendingId?: string | null;
};

export function DraftsList({ drafts, isLoading, onOpen, onSend, onDiscard, pendingId }: DraftsListProps) {
  if (!isLoading && drafts.length === 0) {
    return <EmptyPanel icon={FileText} message="No drafts yet." />;
  }

  return (
    <section
      className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-card"
      aria-label="Drafts"
    >
      <div className="flex items-center gap-2 border-b border-border-subtle px-4 py-4 md:px-6">
        <SectionHeader title="Drafts" className="mb-0" />
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto" role="list">
        {drafts.map((draft) => {
          const pending = pendingId === draft.id;
          // A structured draft (New designed email) is never flattened into the plain-text composer.
          const structured = draft.content != null;
          const summary = (
            <>
              <span className="block truncate text-sm font-bold text-foreground">
                {draft.subject || "(No subject)"}
              </span>
              <p className="truncate text-[11px] text-muted-foreground">To: {draft.to || "—"}</p>
              <p className="mt-1 truncate text-[12px] text-secondary-foreground">
                {structured ? "Designed email · edit it in Lead Details" : draft.body || "No content yet."}
              </p>
            </>
          );
          return (
            <div
              key={draft.id}
              role="listitem"
              className="flex items-start gap-3 border-b border-border-subtle px-4 py-3.5 md:px-6"
            >
              {structured ? (
                <div className="min-w-0 flex-1">{summary}</div>
              ) : (
                <button
                  type="button"
                  onClick={() => onOpen(draft)}
                  className="min-w-0 flex-1 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                >
                  {summary}
                </button>
              )}
              <div className="flex shrink-0 gap-2">
                <Button variant="outline" size="sm" onClick={() => onSend(draft.id)} disabled={pending} aria-label="Send">
                  <SendIcon className="h-3.5 w-3.5" />
                  <span className="hidden md:inline">Send</span>
                </Button>
                <Button variant="outline" size="sm" onClick={() => onDiscard(draft.id)} disabled={pending} aria-label="Discard">
                  <Trash2 className="h-3.5 w-3.5" />
                  <span className="hidden md:inline">Discard</span>
                </Button>
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}
