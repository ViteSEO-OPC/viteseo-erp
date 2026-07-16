import { Sparkles } from "lucide-react";
import type { SuggestedPrompt } from "@/modules/crm/features/copilot/types/copilot-types";

type Props = {
  prompts: SuggestedPrompt[];
  onSelect?: (prompt: SuggestedPrompt) => void;
};

export function SuggestedPromptChips({ prompts, onSelect }: Props) {
  return (
    <div className="grid grid-cols-2 gap-2">
      {prompts.map((prompt) => (
        <button
          key={prompt.id}
          type="button"
          onClick={() => onSelect?.(prompt)}
          className="inline-flex items-center gap-2 rounded-full border border-[var(--copilot-chip-border)] bg-[var(--copilot-chip-bg)] px-3 py-2 text-xs font-medium text-teal-700 transition-colors hover:bg-teal-400/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <Sparkles className="h-3 w-3 shrink-0 text-teal-700" />
          <span className="text-left leading-snug">{prompt.label}</span>
        </button>
      ))}
    </div>
  );
}
