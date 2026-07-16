import type { ReactNode } from "react";
import { cn } from "@/modules/crm/lib/utils";

type FormFieldProps = {
  label: string;
  required?: boolean;
  optional?: boolean;
  children: ReactNode;
  className?: string;
};

export function FormField({ label, required, optional, children, className }: FormFieldProps) {
  return (
    <div className={cn("flex flex-col gap-1.5", className)}>
      <label className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
        {label}
        {required && (
          <span className="ml-0.5 text-red-500" aria-hidden>*</span>
        )}
        {optional && !required && (
          <span className="font-normal text-muted-foreground lowercase"> (optional)</span>
        )}
      </label>
      <div aria-required={required ? true : undefined}>
        {children}
      </div>
    </div>
  );
}

type FormFieldGridProps = {
  children: ReactNode;
  className?: string;
};

export function FormFieldGrid({ children, className }: FormFieldGridProps) {
  return (
    <div className={cn("grid grid-cols-1 gap-4 sm:grid-cols-2", className)}>
      {children}
    </div>
  );
}
