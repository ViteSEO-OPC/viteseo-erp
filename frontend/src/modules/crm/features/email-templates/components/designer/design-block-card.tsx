"use client";

import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { AlertCircle, ChevronDown, ChevronRight, ChevronUp, Copy, GripVertical, Info, Plus, Trash2 } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { Button } from "@/modules/crm/components/ui/button";
import {
  AlignmentControl,
  ColorControl,
  LinkControl,
  OptionalRangeControl,
  RangeControl,
  SegmentedChoice,
  SwitchControl,
  TextLineControl,
} from "@/modules/crm/features/email-templates/components/designer/design-controls";
import { DesignBlockList } from "@/modules/crm/features/email-templates/components/designer/design-block-list";
import { DesignRichText } from "@/modules/crm/features/email-templates/components/designer/design-rich-text";
import {
  ADDABLE_CARD_BLOCKS,
  BLOCK_CONTROL_FIELDS,
  BLOCK_LABELS,
  HEADING_SIZES,
  MAX_ACTION_ITEMS,
  MAX_CARD_BLOCKS,
  MAX_ROWS_ITEMS,
  RANGES,
  TEXT_LIMITS,
  blockSummary,
  inheritedBlockColor,
  inheritedButtonColor,
  logoLinkTarget,
  optionalLink,
  withCardText,
  withRowDescription,
} from "@/modules/crm/features/email-templates/lib/email-design";
import type {
  ActionItem,
  ActionsBlock,
  ButtonBlock,
  ButtonVariant,
  CardBlock,
  DesignBlock,
  DesignProblem,
  DesignStyles,
  LogoBlock,
  LogoLinkTarget,
  RowsBlock,
} from "@/modules/crm/features/email-templates/types/email-design-types";
import type { TemplateToken } from "@/modules/crm/features/email-templates/types/email-template-types";

const ICON_BUTTON =
  "inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-30";

const HEADING_LEVELS = ([1, 2, 3] as const).map((level) => ({ value: level, label: `H${level}`, title: `Heading ${level}` }));

const BUTTON_VARIANT_OPTIONS = [
  { value: "primary", label: "Primary", title: "The design's button colors" },
  { value: "secondary", label: "Secondary", title: "The design's secondary button colors" },
] as const satisfies readonly { value: ButtonVariant; label: string; title: string }[];

const ACTION_KIND_OPTIONS = [
  { value: "button", label: "Button", title: "A button" },
  { value: "link", label: "Link", title: "A text link" },
] as const satisfies readonly { value: ActionItem["kind"]; label: string; title: string }[];

/** An item switched to `kind`: its label and link stay, and any button style is dropped (a link has none). */
function withActionKind(item: ActionItem, kind: ActionItem["kind"]): ActionItem {
  return { kind, label: item.label, url: item.url };
}

/** Whether a problem's field is one this block's panel shows: the field itself, or a part of it (`items.2.title`). */
function isPlaced(type: DesignBlock["type"], field: string): boolean {
  return BLOCK_CONTROL_FIELDS[type].some((control) => field === control || field.startsWith(`${control}.`));
}

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
  /** Every block's problems, by id — a card hands them to its own blocks. */
  allProblems: Record<string, DesignProblem[]>;
  /** Every id in the document, which a card's new or duplicated block must not reuse. */
  takenIds: ReadonlySet<string>;
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
  allProblems,
  takenIds,
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
  const unplaced = problems.filter((problem) => !isPlaced(block.type, problem.field));

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
          <BlockSettings
            block={block}
            onChange={onChange}
            tokens={tokens}
            styleChain={styleChain}
            disabled={disabled}
            error={error}
            allProblems={allProblems}
            takenIds={takenIds}
          />
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
  allProblems,
  takenIds,
}: {
  block: DesignBlock;
  onChange: (block: DesignBlock) => void;
  tokens: readonly TemplateToken[];
  styleChain: readonly DesignStyles[];
  disabled: boolean;
  error: (field: string) => string | undefined;
  allProblems: Record<string, DesignProblem[]>;
  takenIds: ReadonlySet<string>;
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
          <OptionalRangeControl
            label="Custom font size"
            value={block.font_size}
            inherited={HEADING_SIZES[block.level]}
            range={RANGES.headingFontSize}
            onChange={(font_size) => {
              const next = { ...block, font_size };
              if (font_size === undefined) delete next.font_size;
              onChange(next);
            }}
            disabled={disabled}
            error={error("font_size")}
          />
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
          <SegmentedChoice
            label="Style"
            value={block.variant ?? "primary"}
            options={BUTTON_VARIANT_OPTIONS}
            onChange={(variant) => {
              // Primary is the default: it is stored as no variant at all, as every button before variants was.
              const next: ButtonBlock = { ...block, variant };
              if (variant === "primary") delete next.variant;
              onChange(next);
            }}
            disabled={disabled}
            error={error("variant")}
          />
          <div className="flex flex-wrap gap-4">
            <AlignmentControl value={block.align} onChange={(align) => onChange({ ...block, align })} disabled={disabled} error={error("align")} />
            <ColorControl
              label="Button color"
              value={block.background_color}
              inherited={inheritedButtonColor(block, "background_color", styleChain)}
              onChange={(background_color) => onChange({ ...block, background_color })}
              disabled={disabled}
              error={error("background_color")}
            />
            <ColorControl
              label="Label color"
              value={block.text_color}
              inherited={inheritedButtonColor(block, "text_color", styleChain)}
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

    case "card":
      return (
        <CardSettings
          block={block}
          onChange={onChange}
          tokens={tokens}
          styleChain={styleChain}
          disabled={disabled}
          error={error}
          allProblems={allProblems}
          takenIds={takenIds}
        />
      );

    case "rows":
      return <RowsSettings block={block} onChange={onChange} tokens={tokens} styleChain={styleChain} disabled={disabled} error={error} />;

    case "actions":
      return <ActionsSettings block={block} onChange={onChange} tokens={tokens} disabled={disabled} error={error} />;
  }
}

/**
 * An actions row: up to three buttons and links side by side. What each says and where it goes is the template's;
 * button colors are the design's (accent, or secondary), and links follow its link color and underline setting.
 */
function ActionsSettings({
  block,
  onChange,
  tokens,
  disabled,
  error,
}: {
  block: ActionsBlock;
  onChange: (block: DesignBlock) => void;
  tokens: readonly TemplateToken[];
  disabled: boolean;
  error: (field: string) => string | undefined;
}) {
  const items = block.items;
  const full = items.length >= MAX_ACTION_ITEMS;
  const setItems = (next: ActionItem[]) => onChange({ ...block, items: next });
  const setItem = (index: number, item: ActionItem) => setItems(items.map((current, i) => (i === index ? item : current)));

  function move(index: number, direction: -1 | 1) {
    const to = index + direction;
    if (to < 0 || to >= items.length) return;
    const next = [...items];
    [next[index], next[to]] = [next[to], next[index]];
    setItems(next);
  }

  return (
    <>
      <div className="flex flex-col gap-2">
        <div className="flex items-baseline justify-between">
          <span className="text-sm font-medium text-foreground">Buttons and links</span>
          <span className="text-xs text-muted-foreground">
            {items.length} of {MAX_ACTION_ITEMS}
          </span>
        </div>
        <p className="text-xs text-muted-foreground">Shown side by side, and one under another on a narrow screen.</p>
        {items.map((item, index) => (
          <div key={index} className="flex flex-col gap-2 rounded-lg border border-border p-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-muted-foreground">
                {item.kind === "button" ? "Button" : "Link"} {index + 1}
              </span>
              <div className="flex items-center">
                <button type="button" className={ICON_BUTTON} onClick={() => move(index, -1)} disabled={disabled || index === 0} aria-label={`Move item ${index + 1} left`}>
                  <ChevronUp className="size-4" aria-hidden />
                </button>
                <button type="button" className={ICON_BUTTON} onClick={() => move(index, 1)} disabled={disabled || index === items.length - 1} aria-label={`Move item ${index + 1} right`}>
                  <ChevronDown className="size-4" aria-hidden />
                </button>
                <button
                  type="button"
                  className={cn(ICON_BUTTON, "hover:text-destructive")}
                  onClick={() => setItems(items.filter((_, i) => i !== index))}
                  disabled={disabled || items.length === 1}
                  aria-label={`Remove item ${index + 1}`}
                  title={items.length === 1 ? "An actions row needs at least one button or link" : undefined}
                >
                  <Trash2 className="size-4" aria-hidden />
                </button>
              </div>
            </div>
            <div className="flex flex-wrap gap-4">
              <SegmentedChoice
                label="Kind"
                value={item.kind}
                options={ACTION_KIND_OPTIONS}
                onChange={(kind) => setItem(index, withActionKind(item, kind))}
                disabled={disabled}
                error={error(`items.${index}.kind`)}
              />
              {item.kind === "button" ? (
                <SegmentedChoice
                  label="Style"
                  value={item.variant ?? "primary"}
                  options={BUTTON_VARIANT_OPTIONS}
                  onChange={(variant) => {
                    // Primary is the default and is stored as no variant at all.
                    const next: ActionItem = { ...item, variant };
                    if (variant === "primary") delete next.variant;
                    setItem(index, next);
                  }}
                  disabled={disabled}
                  error={error(`items.${index}.variant`)}
                />
              ) : null}
            </div>
            <TextLineControl
              label="Label"
              required
              value={item.label}
              onChange={(label) => setItem(index, { ...item, label })}
              tokens={tokens}
              tokensOnFocus
              maxLength={TEXT_LIMITS.buttonLabel}
              placeholder={item.kind === "button" ? "e.g. Contact us" : "e.g. See our portfolio ›"}
              disabled={disabled}
              error={error(`items.${index}.label`) ?? error(`items.${index}`)}
            />
            <LinkControl label="Link" value={item.url} onChange={(url) => setItem(index, { ...item, url })} disabled={disabled} error={error(`items.${index}.url`)} />
          </div>
        ))}
        {error("items") ? (
          <p role="alert" className="text-xs text-red-500">
            {error("items")}
          </p>
        ) : null}
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" size="sm" onClick={() => setItems([...items, { kind: "button", label: "Contact us", url: "" }])} disabled={disabled || full}>
            <Plus aria-hidden />
            Add button
          </Button>
          <Button type="button" variant="outline" size="sm" onClick={() => setItems([...items, { kind: "link", label: "Learn more ›", url: "" }])} disabled={disabled || full}>
            <Plus aria-hidden />
            Add link
          </Button>
        </div>
      </div>
      <AlignmentControl value={block.align} onChange={(align) => onChange({ ...block, align })} disabled={disabled} error={error("align")} />
      <RangeControl label="Button corner radius" value={block.radius} range={RANGES.buttonRadius} onChange={(radius) => onChange({ ...block, radius })} disabled={disabled} error={error("radius")} />
    </>
  );
}

/**
 * A card: its label and title, its own colors, and its blocks — edited with the same block list as the content
 * around it. The frame (corners, padding, border width) is the design's, under Settings → Email Designs.
 */
function CardSettings({
  block,
  onChange,
  tokens,
  styleChain,
  disabled,
  error,
  allProblems,
  takenIds,
}: {
  block: CardBlock;
  onChange: (block: DesignBlock) => void;
  tokens: readonly TemplateToken[];
  styleChain: readonly DesignStyles[];
  disabled: boolean;
  error: (field: string) => string | undefined;
  allProblems: Record<string, DesignProblem[]>;
  takenIds: ReadonlySet<string>;
}) {
  // The card's own blocks may not reuse any other id in the document; their own ids are the list's to manage.
  const ownIds = new Set(block.blocks.map((inner) => inner.id));
  const otherIds = new Set([...takenIds].filter((id) => !ownIds.has(id)));

  return (
    <>
      <TextLineControl
        label="Label"
        value={block.eyebrow ?? ""}
        onChange={(value) => onChange(withCardText(block, "eyebrow", value))}
        tokens={tokens}
        maxLength={TEXT_LIMITS.cardEyebrow}
        placeholder="Small text above the title, e.g. Quotation summary"
        disabled={disabled}
        error={error("eyebrow")}
      />
      <TextLineControl
        label="Title"
        value={block.title ?? ""}
        onChange={(value) => onChange(withCardText(block, "title", value))}
        tokens={tokens}
        maxLength={TEXT_LIMITS.cardTitle}
        placeholder="e.g. Basic website. ₱35,000, one-time."
        disabled={disabled}
        error={error("title")}
      />
      <div className="flex flex-wrap gap-4">
        <AlignmentControl value={block.align} onChange={(align) => onChange({ ...block, align })} disabled={disabled} error={error("align")} />
        <ColorControl
          label="Background"
          value={block.background_color}
          inherited={inheritedBlockColor("card", "background_color", styleChain)}
          onChange={(background_color) => onChange({ ...block, background_color })}
          disabled={disabled}
          error={error("background_color")}
        />
        <ColorControl
          label="Border"
          value={block.border_color}
          inherited={inheritedBlockColor("card", "border_color", styleChain)}
          onChange={(border_color) => onChange({ ...block, border_color })}
          disabled={disabled}
          error={error("border_color")}
        />
      </div>
      <p className="text-xs text-muted-foreground">
        The card&apos;s corners, padding and border width come from the email design. The border shows only when the design
        gives cards a border width.
      </p>
      <div className="rounded-lg border border-dashed border-border p-3">
        <DesignBlockList
          nested
          title="Inside the card"
          blocks={block.blocks}
          onChange={(update) => onChange({ ...block, blocks: update(block.blocks) })}
          addable={ADDABLE_CARD_BLOCKS}
          max={MAX_CARD_BLOCKS}
          otherIds={otherIds}
          problems={allProblems}
          tokens={tokens}
          styleChain={styleChain}
          disabled={disabled}
          emptyMessage="A card needs at least one block. Add rows, text or a button."
        />
        {error("blocks") ? (
          <p role="alert" className="mt-2 text-xs text-red-500">
            {error("blocks")}
          </p>
        ) : null}
      </div>
    </>
  );
}

/** A rows block: its items, each a title and an optional description, plus how they are drawn. */
function RowsSettings({
  block,
  onChange,
  tokens,
  styleChain,
  disabled,
  error,
}: {
  block: RowsBlock;
  onChange: (block: DesignBlock) => void;
  tokens: readonly TemplateToken[];
  styleChain: readonly DesignStyles[];
  disabled: boolean;
  error: (field: string) => string | undefined;
}) {
  const items = block.items;
  const full = items.length >= MAX_ROWS_ITEMS;
  const setItems = (next: RowsBlock["items"]) => onChange({ ...block, items: next });

  function move(index: number, direction: -1 | 1) {
    const to = index + direction;
    if (to < 0 || to >= items.length) return;
    const next = [...items];
    [next[index], next[to]] = [next[to], next[index]];
    setItems(next);
  }

  return (
    <>
      <div className="flex flex-col gap-2">
        <div className="flex items-baseline justify-between">
          <span className="text-sm font-medium text-foreground">Rows</span>
          <span className="text-xs text-muted-foreground">
            {items.length} of {MAX_ROWS_ITEMS}
          </span>
        </div>
        {items.map((item, index) => (
          <div key={index} className="flex flex-col gap-2 rounded-lg border border-border p-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-muted-foreground">Row {index + 1}</span>
              <div className="flex items-center">
                <button type="button" className={ICON_BUTTON} onClick={() => move(index, -1)} disabled={disabled || index === 0} aria-label={`Move row ${index + 1} up`}>
                  <ChevronUp className="size-4" aria-hidden />
                </button>
                <button type="button" className={ICON_BUTTON} onClick={() => move(index, 1)} disabled={disabled || index === items.length - 1} aria-label={`Move row ${index + 1} down`}>
                  <ChevronDown className="size-4" aria-hidden />
                </button>
                <button
                  type="button"
                  className={cn(ICON_BUTTON, "hover:text-destructive")}
                  onClick={() => setItems(items.filter((_, i) => i !== index))}
                  disabled={disabled || items.length === 1}
                  aria-label={`Remove row ${index + 1}`}
                  title={items.length === 1 ? "A rows block needs at least one row" : undefined}
                >
                  <Trash2 className="size-4" aria-hidden />
                </button>
              </div>
            </div>
            <TextLineControl
              label="Title"
              required
              value={item.title}
              onChange={(title) => setItems(items.map((current, i) => (i === index ? { ...current, title } : current)))}
              tokens={tokens}
              tokensOnFocus
              maxLength={TEXT_LIMITS.rowTitle}
              placeholder="e.g. Website"
              disabled={disabled}
              error={error(`items.${index}.title`) ?? error(`items.${index}`)}
            />
            <TextLineControl
              label="Description"
              value={item.description ?? ""}
              onChange={(description) => setItems(items.map((current, i) => (i === index ? withRowDescription(current, description) : current)))}
              tokens={tokens}
              tokensOnFocus
              maxLength={TEXT_LIMITS.rowDescription}
              placeholder="Optional, e.g. 5 pages, with a contact form."
              disabled={disabled}
              error={error(`items.${index}.description`)}
            />
          </div>
        ))}
        {error("items") ? (
          <p role="alert" className="text-xs text-red-500">
            {error("items")}
          </p>
        ) : null}
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="w-fit"
          onClick={() => setItems([...items, { title: "Item" }])}
          disabled={disabled || full}
        >
          <Plus aria-hidden />
          Add row
        </Button>
      </div>
      <SwitchControl
        label="Lines between rows"
        checked={block.dividers}
        onChange={(dividers) => onChange({ ...block, dividers })}
        disabled={disabled}
        error={error("dividers")}
      />
      <div className="flex flex-wrap gap-4">
        <AlignmentControl value={block.align} onChange={(align) => onChange({ ...block, align })} disabled={disabled} error={error("align")} />
        <ColorControl
          label="Titles"
          value={block.title_color}
          inherited={inheritedBlockColor("rows", "title_color", styleChain)}
          onChange={(title_color) => onChange({ ...block, title_color })}
          disabled={disabled}
          error={error("title_color")}
        />
        <ColorControl
          label="Descriptions"
          value={block.description_color}
          inherited={inheritedBlockColor("rows", "description_color", styleChain)}
          onChange={(description_color) => onChange({ ...block, description_color })}
          disabled={disabled}
          error={error("description_color")}
        />
        {block.dividers ? (
          <ColorControl
            label="Lines"
            value={block.divider_color}
            inherited={inheritedBlockColor("rows", "divider_color", styleChain)}
            onChange={(divider_color) => onChange({ ...block, divider_color })}
            disabled={disabled}
            error={error("divider_color")}
          />
        ) : null}
      </div>
    </>
  );
}
