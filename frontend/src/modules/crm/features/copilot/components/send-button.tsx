import { Send } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

type Props = {
  onClick?: () => void;
  disabled?: boolean;
  className?: string;
};

export function SendButton({ onClick, disabled, className }: Props) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        "flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary text-white transition-colors",
        "hover:bg-teal-500",
        "disabled:cursor-not-allowed disabled:opacity-50",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        className,
      )}
      aria-label="Send message"
    >
      <Send className="h-4 w-4" />
    </button>
  );
}
