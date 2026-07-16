import { Construction } from "lucide-react";

import { PageHeader } from "@/core/components/page-header";

/**
 * Friendly placeholder for nav items whose feature isn't built yet (Tier-2, or
 * a Tier-1 slice not reached). Keeps the sidebar fully clickable without 404s.
 */
export function StubPage({
  title,
  note = "This section isn't part of the MVP build yet.",
}: {
  title: string;
  note?: string;
}) {
  return (
    <div className="p-8">
      <PageHeader title={title} />
      <div className="mt-8 flex flex-col items-center justify-center gap-3 rounded-card border border-dashed border-border bg-card py-20 text-center">
        <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <Construction className="size-6" />
        </span>
        <p className="text-sm font-medium text-foreground">Coming soon</p>
        <p className="max-w-xs text-sm text-muted-foreground">{note}</p>
      </div>
    </div>
  );
}
