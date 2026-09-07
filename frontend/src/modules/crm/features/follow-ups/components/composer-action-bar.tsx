"use client";

import { RefreshCw, Copy, Bookmark, Check, Send as SendIcon } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { ScheduleToggle } from "@/modules/crm/features/follow-ups/components/schedule-toggle";
import { useCopyToClipboard } from "@/core/use-copy-to-clipboard";
import { notify as toast } from "@/core/lib/notify";

type ComposerActionBarProps = {
  scheduleEnabled: boolean;
  onScheduleToggle: () => void;
  /** Re-render Subject + Body from the current Purpose + Tone. Omitted when no template matches. */
  onRegenerate?: () => void;
  /** Composer contents, for the clipboard. */
  subject?: string;
  body?: string;
  /** Omitted when the selected item has no lead — a draft cannot be saved without one. */
  onSaveDraft?: () => void;
  savingDraft?: boolean;
  onMarkDone?: () => void;
  markingDone?: boolean;
  onSend?: () => void;
  sending?: boolean;
};

export function ComposerActionBar({
  scheduleEnabled,
  onScheduleToggle,
  onRegenerate,
  subject,
  body,
  onSaveDraft,
  savingDraft,
  onMarkDone,
  markingDone,
  onSend,
  sending,
}: ComposerActionBarProps) {
  // The shared clipboard helper (already used by Team Accounts) — `copied` auto-resets after ~2s and
  // drives the tick, so this needs no local timer.
  const { copied, copy } = useCopyToClipboard();

  const handleCopy = async () => {
    const text = [subject ?? "", body ?? ""].join("\n\n").trim();
    if (text === "") {
      toast.error("Nothing to copy — the email is empty.");
      return;
    }
    // `copy` tries the Clipboard API then the legacy textarea fallback, so it resolves false only
    // when BOTH fail. The message stays neutral about the cause — on an insecure origin nothing was
    // "blocked", the API simply does not exist — and tells the BD what to do instead.
    const ok = await copy(text);
    if (ok) {
      toast.success("Email copied to clipboard");
    } else {
      toast.error("Couldn't copy automatically — select the text and copy manually.");
    }
  };

  return (
    <div className="flex flex-wrap items-center gap-2 border-t border-border-subtle px-4 py-4 md:px-6">
      <Button
        variant="outline"
        onClick={onRegenerate}
        disabled={!onRegenerate}
        aria-label="Regenerate"
        title={!onRegenerate ? "No email templates exist for the selected Tone" : undefined}
        className="min-h-11 md:min-h-0"
      >
        <RefreshCw className="h-4 w-4" />
        <span className="hidden md:inline">Regenerate</span>
      </Button>
      <Button
        variant="outline"
        onClick={handleCopy}
        aria-label="Copy"
        className="min-h-11 md:min-h-0"
      >
        {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
        <span className="hidden md:inline">{copied ? "Copied" : "Copy"}</span>
      </Button>
      <Button
        variant="outline"
        onClick={onSaveDraft}
        disabled={savingDraft || !onSaveDraft}
        aria-label="Save Draft"
        title={!onSaveDraft ? "This follow-up has no linked lead, so a draft cannot be saved" : undefined}
        className="min-h-11 md:min-h-0"
      >
        <Bookmark className="h-4 w-4" />
        <span className="hidden md:inline">{savingDraft ? "Saving…" : "Save Draft"}</span>
      </Button>
      <Button
        variant="outline"
        onClick={onMarkDone}
        disabled={markingDone || !onMarkDone}
        aria-label="Mark Done"
        title={!onMarkDone ? "No follow-up to complete for this lead" : undefined}
        className="min-h-11 border-teal-500 text-teal-600 hover:bg-teal-50 hover:text-teal-700 md:min-h-0"
      >
        <Check className="h-4 w-4" />
        <span className="hidden md:inline">{markingDone ? "Marking…" : "Mark Done"}</span>
      </Button>

      <div className="ml-auto flex w-full items-center justify-end gap-4 md:w-auto">
        <ScheduleToggle checked={scheduleEnabled} onChange={onScheduleToggle} />
        <Button variant="default" onClick={onSend} disabled={sending} aria-label="Send" className="min-h-11 md:min-h-0">
          <span className="hidden md:inline">{sending ? "Sending…" : "Send"}</span>
          <SendIcon className="h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
