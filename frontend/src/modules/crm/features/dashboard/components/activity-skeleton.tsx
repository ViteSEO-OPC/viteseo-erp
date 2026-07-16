import { Skeleton } from "@/modules/crm/components/ui/skeleton";

export function ActivitySkeleton() {
  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-card">
      {Array.from({ length: 4 }).map((_, i) => (
        <div
          key={i}
          className="flex items-start gap-3 border-b border-border-subtle px-4 py-3 last:border-b-0"
        >
          <Skeleton className="h-8 w-8 rounded-lg" />
          <div className="flex flex-1 items-baseline justify-between gap-2">
            <div className="space-y-1.5">
              <Skeleton className="h-4 w-48" />
            </div>
            <Skeleton className="h-3 w-14 shrink-0" />
          </div>
        </div>
      ))}
    </div>
  );
}
