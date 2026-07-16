import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";

/** UI-first placeholder — archive backend arrives in a later milestone. */
export function LeadArchivePage() {
  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Lead Archive</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Archived leads are kept here, out of the active pipeline.
        </p>
      </header>
      <EmptyState message="No archived leads yet." />
    </div>
  );
}
