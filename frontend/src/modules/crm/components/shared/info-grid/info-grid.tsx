import type { ReactNode } from "react";
import { cn } from "@/modules/crm/lib/utils";

type InfoFieldProps = {
  label: string;
  /** Optional leading icon rendered to the left of the label/value stack. */
  icon?: ReactNode;
  children: ReactNode;
  className?: string;
};

export function InfoField({ label, icon, children, className }: InfoFieldProps) {
  const inner = (
    <>
      <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
        {label}
      </span>
      <span className="text-sm text-foreground">{children}</span>
    </>
  );

  if (icon) {
    return (
      <div className={cn("flex items-center gap-3", className)}>
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-600 dark:bg-teal-400/10 dark:text-teal-400 [&_svg]:h-[18px] [&_svg]:w-[18px]">
          {icon}
        </span>
        <div className="flex min-w-0 flex-col gap-0.5">{inner}</div>
      </div>
    );
  }

  return (
    <div className={cn("flex flex-col gap-0.5", className)}>{inner}</div>
  );
}

type InfoGridProps = {
  children: ReactNode;
  className?: string;
};

export function InfoGrid({ children, className }: InfoGridProps) {
  return (
    <div className={cn("grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3", className)}>
      {children}
    </div>
  );
}
