import { Check } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

type Step = {
  label: string;
};

type Props = {
  steps: Step[];
  current: number;
  completed?: number[];
  className?: string;
};

export function WizardStepper({ steps, current, completed, className }: Props) {
  return (
    <nav aria-label="Progress" className={cn("flex items-center", className)}>
      {steps.map((step, index) => {
        const stepNumber = index + 1;
        const isActive = stepNumber === current;
        // Explicit `completed` overrides the derived "any earlier step" behaviour.
        const isCompleted = completed
          ? completed.includes(stepNumber)
          : stepNumber < current;
        const isInactive = !isActive && !isCompleted;

        return (
          <div key={stepNumber} className="flex flex-1 items-center last:flex-none">
            <div className="flex items-center gap-2">
              <span
                className={cn(
                  "flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold transition-colors",
                  (isActive || isCompleted) && "bg-teal-400 text-white",
                  isInactive && "bg-secondary text-muted-foreground",
                )}
                aria-current={isActive ? "step" : undefined}
              >
                {isCompleted ? (
                  <Check className="h-3.5 w-3.5" strokeWidth={3} aria-hidden />
                ) : (
                  stepNumber
                )}
              </span>
              <span
                className={cn(
                  "whitespace-nowrap text-sm transition-colors",
                  isActive && "font-semibold text-teal-700",
                  isCompleted && "font-medium text-card-foreground",
                  isInactive && "text-muted-foreground",
                )}
              >
                {step.label}
              </span>
            </div>

            {index < steps.length - 1 && (
              <div className="mx-3 h-px flex-1 bg-secondary" />
            )}
          </div>
        );
      })}
    </nav>
  );
}
