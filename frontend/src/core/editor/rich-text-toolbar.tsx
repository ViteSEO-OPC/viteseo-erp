"use client";

import { Fragment } from "react";
import type { Editor } from "@tiptap/react";
import {
  Bold,
  Highlighter,
  Italic,
  List,
  ListOrdered,
  ListTodo,
  Redo2,
  Strikethrough,
  Underline as UnderlineIcon,
  Undo2,
} from "lucide-react";
import { cn } from "@/core/lib/utils";

/** One control the toolbar can offer. `headings` is the H1–H3 group as a whole. */
export type RichTextControl =
  | "bold"
  | "italic"
  | "underline"
  | "strike"
  | "headings"
  | "bulletList"
  | "orderedList"
  | "taskList"
  | "highlight"
  | "undo"
  | "redo";

/** Everything — the default, and exactly the bar Internal Notes has always shown. */
const ALL_CONTROLS: readonly RichTextControl[] = [
  "bold",
  "italic",
  "underline",
  "strike",
  "headings",
  "bulletList",
  "orderedList",
  "taskList",
  "highlight",
  "undo",
  "redo",
];

/**
 * Formatting bar for a Tiptap editor. Marks → headings → lists → highlight → history, grouped with
 * hairline separators. Every button reports `aria-pressed` so assistive tech can distinguish
 * "bold is on" from "bold is available".
 *
 * ── Why this is not `modules/projects/components/notes/note-toolbar.tsx` ──
 * That file is the reference this was modelled on. It is NOT imported (a CRM → Projects import would
 * breach module separation) and NOT moved, because it is written in the Projects/PMS palette —
 * `text-ink-soft`, `bg-brand/10`, `bg-line`, `hover:bg-black/5`. Those would render as Sprintly
 * chrome inside a CRM card and would not follow the CRM's dark mode.
 *
 * This copy carries the same controls, the same `aria-pressed` semantics and the same
 * mousedown-suppression, restyled in the shadcn base tokens that exist on `:root` for every module.
 * Projects keeps its own toolbar unchanged; unifying the two is a later, optional step, and is not
 * worth changing working Projects UI for.
 *
 * The control set is deliberately exactly what the Projects editor already offers — nothing was
 * invented for completeness. Link is omitted for the same reason it is commented out there: the CRM
 * has no approved link-entry flow, and `window.prompt` is not one.
 *
 * ── `controls` ──
 * Optional and additive. Omitted, the bar is the full set above, unchanged — which is what every
 * existing caller gets. A surface whose editor supports less passes its own subset (the email composer
 * offers only what survives a mail client), so the bar never shows a button its editor cannot honour.
 * A group with nothing visible disappears along with its separator; order never changes.
 */
export function RichTextToolbar({
  editor,
  className,
  controls = ALL_CONTROLS,
}: {
  editor: Editor | null;
  className?: string;
  controls?: readonly RichTextControl[];
}): React.ReactElement | null {
  if (!editor) return null;

  const has = (control: RichTextControl) => controls.includes(control);

  const groups: React.ReactElement[][] = [
    [
      has("bold") && (
        <ToolbarButton
          key="bold"
          label="Bold"
          active={editor.isActive("bold")}
          onClick={() => editor.chain().focus().toggleBold().run()}
        >
          <Bold className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
      has("italic") && (
        <ToolbarButton
          key="italic"
          label="Italic"
          active={editor.isActive("italic")}
          onClick={() => editor.chain().focus().toggleItalic().run()}
        >
          <Italic className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
      has("underline") && (
        <ToolbarButton
          key="underline"
          label="Underline"
          active={editor.isActive("underline")}
          onClick={() => editor.chain().focus().toggleUnderline().run()}
        >
          <UnderlineIcon className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
      has("strike") && (
        <ToolbarButton
          key="strike"
          label="Strikethrough"
          active={editor.isActive("strike")}
          onClick={() => editor.chain().focus().toggleStrike().run()}
        >
          <Strikethrough className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
    ],
    has("headings")
      ? ([1, 2, 3] as const).map((level) => (
          <ToolbarButton
            key={`heading-${level}`}
            label={`Heading ${level}`}
            active={editor.isActive("heading", { level })}
            onClick={() => editor.chain().focus().toggleHeading({ level }).run()}
          >
            <span className="text-xs font-bold">H{level}</span>
          </ToolbarButton>
        ))
      : [],
    [
      has("bulletList") && (
        <ToolbarButton
          key="bulletList"
          label="Bullet list"
          active={editor.isActive("bulletList")}
          onClick={() => editor.chain().focus().toggleBulletList().run()}
        >
          <List className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
      has("orderedList") && (
        <ToolbarButton
          key="orderedList"
          label="Numbered list"
          active={editor.isActive("orderedList")}
          onClick={() => editor.chain().focus().toggleOrderedList().run()}
        >
          <ListOrdered className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
      has("taskList") && (
        <ToolbarButton
          key="taskList"
          label="Task list"
          active={editor.isActive("taskList")}
          onClick={() => editor.chain().focus().toggleTaskList().run()}
        >
          <ListTodo className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
    ],
    [
      has("highlight") && (
        <ToolbarButton
          key="highlight"
          label="Highlight"
          active={editor.isActive("highlight")}
          onClick={() => editor.chain().focus().toggleHighlight().run()}
        >
          <Highlighter className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
    ],
    [
      has("undo") && (
        <ToolbarButton
          key="undo"
          label="Undo"
          disabled={!editor.can().undo()}
          onClick={() => editor.chain().focus().undo().run()}
        >
          <Undo2 className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
      has("redo") && (
        <ToolbarButton
          key="redo"
          label="Redo"
          disabled={!editor.can().redo()}
          onClick={() => editor.chain().focus().redo().run()}
        >
          <Redo2 className="h-3.5 w-3.5" />
        </ToolbarButton>
      ),
    ],
  ]
    .map((group) => group.filter((item): item is React.ReactElement => item !== false))
    .filter((group) => group.length > 0);

  return (
    <div
      role="toolbar"
      aria-label="Text formatting"
      className={cn("flex flex-wrap items-center gap-0.5", className)}
    >
      {groups.map((group, index) => (
        <Fragment key={index}>
          {index > 0 ? <Divider /> : null}
          {group}
        </Fragment>
      ))}
    </div>
  );
}

function ToolbarButton({
  label,
  active = false,
  disabled = false,
  onClick,
  children,
}: {
  label: string;
  active?: boolean;
  disabled?: boolean;
  onClick: () => void;
  children: React.ReactNode;
}): React.ReactElement {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      aria-pressed={active}
      disabled={disabled}
      // The editor must keep its selection: pressing a toolbar button should never blur it, and
      // without this the bar would also fold away on any reveal-on-focus surface mid-use.
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      className={cn(
        "flex h-7 min-w-7 items-center justify-center rounded-md px-1.5 transition-colors",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        "disabled:cursor-not-allowed disabled:opacity-40",
        active
          ? "bg-teal-100 text-teal-800 dark:bg-teal-950/50 dark:text-teal-300"
          : "text-muted-foreground hover:bg-muted hover:text-foreground",
      )}
    >
      {children}
    </button>
  );
}

function Divider(): React.ReactElement {
  return <span className="mx-1 h-4 w-px shrink-0 bg-border" aria-hidden />;
}
