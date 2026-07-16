"use client";

import { RefreshCw, Copy, Bookmark, Check, Send as SendIcon } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { ScheduleToggle } from "@/modules/crm/features/follow-ups/components/schedule-toggle";

type ComposerActionBarProps = {
  scheduleEnabled: boolean;
  onScheduleToggle: () => void;
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
  onSaveDraft,
  savingDraft,
  onMarkDone,
  markingDone,
  onSend,
  sending,
}: ComposerActionBarProps) {
  return (
    <div className="flex flex-wrap items-center gap-2 border-t border-border-subtle px-4 py-4 md:px-6">
      <Button variant="outline" disabled title="Coming soon" className="min-h-11 md:min-h-0">
        <RefreshCw className="h-4 w-4" />
        <span className="hidden md:inline">Regenerate</span>
      </Button>
      <Button variant="outline" disabled title="Coming soon" className="min-h-11 md:min-h-0">
        <Copy className="h-4 w-4" />
        <span className="hidden md:inline">Copy</span>
      </Button>
      <Button
        variant="outline"
        onClick={onSaveDraft}
        disabled={savingDraft}
        className="min-h-11 md:min-h-0"
      >
        <Bookmark className="h-4 w-4" />
        <span className="hidden md:inline">{savingDraft ? "Saving…" : "Save Draft"}</span>
      </Button>
      <Button
        variant="outline"
        onClick={onMarkDone}
        disabled={markingDone || !onMarkDone}
        title={!onMarkDone ? "No follow-up to complete for this lead" : undefined}
        className="min-h-11 border-teal-500 text-teal-600 hover:bg-teal-50 hover:text-teal-700 md:min-h-0"
      >
        <Check className="h-4 w-4" />
        <span className="hidden md:inline">{markingDone ? "Marking…" : "Mark Done"}</span>
      </Button>

      <div className="ml-auto flex w-full items-center justify-end gap-4 md:w-auto">
        <ScheduleToggle checked={scheduleEnabled} onChange={onScheduleToggle} />
        <Button variant="default" onClick={onSend} disabled={sending} className="min-h-11 md:min-h-0">
          <span className="hidden md:inline">{sending ? "Sending…" : "Send"}</span>
          <SendIcon className="h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
