import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";

/** UI-first placeholder — Recycle Bin backend arrives in a later milestone. */
export function RecycleBinPage() {
  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Recycle Bin</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Deleted leads land here and can be restored before they&rsquo;re gone for good.
        </p>
      </header>
      <EmptyState message="The Recycle Bin is empty." />
    </div>
  );
}
