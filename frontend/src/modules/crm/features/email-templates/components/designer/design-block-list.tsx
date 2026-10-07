"use client";

import { useId, useMemo, useState } from "react";
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core";
import { SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { Plus } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { DesignBlockCard } from "@/modules/crm/features/email-templates/components/designer/design-block-card";
import {
  BLOCK_LABELS,
  blockCount,
  blockIds,
  createBlock,
  duplicateBlock,
  moveBlock,
  newBlockId,
  removeBlock,
  replaceBlock,
} from "@/modules/crm/features/email-templates/lib/email-design";
import type {
  DesignBlock,
  DesignBlockType,
  DesignProblem,
  DesignStyles,
} from "@/modules/crm/features/email-templates/types/email-design-types";
import type { TemplateToken } from "@/modules/crm/features/email-templates/types/email-template-types";

const NO_IDS: ReadonlySet<string> = new Set();

type Props = {
  title: string;
  description?: string;
  blocks: DesignBlock[];
  /** Receives an update, so consecutive edits compose against the latest list. */
  onChange: (update: (blocks: DesignBlock[]) => DesignBlock[]) => void;
  /** The block types "Add block" offers here. */
  addable: readonly DesignBlockType[];
  max: number;
  /** Block ids used elsewhere in the same document, which a new or duplicated block must not reuse. */
  otherIds?: ReadonlySet<string>;
  /** The server's problems, by block id. */
  problems: Record<string, DesignProblem[]>;
  tokens: readonly TemplateToken[];
  styleChain: readonly DesignStyles[];
  disabled: boolean;
  emptyMessage: string;
  initiallyExpanded?: string | null;
  /** A card's own list: a smaller heading, inside its card's settings. */
  nested?: boolean;
};

/**
 * One ordered list of blocks — a design's header or footer, a template's content, or a card's own blocks —
 * with its own drag context, its expanded block, and the Add buttons for the block types that part of the email
 * may hold. Every change goes out through `onChange`; the list holds no document state of its own.
 *
 * A card's settings hold another of these lists for the card's blocks, so cards are edited with the very same
 * list, cards and controls as everything else. The count against `max` includes a card's blocks, as the
 * server's content limit does, and every new id avoids every id in the document.
 */
export function DesignBlockList({
  title,
  description,
  blocks,
  onChange,
  addable,
  max,
  otherIds = NO_IDS,
  problems,
  tokens,
  styleChain,
  disabled,
  emptyMessage,
  initiallyExpanded = null,
  nested = false,
}: Props) {
  const headingId = useId();
  const [expandedId, setExpandedId] = useState<string | null>(initiallyExpanded);
  const count = blockCount(blocks);
  const full = count >= max;
  // Every id this list may not reuse: the rest of the document's, and its own (cards' blocks included).
  const takenIds = useMemo(() => new Set([...otherIds, ...blockIds(blocks)]), [otherIds, blocks]);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  function add(type: DesignBlockType) {
    if (full) return;
    const id = newBlockId(type, takenIds);
    const block = createBlock(type, id);
    // A new card's first block derives its id from the card's; make sure nothing else uses it either.
    if (block.type === "card") {
      block.blocks = block.blocks.map((inner) => ({ ...inner, id: newBlockId(inner.type, new Set([...takenIds, id])) }));
    }
    onChange((current) => [...current, block]);
    setExpandedId(id);
  }

  function duplicate(id: string) {
    if (full) return;
    const result = duplicateBlock(blocks, id, { taken: otherIds });
    if (result.id === null) return;
    onChange(() => result.blocks);
    setExpandedId(result.id);
  }

  function onDragEnd({ active, over }: DragEndEvent) {
    if (!over || active.id === over.id) return;
    onChange((current) =>
      moveBlock(
        current,
        current.findIndex((block) => block.id === active.id),
        current.findIndex((block) => block.id === over.id),
      ),
    );
  }

  return (
    <section aria-labelledby={headingId} className="flex flex-col gap-3">
      <div className="flex items-baseline justify-between gap-2">
        <div className="flex flex-col gap-0.5">
          <h3 id={headingId} className={nested ? "text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground" : "text-sm font-semibold text-foreground"}>
            {title}
          </h3>
          {description ? <p className="text-xs text-muted-foreground">{description}</p> : null}
        </div>
        <span className="shrink-0 text-xs text-muted-foreground">
          {count} of {max}
        </span>
      </div>

      {blocks.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">{emptyMessage}</p>
      ) : (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
          <SortableContext items={blocks.map((block) => block.id)} strategy={verticalListSortingStrategy}>
            <div className="flex flex-col gap-2">
              {blocks.map((block, index) => (
                <DesignBlockCard
                  key={block.id}
                  block={block}
                  index={index}
                  count={blocks.length}
                  expanded={expandedId === block.id}
                  problems={problems[block.id] ?? []}
                  allProblems={problems}
                  takenIds={takenIds}
                  tokens={tokens}
                  styleChain={styleChain}
                  disabled={disabled}
                  duplicateDisabled={full}
                  onToggle={() => setExpandedId((current) => (current === block.id ? null : block.id))}
                  onChange={(next) => onChange((current) => replaceBlock(current, next))}
                  onMove={(direction) => onChange((current) => moveBlock(current, index, index + direction))}
                  onDuplicate={() => duplicate(block.id)}
                  onRemove={() => onChange((current) => removeBlock(current, block.id))}
                />
              ))}
            </div>
          </SortableContext>
        </DndContext>
      )}

      <div className="flex flex-wrap items-center gap-1.5">
        <span className="mr-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">Add</span>
        {addable.map((type) => (
          <Button key={type} type="button" variant="outline" size="sm" onClick={() => add(type)} disabled={disabled || full}>
            <Plus aria-hidden />
            {BLOCK_LABELS[type]}
          </Button>
        ))}
      </div>
    </section>
  );
}
