"use client";

import { useEffect, useRef, useState } from "react";
import { EditorContent, useEditor, type Editor } from "@tiptap/react";
import { StarterKit } from "@tiptap/starter-kit";
import { CharacterCount, Placeholder } from "@tiptap/extensions";
import {
  Bold,
  Italic,
  Link2,
  Link2Off,
  List,
  ListOrdered,
  Quote,
  Redo2,
  Strikethrough,
  Underline as UnderlineIcon,
  Undo2,
} from "lucide-react";

import { cn } from "@/lib/utils";
import { EMAIL_PROSE_CLASSES } from "@/modules/hr/lib/email-editor-styles";

/**
 * Matches the backend's `body_html` ceiling. Markup inflates the count well past what
 * the reader sees, which is why it is this generous for what is a few paragraphs.
 */
const CHARACTER_LIMIT = 50_000;

export interface RichTextEditorProps {
  /** Current HTML. Changes from outside (a draft arriving) are applied to the document. */
  value: string;
  onChange: (html: string) => void;
  placeholder?: string;
  ariaLabel: string;
  /** Applied to the scrolling editable area — usually a min/max height. */
  editorClassName?: string;
  invalid?: boolean;
}

/**
 * The formatting surface for a candidate email.
 *
 * The toolbar offers exactly what survives the server's HTML sanitiser, so nothing HR can
 * press produces markup that is silently dropped on the way out. Anything richer — tables,
 * images, colours — is left out on purpose: mail clients render those inconsistently at
 * best, and a hiring email gains nothing from them.
 *
 * HR-local rather than shared: the Projects module has its own note editor with its own
 * extension set, and coupling the two would make an email inherit whatever a note needs.
 */
export function RichTextEditor({
  value,
  onChange,
  placeholder,
  ariaLabel,
  editorClassName = "",
  invalid = false,
}: RichTextEditorProps): React.ReactElement {
  // What the editor last emitted. Without it, echoing our own HTML back through `value`
  // would re-set the document on every keystroke and park the caret at the start.
  const emitted = useRef(value);

  const editor = useEditor({
    // Tiptap must not render during SSR — the document doesn't exist yet.
    immediatelyRender: false,
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3] },
        // Autolink so a pasted meeting URL is clickable without HR reaching for the
        // toolbar; not opened on click, because clicking inside an editor means "edit".
        link: { openOnClick: false, autolink: true },
        // Emails don't carry code blocks, and the sanitiser would keep them as plain
        // <pre> anyway — dropping them keeps the toolbar honest about what it can do.
        codeBlock: false,
      }),
      Placeholder.configure({ placeholder: placeholder ?? "Write your message…" }),
      CharacterCount.configure({ limit: CHARACTER_LIMIT }),
    ],
    content: value,
    editorProps: {
      attributes: { role: "textbox", "aria-multiline": "true", "aria-label": ariaLabel },
    },
    onUpdate: ({ editor: instance }) => {
      const html = instance.getHTML();
      emitted.current = html;
      onChange(html);
    },
  });

  // Content pushed in from outside: the draft finishing its fetch, or a reset on reopen.
  useEffect(() => {
    if (!editor || value === emitted.current) return;
    emitted.current = value;
    editor.commands.setContent(value, { emitUpdate: false });
  }, [editor, value]);

  return (
    <div
      className={cn(
        "rounded-lg border bg-transparent",
        invalid ? "border-destructive" : "border-input focus-within:border-ring",
      )}
    >
      <div className="border-b border-line px-1.5 py-1">
        <EditorToolbar editor={editor} />
      </div>
      <div className={cn("overflow-y-auto px-3 py-2", EMAIL_PROSE_CLASSES, editorClassName)}>
        <EditorContent editor={editor} />
      </div>
    </div>
  );
}

function EditorToolbar({ editor }: { editor: Editor | null }): React.ReactElement | null {
  const [, forceRender] = useState(0);

  // Tiptap mutates the editor in place, so React has no idea a mark just became active.
  // Re-render on every transaction to keep the pressed states honest.
  useEffect(() => {
    if (!editor) return;
    const rerender = () => forceRender((n) => n + 1);
    editor.on("transaction", rerender);
    return () => {
      editor.off("transaction", rerender);
    };
  }, [editor]);

  if (!editor) return null;

  const linked = editor.isActive("link");

  function promptForLink(): void {
    if (!editor) return;
    const previous = (editor.getAttributes("link").href as string) ?? "";
    const href = window.prompt("Link URL", previous);
    if (href === null) return;
    if (href.trim() === "") {
      editor.chain().focus().extendMarkRange("link").unsetLink().run();
      return;
    }
    // A bare "meet.example.com" is what people paste; without a scheme the sanitiser
    // would reject it and the link would arrive as plain text.
    const safe = /^(https?:|mailto:|tel:)/i.test(href.trim())
      ? href.trim()
      : `https://${href.trim()}`;
    editor.chain().focus().extendMarkRange("link").setLink({ href: safe }).run();
  }

  return (
    <div
      role="toolbar"
      aria-label="Text formatting"
      className="flex flex-wrap items-center gap-0.5"
    >
      <ToolbarButton
        label="Bold"
        active={editor.isActive("bold")}
        onClick={() => editor.chain().focus().toggleBold().run()}
      >
        <Bold className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Italic"
        active={editor.isActive("italic")}
        onClick={() => editor.chain().focus().toggleItalic().run()}
      >
        <Italic className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Underline"
        active={editor.isActive("underline")}
        onClick={() => editor.chain().focus().toggleUnderline().run()}
      >
        <UnderlineIcon className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Strikethrough"
        active={editor.isActive("strike")}
        onClick={() => editor.chain().focus().toggleStrike().run()}
      >
        <Strikethrough className="size-3.5" />
      </ToolbarButton>

      <Divider />

      {([2, 3] as const).map((level) => (
        <ToolbarButton
          key={level}
          label={`Heading ${level}`}
          active={editor.isActive("heading", { level })}
          onClick={() => editor.chain().focus().toggleHeading({ level }).run()}
        >
          <span className="text-[11px] font-bold">H{level - 1}</span>
        </ToolbarButton>
      ))}

      <Divider />

      <ToolbarButton
        label="Bullet list"
        active={editor.isActive("bulletList")}
        onClick={() => editor.chain().focus().toggleBulletList().run()}
      >
        <List className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Numbered list"
        active={editor.isActive("orderedList")}
        onClick={() => editor.chain().focus().toggleOrderedList().run()}
      >
        <ListOrdered className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Quote"
        active={editor.isActive("blockquote")}
        onClick={() => editor.chain().focus().toggleBlockquote().run()}
      >
        <Quote className="size-3.5" />
      </ToolbarButton>

      <Divider />

      <ToolbarButton label="Add link" active={linked} onClick={promptForLink}>
        <Link2 className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Remove link"
        disabled={!linked}
        onClick={() => editor.chain().focus().extendMarkRange("link").unsetLink().run()}
      >
        <Link2Off className="size-3.5" />
      </ToolbarButton>

      <Divider />

      <ToolbarButton
        label="Undo"
        disabled={!editor.can().undo()}
        onClick={() => editor.chain().focus().undo().run()}
      >
        <Undo2 className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        label="Redo"
        disabled={!editor.can().redo()}
        onClick={() => editor.chain().focus().redo().run()}
      >
        <Redo2 className="size-3.5" />
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
      // The editor must keep its selection: a toolbar press should never blur it, or
      // the mark would be applied to nothing.
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      className={cn(
        "flex size-7 items-center justify-center rounded-md transition-colors",
        active
          ? "bg-brand-subtle text-brand-foreground"
          : "text-muted-foreground hover:bg-black/5 hover:text-foreground",
        "disabled:cursor-not-allowed disabled:opacity-40",
      )}
    >
      {children}
    </button>
  );
}

function Divider(): React.ReactElement {
  return <span className="mx-1 h-4 w-px shrink-0 bg-line" aria-hidden />;
}
