import { cn } from "@/modules/crm/lib/utils";

type EmptyStateProps = {
  message: string;
  className?: string;
};

export function EmptyState({ message, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        "flex items-center justify-center rounded-lg border border-dashed border-border py-12",
        className,
      )}
    >
      <p className="text-sm text-muted-foreground">{message}</p>
    </div>
  );
}
