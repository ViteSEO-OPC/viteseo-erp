import { Skeleton } from "@/modules/crm/components/ui/skeleton";

export function TableSkeleton() {
  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-card">
      <div className="border-b border-border-subtle bg-surface-table-header px-4 py-3">
        <div className="flex gap-8">
          <Skeleton className="h-3 w-20" />
          <Skeleton className="h-3 w-24" />
          <Skeleton className="h-3 w-10" />
          <Skeleton className="h-3 w-12" />
          <Skeleton className="h-3 w-14" />
        </div>
      </div>
      {Array.from({ length: 4 }).map((_, i) => (
        <div
          key={i}
          className="flex items-center gap-8 border-b border-border-subtle px-4 py-3 last:border-b-0"
        >
          <Skeleton className="h-4 w-32" />
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-4 w-8" />
          <Skeleton className="h-5 w-14 rounded-full" />
          <Skeleton className="h-5 w-20 rounded-full" />
        </div>
      ))}
    </div>
  );
}
