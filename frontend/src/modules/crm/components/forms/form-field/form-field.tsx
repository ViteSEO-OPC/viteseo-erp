import type { ReactNode } from "react";
import { useId } from "react";
import { cn } from "@/modules/crm/lib/utils";

type FormFieldProps = {
  label: string;
  required?: boolean;
  optional?: boolean;
  children: ReactNode;
  className?: string;
  /**
   * Validation message for this field. Rendering it here rather than in each form is what lets a
   * form show WHICH field is wrong instead of one sentence at the bottom of the dialog — the shared
   * slot every CRM form can now use.
   */
  error?: string;
};

export function FormField({
  label,
  required,
  optional,
  children,
  className,
  error,
}: FormFieldProps) {
  const errorId = useId();

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
      {/* `aria-invalid` + `aria-describedby` are set on the wrapper so any control shape (native
          input, native select, or a composed component like NicheSelect) is announced as invalid
          without every caller having to thread the ids through itself. */}
      <div
        aria-required={required ? true : undefined}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
      >
        {children}
      </div>
      {error ? (
        <p id={errorId} role="alert" className="text-xs text-red-500">
          {error}
        </p>
      ) : null}
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
