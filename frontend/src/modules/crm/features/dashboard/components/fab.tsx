import { Sparkles } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

type FabProps = {
  className?: string;
};

export function Fab({ className }: FabProps) {
  return (
    <button
      type="button"
      className={cn(
        "fixed bottom-6 right-6 z-50 flex h-12 w-12 items-center justify-center rounded-full bg-primary text-card-foreground shadow-fab transition-colors",
        "hover:bg-teal-500",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
        className,
      )}
      aria-label="Open AI assistant"
    >
      <Sparkles className="h-5 w-5" />
    </button>
  );
}
