type NavCountBadgeProps = {
  count: number;
  collapsed?: boolean;
};

export function NavCountBadge({ count, collapsed }: NavCountBadgeProps) {
  if (collapsed) {
    return (
      <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60" />
    );
  }

  return (
    <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-secondary-foreground">
      {count}
    </span>
  );
}
