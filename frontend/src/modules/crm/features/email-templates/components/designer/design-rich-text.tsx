"use client";

import { useEffect, useRef, useState } from "react";
import { EditorContent, useEditor, type JSONContent } from "@tiptap/react";
import { StarterKit } from "@tiptap/starter-kit";
import { Placeholder } from "@tiptap/extensions";
import { Link2 } from "lucide-react";
import { RichTextToolbar, type RichTextControl } from "@/core/editor/rich-text-toolbar";
import { RICH_TEXT_PROSE_CLASSES } from "@/core/editor/rich-text-styles";
import { Button } from "@/modules/crm/components/ui/button";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { cn } from "@/modules/crm/lib/utils";
import { TokenChips } from "@/modules/crm/features/email-templates/components/designer/design-controls";
import { isAllowedLinkUrl } from "@/modules/crm/features/email-templates/lib/email-design";
import type { RichTextDoc } from "@/modules/crm/features/email-templates/types/email-design-types";
import type { TemplateToken } from "@/modules/crm/features/email-templates/types/email-template-types";

const WITH_LISTS: readonly RichTextControl[] = ["bold", "italic", "underline", "strike", "bulletList", "orderedList", "undo", "redo"];
const WITHOUT_LISTS: readonly RichTextControl[] = ["bold", "italic", "underline", "strike", "undo", "redo"];

const LINK_RULE = "Use a full https:// address, a mailto: address or a tel: number.";

/**
 * The rich text inside a Text or Footer block: a Tiptap editor whose schema is exactly what the server's
 * EmailDesignValidator accepts — paragraphs, line breaks, bold, italic, underline, strike, links and (in
 * text blocks) lists. Headings, quotes, code and rules are not registered, so pasted markup carrying them
 * is reduced to paragraphs before it reaches the document.
 *
 * The block keeps the editor's JSON (`getJSON()`), never its HTML: the server renders the email.
 *
 * Configured here rather than in core/editor, which Internal Notes and the reply composer share. The
 * shared toolbar is used as-is with a subset of controls; links, which the shared toolbar deliberately
 * leaves out, get their own entry flow — an inline field, never `window.prompt` — that accepts only
 * https, mailto and tel.
 */
export function DesignRichText({
  value,
  onChange,
  tokens,
  allowLists,
  ariaLabel,
  placeholder,
  disabled = false,
}: {
  /** The starting document. Later edits flow out through `onChange`; the editor owns the text while mounted. */
  value: RichTextDoc;
  onChange: (doc: RichTextDoc) => void;
  tokens: readonly TemplateToken[];
  allowLists: boolean;
  ariaLabel: string;
  placeholder?: string;
  disabled?: boolean;
}) {
  // Tiptap captures its callbacks once, when the editor is created; the ref always holds the latest.
  const changeRef = useRef(onChange);
  useEffect(() => {
    changeRef.current = onChange;
  }, [onChange]);

  const [linkOpen, setLinkOpen] = useState(false);
  const [linkValue, setLinkValue] = useState("");
  const [linkError, setLinkError] = useState<string | null>(null);

  const editor = useEditor({
    immediatelyRender: false,
    shouldRerenderOnTransaction: true,
    editable: !disabled,
    extensions: [
      StarterKit.configure({
        heading: false,
        blockquote: false,
        codeBlock: false,
        code: false,
        horizontalRule: false,
        ...(allowLists ? {} : { bulletList: false, orderedList: false, listItem: false, listKeymap: false }),
        link: {
          openOnClick: false,
          autolink: false,
          linkOnPaste: false,
          defaultProtocol: "https",
          protocols: ["mailto", "tel"],
          isAllowedUri: (url) => isAllowedLinkUrl(url),
        },
      }),
      Placeholder.configure({ placeholder: placeholder ?? "" }),
    ],
    content: value as JSONContent,
    editorProps: {
      attributes: { role: "textbox", "aria-multiline": "true", "aria-label": ariaLabel },
    },
    onUpdate: ({ editor: current }) => changeRef.current(current.getJSON() as RichTextDoc),
  });

  useEffect(() => {
    editor?.setEditable(!disabled);
  }, [editor, disabled]);

  function openLinkField() {
    if (!editor) return;
    setLinkValue((editor.getAttributes("link").href as string | undefined) ?? "");
    setLinkError(null);
    setLinkOpen(true);
  }

  function applyLink() {
    if (!editor) return;
    const href = linkValue.trim();
    if (!isAllowedLinkUrl(href)) {
      setLinkError(LINK_RULE);
      return;
    }
    if (editor.state.selection.empty && !editor.isActive("link")) {
      setLinkError("Select the words to link first.");
      return;
    }
    editor.chain().focus().extendMarkRange("link").setLink({ href }).run();
    setLinkOpen(false);
  }

  function removeLink() {
    editor?.chain().focus().extendMarkRange("link").unsetLink().run();
    setLinkOpen(false);
  }

  function insertToken(key: string) {
    // As a text node, never a string: a string passed to insertContent is parsed as HTML.
    editor?.chain().focus().insertContent({ type: "text", text: `{{${key}}}` }).run();
  }

  return (
    <div className="flex min-w-0 flex-col gap-1.5">
      <div className={cn("overflow-hidden rounded-lg border border-border bg-card", disabled && "opacity-60")}>
        <div className="flex flex-wrap items-center gap-1 border-b border-border-subtle bg-muted/40 px-2 py-1.5">
          <RichTextToolbar editor={editor} controls={allowLists ? WITH_LISTS : WITHOUT_LISTS} />
          <span aria-hidden className="mx-1 h-4 w-px bg-border" />
          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={openLinkField}
            disabled={!editor || disabled}
            aria-label="Link"
            aria-pressed={editor?.isActive("link") ?? false}
            title="Link"
            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40 aria-pressed:bg-muted aria-pressed:text-foreground"
          >
            <Link2 className="size-3.5" aria-hidden />
          </button>
        </div>

        {linkOpen ? (
          <div className="flex flex-col gap-1.5 border-b border-border-subtle bg-muted/20 px-3 py-2">
            <div className="flex flex-wrap items-center gap-2">
              <input
                autoFocus
                type="url"
                inputMode="url"
                value={linkValue}
                onChange={(event) => {
                  setLinkValue(event.target.value);
                  setLinkError(null);
                }}
                onKeyDown={(event) => {
                  // Enter applies the link rather than submitting the designer's form.
                  if (event.key === "Enter") {
                    event.preventDefault();
                    applyLink();
                  } else if (event.key === "Escape") {
                    event.preventDefault();
                    setLinkOpen(false);
                  }
                }}
                placeholder="https://"
                aria-label="Link address"
                aria-invalid={linkError ? true : undefined}
                className={cn(FIELD, "min-w-48 flex-1")}
              />
              <Button type="button" size="sm" onClick={applyLink}>
                Apply
              </Button>
              {editor?.isActive("link") ? (
                <Button type="button" size="sm" variant="outline" onClick={removeLink}>
                  Remove link
                </Button>
              ) : null}
              <Button type="button" size="sm" variant="ghost" onClick={() => setLinkOpen(false)}>
                Cancel
              </Button>
            </div>
            {linkError ? (
              <p role="alert" className="text-xs text-red-500">
                {linkError}
              </p>
            ) : null}
          </div>
        ) : null}

        <div className={cn("max-h-64 overflow-y-auto px-3 py-2 [&_.tiptap]:min-h-16", RICH_TEXT_PROSE_CLASSES)}>
          <EditorContent editor={editor} />
        </div>
      </div>
      {tokens.length > 0 ? <TokenChips tokens={tokens} onInsert={insertToken} disabled={!editor || disabled} /> : null}
    </div>
  );
}
