import { Minus } from "lucide-react";
import { AiAvatarTile } from "@/modules/crm/features/copilot/components/ai-avatar-tile";
import type { CopilotStatus } from "@/modules/crm/features/copilot/types/copilot-types";

type Props = {
  status: CopilotStatus;
  onMinimize?: () => void;
};

export function CopilotHeader({ onMinimize }: Props) {
  return (
    <div className="relative shrink-0">
      <div className="h-1 w-full bg-gradient-to-r from-[var(--copilot-top-accent)] to-transparent" />
      <div className="flex items-start gap-3 bg-gradient-to-b from-[var(--copilot-header-gradient-from)] to-[var(--copilot-header-gradient-to)] px-4 pb-3 pt-3">
        <AiAvatarTile />
        <div className="flex-1 min-w-0">
          <h2 className="text-lg font-bold text-foreground">
            Vite AI Copilot
          </h2>
          <p className="flex items-center gap-1.5 text-xs text-teal-700">
            <span className="h-1.5 w-1.5 rounded-full bg-teal-500" aria-hidden />
            Ready to assist
          </p>
        </div>
        <button
          type="button"
          onClick={onMinimize}
          className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-200 text-gray-600 transition-colors hover:bg-gray-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          aria-label="Minimize"
        >
          <Minus className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}
