import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";

/** UI-first placeholder — restore backend arrives in a later milestone. */
export function RestoreArchivedLeadsPage() {
  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Restore Archived Leads</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Bring archived or deleted leads back into the active pipeline.
        </p>
      </header>
      <EmptyState message="Nothing to restore right now." />
    </div>
  );
}
