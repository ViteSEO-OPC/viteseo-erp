type NavGroupLabelProps = {
  label: string;
  collapsed?: boolean;
};

export function NavGroupLabel({ label, collapsed }: NavGroupLabelProps) {
  if (collapsed) return null;

  return (
    <div className="px-4 pb-1 pt-4">
      <span className="text-xs font-semibold uppercase tracking-[0.08em] text-sidebar-foreground/60">
        {label}
      </span>
    </div>
  );
}
