"use client";

import { Clock } from "lucide-react";
import { Switch } from "@/modules/crm/components/ui/switch";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { Input } from "@/modules/crm/components/ui/input";
import {
  businessDaysAheadLocalInput,
  businessDaysUntilNextMonday,
  businessNowLocalInput,
} from "@/modules/crm/lib/timezone";
import type { SchedulerState } from "@/modules/crm/features/follow-ups/types/follow-ups-types";

type SchedulerPanelProps = {
  open: boolean;
  scheduler: SchedulerState;
  onToggle: () => void;
  onDatetimeChange: (value: string) => void;
  onClose: () => void;
};

export function SchedulerPanel({
  open,
  scheduler,
  onToggle,
  onDatetimeChange,
  onClose,
}: SchedulerPanelProps) {
  return (
    // Dialog, not Sheet. `Sheet side="bottom"` compiles to `inset-x-0 bottom-0`, which pins the
    // panel to the very bottom edge of the viewport — that is why this modal read as "too low".
    // Dialog is the pattern every other Zerem modal already uses (Schedule Follow-up, Assign Lead,
    // Won/Lost, Attachments): centred via `top-1/2 left-1/2 -translate-*`, capped at
    // `max-h-[calc(100dvh-2rem)]` with `overflow-y-auto overflow-x-hidden` so it can neither clip
    // nor scroll sideways, and `max-w-[calc(100%-2rem)]` so it stays inside a phone viewport.
    <Dialog open={open} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="w-full px-6 py-5 sm:max-w-[580px]">
        {/* Title row holds ONLY the title. `pr-8` reserves the corner for Dialog's built-in close
            button (`absolute top-2 right-2`) — the toggle used to sit there, putting a destructive
            "close" and a state-changing "enable" a few pixels apart. */}
        <DialogHeader className="p-0 pr-8">
          <div className="flex items-center gap-2">
            <Clock className="h-4 w-4 shrink-0 text-teal-500" />
            <DialogTitle className="text-sm font-bold text-foreground">
              Schedule this email for later
            </DialogTitle>
          </div>
        </DialogHeader>

        <div className="mt-4 space-y-3">
          {/* The enable/disable control, now on its own labelled row and well clear of the X.
              Wrapping <label> rather than htmlFor: the Switch primitive assigns its own DOM id, so a
              htmlFor would dangle — this is the pattern schedule-toggle.tsx already uses, and it
              makes the whole row a hit target. */}
          <label className="flex cursor-pointer items-center justify-between gap-3 text-sm text-foreground">
            <span>Schedule email</span>
            <Switch
              checked={scheduler.enabled}
              onCheckedChange={onToggle}
              aria-label="Schedule email"
            />
          </label>
          {/* A real datetime-local input, in the business timezone, matching how Schedule Follow-up
              already collects a time. It replaces free text ("MM/DD/YYYY HH:MM AM/PM") that the
              backend's `date|after:now` rule could never have parsed. `min` blocks past values in
              the picker; the send path re-validates, since `min` is only a UI affordance. */}
          <Input
            type="datetime-local"
            value={scheduler.datetime}
            min={businessNowLocalInput()}
            onChange={(e) => onDatetimeChange(e.target.value)}
            aria-label="Send date and time"
          />
          <div className="flex gap-2">
            {/* Quick-fills now produce REAL future values in the business timezone rather than the
                literal strings "Tomorrow 9:00 AM" / "Next Monday 9:00 AM". */}
            <Button
              variant="outline"
              size="sm"
              className="min-h-11 rounded-full border-gray-300 bg-cream text-gray-700 hover:bg-cream md:min-h-0 dark:border-border dark:bg-secondary dark:text-foreground dark:hover:bg-input"
              onClick={() => onDatetimeChange(businessDaysAheadLocalInput(1, 9))}
            >
              Tomorrow 9:00 AM
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="min-h-11 rounded-full border-gray-300 bg-cream text-gray-700 hover:bg-cream md:min-h-0 dark:border-border dark:bg-secondary dark:text-foreground dark:hover:bg-input"
              onClick={() => onDatetimeChange(businessDaysAheadLocalInput(businessDaysUntilNextMonday(), 9))}
            >
              Next Monday 9:00 AM
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
