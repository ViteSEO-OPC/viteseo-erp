"use client";

import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { AlertCircle, ChevronDown, ChevronRight, ChevronUp, Copy, GripVertical, Info, Trash2 } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import {
  AlignmentControl,
  ColorControl,
  LinkControl,
  RangeControl,
  SegmentedChoice,
  TextLineControl,
} from "@/modules/crm/features/email-templates/components/designer/design-controls";
import { DesignRichText } from "@/modules/crm/features/email-templates/components/designer/design-rich-text";
import {
  BLOCK_CONTROL_FIELDS,
  BLOCK_LABELS,
  RANGES,
  TEXT_LIMITS,
  blockSummary,
  inheritedBlockColor,
  logoLinkTarget,
  optionalLink,
} from "@/modules/crm/features/email-templates/lib/email-design";
import type {
  DesignBlock,
  DesignProblem,
  DesignStyles,
  LogoBlock,
  LogoLinkTarget,
} from "@/modules/crm/features/email-templates/types/email-design-types";
import type { TemplateToken } from "@/modules/crm/features/email-templates/types/email-template-types";

const ICON_BUTTON =
  "inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-30";

const HEADING_LEVELS = ([1, 2, 3] as const).map((level) => ({ value: level, label: `H${level}`, title: `Heading ${level}` }));

const LOGO_LINK_OPTIONS = [
  { value: "website", label: "Website", title: "Link to the organization website" },
  { value: "custom", label: "Custom", title: "Link to a custom address" },
  { value: "none", label: "None", title: "No link" },
] as const satisfies readonly { value: LogoLinkTarget; label: string; title: string }[];

/** A logo pointed at `target`: the website and "none" carry no address; custom keeps the one it had. */
function withLogoLinkTarget(block: LogoBlock, target: LogoLinkTarget): LogoBlock {
  const base: LogoBlock = { type: "logo", id: block.id, width: block.width, align: block.align };
  if (target === "website") return base;
  if (target === "none") return { ...base, link_target: "none" };
  return { ...base, link_target: "custom", link_url: block.link_url ?? "" };
}

type Props = {
  block: DesignBlock;
  index: number;
  count: number;
  expanded: boolean;
  problems: readonly DesignProblem[];
  tokens: readonly TemplateToken[];
  /**
   * Where an unset block color comes from, nearest first: a template's style overrides then its design's
   * styles, or a design's own styles.
   */
  styleChain: readonly DesignStyles[];
  disabled: boolean;
  /** Duplicating is withheld when the list is full. */
  duplicateDisabled?: boolean;
  onToggle: () => void;
  onChange: (block: DesignBlock) => void;
  onMove: (direction: -1 | 1) => void;
  onDuplicate: () => void;
  onRemove: () => void;
};

/**
 * One block in the designer's list: a header to drag, reorder, duplicate, remove and expand it, and — when
 * expanded — the settings for its type. Dragging uses dnd-kit's pointer and keyboard sensors; the up/down
 * buttons are the same move for anyone who prefers them.
 */
export function DesignBlockCard({
  block,
  index,
  count,
  expanded,
  problems,
  tokens,
  styleChain,
  disabled,
  duplicateDisabled = false,
  onToggle,
  onChange,
  onMove,
  onDuplicate,
  onRemove,
}: Props) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: block.id, disabled });
  const label = BLOCK_LABELS[block.type];
  const summary = blockSummary(block);
  const bodyId = `design-block-${block.id}`;

  const error = (field: string) => problems.find((problem) => problem.field === field)?.message;
  const unplaced = problems.filter((problem) => !BLOCK_CONTROL_FIELDS[block.type].includes(problem.field));

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        "rounded-lg border bg-card",
        problems.length > 0 ? "border-destructive/60" : "border-border",
        isDragging && "relative z-10 opacity-80 shadow-lg",
      )}
    >
      <div className="flex items-center gap-1 px-1.5 py-1">
        <button
          type="button"
          {...attributes}
          {...listeners}
          disabled={disabled}
          aria-label={`Drag the ${label} block to reorder`}
          className={cn(ICON_BUTTON, "cursor-grab touch-none active:cursor-grabbing")}
        >
          <GripVertical className="size-4" aria-hidden />
        </button>

        <button
          type="button"
          onClick={onToggle}
          aria-expanded={expanded}
          aria-controls={bodyId}
          className="flex min-w-0 flex-1 items-center gap-2 rounded-md px-1 py-1 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <ChevronRight className={cn("size-4 shrink-0 text-muted-foreground transition-transform", expanded && "rotate-90")} aria-hidden />
          <span className="shrink-0 text-sm font-semibold text-foreground">{label}</span>
          {summary ? <span className="min-w-0 truncate text-xs text-muted-foreground">{summary}</span> : null}
          {problems.length > 0 ? (
            <span className="ml-auto inline-flex shrink-0 items-center gap-1 text-xs font-medium text-destructive">
              <AlertCircle className="size-3.5" aria-hidden />
              {problems.length === 1 ? "1 problem" : `${problems.length} problems`}
            </span>
          ) : null}
        </button>

        <div className="flex shrink-0 items-center">
          <button type="button" className={ICON_BUTTON} onClick={() => onMove(-1)} disabled={disabled || index === 0} aria-label={`Move the ${label} block up`}>
            <ChevronUp className="size-4" aria-hidden />
          </button>
          <button type="button" className={ICON_BUTTON} onClick={() => onMove(1)} disabled={disabled || index === count - 1} aria-label={`Move the ${label} block down`}>
            <ChevronDown className="size-4" aria-hidden />
          </button>
          <button type="button" className={ICON_BUTTON} onClick={onDuplicate} disabled={disabled || duplicateDisabled} aria-label={`Duplicate the ${label} block`}>
            <Copy className="size-4" aria-hidden />
          </button>
          <button
            type="button"
            className={cn(ICON_BUTTON, "hover:text-destructive")}
            onClick={onRemove}
            disabled={disabled}
            aria-label={`Remove the ${label} block`}
          >
            <Trash2 className="size-4" aria-hidden />
          </button>
        </div>
      </div>

      {expanded ? (
        <div id={bodyId} className="flex flex-col gap-4 border-t border-border px-4 py-4">
          {unplaced.length > 0 ? (
            <ul role="alert" className="flex flex-col gap-1 text-xs text-red-500">
              {unplaced.map((problem, i) => (
                <li key={`${problem.field}-${i}`}>{problem.message}</li>
              ))}
            </ul>
          ) : null}
          <BlockSettings block={block} onChange={onChange} tokens={tokens} styleChain={styleChain} disabled={disabled} error={error} />
        </div>
      ) : null}
    </div>
  );
}

function Note({ children }: { children: React.ReactNode }) {
  return (
    <p className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
      <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
      <span>{children}</span>
    </p>
  );
}

function BlockSettings({
  block,
  onChange,
  tokens,
  styleChain,
  disabled,
  error,
}: {
  block: DesignBlock;
  onChange: (block: DesignBlock) => void;
  tokens: readonly TemplateToken[];
  styleChain: readonly DesignStyles[];
  disabled: boolean;
  error: (field: string) => string | undefined;
}) {
  switch (block.type) {
    case "heading":
      return (
        <>
          <TextLineControl
            label="Heading"
            required
            value={block.text}
            onChange={(text) => onChange({ ...block, text })}
            tokens={tokens}
            maxLength={TEXT_LIMITS.heading}
            disabled={disabled}
            error={error("text")}
          />
          <div className="flex flex-wrap gap-4">
            <SegmentedChoice label="Size" value={block.level} options={HEADING_LEVELS} onChange={(level) => onChange({ ...block, level })} disabled={disabled} error={error("level")} />
            <AlignmentControl value={block.align} onChange={(align) => onChange({ ...block, align })} disabled={disabled} error={error("align")} />
            <ColorControl
              label="Color"
              value={block.color}
              inherited={inheritedBlockColor("heading", "color", styleChain)}
              onChange={(color) => onChange({ ...block, color })}
              disabled={disabled}
              error={error("color")}
            />
          </div>
        </>
      );

    case "text":
    case "footer":
      return (
        <>
          <div className="flex flex-col gap-1.5">
            <DesignRichText
              value={block.content}
              onChange={(content) => onChange({ ...block, content })}
              tokens={tokens}
              allowLists={block.type === "text"}
              ariaLabel={block.type === "text" ? "Text" : "Footer text"}
              placeholder={block.type === "text" ? "Write your message…" : "Company name, address or contact details…"}
              disabled={disabled}
            />
            {error("content") ? (
              <p role="alert" className="text-xs text-red-500">
                {error("content")}
              </p>
            ) : null}
          </div>
          <div className="flex flex-wrap gap-4">
            <AlignmentControl value={block.align} onChange={(align) => onChange({ ...block, align })} disabled={disabled} error={error("align")} />
            <ColorControl
              label="Text color"
              value={block.color}
              inherited={inheritedBlockColor(block.type, "color", styleChain)}
              onChange={(color) => onChange({ ...block, color })}
              disabled={disabled}
              error={error("color")}
            />
          </div>
          <RangeControl
            label="Font size"
            value={block.font_size}
            range={block.type === "text" ? RANGES.textFontSize : RANGES.footerFontSize}
            onChange={(font_size) => onChange({ ...block, font_size })}
            disabled={disabled}
            error={error("font_size")}
          />
        </>
      );

    case "button":
      return (
        <>
          <TextLineControl
            label="Button label"
            required
            value={block.label}
            onChange={(label) => onChange({ ...block, label })}
            tokens={tokens}
            maxLength={TEXT_LIMITS.buttonLabel}
            disabled={disabled}
            error={error("label")}
          />
          <LinkControl label="Link" value={block.url} onChange={(url) => onChange({ ...block, url })} disabled={disabled} error={error("url")} />
          <div className="flex flex-wrap gap-4">
            <AlignmentControl value={block.align} onChange={(align) => onChange({ ...block, align })} disabled={disabled} error={error("align")} />
            <ColorControl
              label="Button color"
              value={block.background_color}
              inherited={inheritedBlockColor("button", "background_color", styleChain)}
              onChange={(background_color) => onChange({ ...block, background_color })}
              disabled={disabled}
              error={error("background_color")}
            />
            <ColorControl
              label="Label color"
              value={block.text_color}
              inherited={inheritedBlockColor("button", "text_color", styleChain)}
              onChange={(text_color) => onChange({ ...block, text_color })}
              disabled={disabled}
              error={error("text_color")}
            />
          </div>
          <RangeControl label="Corner radius" value={block.radius} range={RANGES.buttonRadius} onChange={(radius) => onChange({ ...block, radius })} disabled={disabled} error={error("radius")} />
        </>
      );

    case "logo": {
      const target = logoLinkTarget(block);
      return (
        <>
          <Note>
            Shows your organization logo, set under Settings → Email Designs → Organization logo. The design never stores
            the image, so replacing that logo updates every design at once. Until a logo is uploaded, it is left out.
          </Note>
          <RangeControl label="Width" value={block.width} range={RANGES.logoWidth} onChange={(width) => onChange({ ...block, width })} disabled={disabled} error={error("width")} />
          <AlignmentControl value={block.align} onChange={(align) => onChange({ ...block, align })} disabled={disabled} error={error("align")} />
          <SegmentedChoice
            label="Links to"
            value={target}
            options={LOGO_LINK_OPTIONS}
            onChange={(next) => onChange(withLogoLinkTarget(block, next))}
            disabled={disabled}
            error={error("link_target")}
          />
          {target === "custom" ? (
            <LinkControl
              label="Link"
              value={block.link_url ?? ""}
              onChange={(value) => onChange({ ...block, link_target: "custom", link_url: value })}
              disabled={disabled}
              error={error("link_url")}
            />
          ) : (
            <>
              {target === "website" ? (
                <p className="text-xs text-muted-foreground">
                  Links to your organization&apos;s website once its email branding has one; otherwise the logo is not linked.
                </p>
              ) : null}
              {error("link_url") ? (
                <p role="alert" className="text-xs text-red-500">
                  {error("link_url")}
                </p>
              ) : null}
            </>
          )}
        </>
      );
    }

    case "image":
      return (
        <>
          <Note>Image uploads arrive in a later update. This image keeps its current file, which the preview cannot show yet.</Note>
          <TextLineControl
            label="Alt text"
            value={block.alt}
            onChange={(alt) => onChange({ ...block, alt })}
            maxLength={TEXT_LIMITS.alt}
            placeholder="Describe the image for readers who cannot see it"
            disabled={disabled}
            error={error("alt")}
          />
          <RangeControl label="Width" value={block.width} range={RANGES.imageWidth} onChange={(width) => onChange({ ...block, width })} disabled={disabled} error={error("width")} />
          <AlignmentControl value={block.align} onChange={(align) => onChange({ ...block, align })} disabled={disabled} error={error("align")} />
          <LinkControl
            label="Link"
            optional
            value={block.link_url ?? ""}
            onChange={(value) => onChange({ ...block, link_url: optionalLink(value) })}
            disabled={disabled}
            error={error("link_url")}
          />
        </>
      );

    case "divider":
      return (
        <div className="flex flex-col gap-4">
          <ColorControl
            label="Line color"
            value={block.color}
            inherited={inheritedBlockColor("divider", "color", styleChain)}
            onChange={(color) => onChange({ ...block, color })}
            disabled={disabled}
            error={error("color")}
          />
          <RangeControl label="Thickness" value={block.thickness} range={RANGES.dividerThickness} onChange={(thickness) => onChange({ ...block, thickness })} disabled={disabled} error={error("thickness")} />
        </div>
      );

    case "spacer":
      return <RangeControl label="Height" value={block.height} range={RANGES.spacerHeight} onChange={(height) => onChange({ ...block, height })} disabled={disabled} error={error("height")} />;
  }
}
