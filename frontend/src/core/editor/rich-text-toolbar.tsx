"use client";

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
 */
export function RichTextToolbar({
  editor,
  className,
}: {
  editor: Editor | null;
  className?: string;
}): React.ReactElement | null {
  if (!editor) return null;

  return (
    <div
      role="toolbar"
      aria-label="Text formatting"
      className={cn("flex flex-wrap items-center gap-0.5", className)}
    >
      <ToolbarButton
        label="Bold"
        active={editor.isActive("bold")}
        onClick={() => editor.chain().focus().toggleBold().run()}
      >
        <Bold className="h-3.5 w-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Italic"
        active={editor.isActive("italic")}
        onClick={() => editor.chain().focus().toggleItalic().run()}
      >
        <Italic className="h-3.5 w-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Underline"
        active={editor.isActive("underline")}
        onClick={() => editor.chain().focus().toggleUnderline().run()}
      >
        <UnderlineIcon className="h-3.5 w-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Strikethrough"
        active={editor.isActive("strike")}
        onClick={() => editor.chain().focus().toggleStrike().run()}
      >
        <Strikethrough className="h-3.5 w-3.5" />
      </ToolbarButton>

      <Divider />

      {([1, 2, 3] as const).map((level) => (
        <ToolbarButton
          key={level}
          label={`Heading ${level}`}
          active={editor.isActive("heading", { level })}
          onClick={() => editor.chain().focus().toggleHeading({ level }).run()}
        >
          <span className="text-xs font-bold">H{level}</span>
        </ToolbarButton>
      ))}

      <Divider />

      <ToolbarButton
        label="Bullet list"
        active={editor.isActive("bulletList")}
        onClick={() => editor.chain().focus().toggleBulletList().run()}
      >
        <List className="h-3.5 w-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Numbered list"
        active={editor.isActive("orderedList")}
        onClick={() => editor.chain().focus().toggleOrderedList().run()}
      >
        <ListOrdered className="h-3.5 w-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Task list"
        active={editor.isActive("taskList")}
        onClick={() => editor.chain().focus().toggleTaskList().run()}
      >
        <ListTodo className="h-3.5 w-3.5" />
      </ToolbarButton>

      <Divider />

      <ToolbarButton
        label="Highlight"
        active={editor.isActive("highlight")}
        onClick={() => editor.chain().focus().toggleHighlight().run()}
      >
        <Highlighter className="h-3.5 w-3.5" />
      </ToolbarButton>

      <Divider />

      <ToolbarButton
        label="Undo"
        disabled={!editor.can().undo()}
        onClick={() => editor.chain().focus().undo().run()}
      >
        <Undo2 className="h-3.5 w-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Redo"
        disabled={!editor.can().redo()}
        onClick={() => editor.chain().focus().redo().run()}
      >
        <Redo2 className="h-3.5 w-3.5" />
      </ToolbarButton>
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
