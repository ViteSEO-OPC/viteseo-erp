"use client";

import type { Editor } from "@tiptap/react";
import {
  Bold,
  Highlighter,
  Italic,
  Link2,
  List,
  ListOrdered,
  ListTodo,
  Redo2,
  Strikethrough,
  Underline as UnderlineIcon,
  Undo2,
} from "lucide-react";

/**
 * The formatting bar. Order follows the spec: marks, headings, lists,
 * highlight/link, history — grouped with hairline separators.
 *
 * Every button reports its state through `aria-pressed` so assistive tech can
 * tell "bold is on" from "bold is available".
 */
export function NoteToolbar({
  editor,
  className = "",
}: {
  editor: Editor | null;
  className?: string;
}): React.ReactElement | null {
  if (!editor) return null;

  const promptForLink = (): void => {
    const previous = (editor.getAttributes("link").href as string) ?? "";
    // A native prompt keeps the link flow one keystroke deep; a modal here
    // would fight the editor for focus and lose the selection.
    const href = window.prompt("Link URL", previous);
    if (href === null) return;
    if (href === "") {
      editor.chain().focus().extendMarkRange("link").unsetLink().run();
      return;
    }
    const safe = /^https?:\/\//i.test(href) ? href : `https://${href}`;
    editor.chain().focus().extendMarkRange("link").setLink({ href: safe }).run();
  };

  return (
    <div
      role="toolbar"
      aria-label="Text formatting"
      className={`flex flex-wrap items-center gap-0.5 ${className}`}
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
      {/* <ToolbarButton
        label="Link"
        active={editor.isActive("link")}
        onClick={promptForLink}
      >
        <Link2 className="h-3.5 w-3.5" />
      </ToolbarButton> */}

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
      // The editor must keep the selection: a toolbar press should never blur it.
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      className={`flex h-7 min-w-7 items-center justify-center rounded-md px-1.5 transition-colors ${
        active
          ? "bg-brand/10 text-brand"
          : "text-ink-soft hover:bg-black/5 hover:text-ink"
      } disabled:cursor-not-allowed disabled:opacity-40`}
    >
      {children}
    </button>
  );
}

function Divider(): React.ReactElement {
  return <span className="mx-1 h-4 w-px shrink-0 bg-line" aria-hidden />;
}
