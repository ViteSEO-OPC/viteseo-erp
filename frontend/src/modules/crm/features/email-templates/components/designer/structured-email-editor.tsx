"use client";

import { DesignBlockList } from "@/modules/crm/features/email-templates/components/designer/design-block-list";
import { ADDABLE_BLOCKS, MAX_CONTENT_BLOCKS } from "@/modules/crm/features/email-templates/lib/email-design";
import type {
  DesignProblem,
  DesignStyles,
  TemplateContent,
} from "@/modules/crm/features/email-templates/types/email-design-types";
import type { TemplateToken } from "@/modules/crm/features/email-templates/types/email-template-types";

type Props = {
  content: TemplateContent;
  /** Receives an update, so consecutive edits compose against the latest content. */
  onChange: (update: (content: TemplateContent) => TemplateContent) => void;
  /** The server's problems, by block id. */
  problems: Record<string, DesignProblem[]>;
  tokens: readonly TemplateToken[];
  styleChain: readonly DesignStyles[];
  disabled: boolean;
  description?: string;
  initiallyExpanded?: string | null;
};

/**
 * The one structured-email editor: a template content document's blocks — headings, text, buttons, actions,
 * cards and their rows — edited with the shared block list, block cards and controls.
 *
 * Settings → Email Templates edits a reusable template's content with it; Lead Details → New designed email edits
 * one lead's copy of that content with the same component, so the two can never drift apart. It holds no state of
 * its own and never renders email HTML: previews come from the server.
 */
export function StructuredEmailEditor({
  content,
  onChange,
  problems,
  tokens,
  styleChain,
  disabled,
  description = "This template's message, between the design's header and its signature area.",
  initiallyExpanded = null,
}: Props) {
  return (
    <DesignBlockList
      title="Content"
      description={description}
      blocks={content.blocks}
      onChange={(next) => onChange((current) => ({ ...current, blocks: next(current.blocks) }))}
      addable={ADDABLE_BLOCKS.blocks}
      max={MAX_CONTENT_BLOCKS}
      problems={problems}
      tokens={tokens}
      styleChain={styleChain}
      disabled={disabled}
      emptyMessage="No content yet. Add a heading, some text or a card below."
      initiallyExpanded={initiallyExpanded}
    />
  );
}
