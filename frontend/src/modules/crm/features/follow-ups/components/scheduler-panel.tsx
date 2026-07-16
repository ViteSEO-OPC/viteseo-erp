"use client";

import { Clock } from "lucide-react";
import { Switch } from "@/modules/crm/components/ui/switch";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/modules/crm/components/ui/sheet";
import { Button } from "@/modules/crm/components/ui/button";
import { Input } from "@/modules/crm/components/ui/input";
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
    <Sheet open={open} onOpenChange={(open) => !open && onClose()}>
      <SheetContent side="bottom" className="mx-auto max-w-[580px] rounded-t-xl px-6 py-5">
        <SheetHeader className="p-0">
          <div className="flex items-center gap-2">
            <Clock className="h-4 w-4 text-teal-500" />
            <SheetTitle className="flex-1 text-sm font-bold text-foreground">
              Schedule this email for later
            </SheetTitle>
            <Switch
              checked={scheduler.enabled}
              onCheckedChange={onToggle}
            />
          </div>
        </SheetHeader>

        <div className="mt-4 space-y-3">
          <Input
            type="text"
            value={scheduler.datetime}
            onChange={(e) => onDatetimeChange(e.target.value)}
            placeholder="MM/DD/YYYY HH:MM AM/PM"
          />
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              className="min-h-11 rounded-full border-gray-300 bg-cream text-gray-700 hover:bg-cream md:min-h-0"
              onClick={() => onDatetimeChange("Tomorrow 9:00 AM")}
            >
              Tomorrow 9:00 AM
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="min-h-11 rounded-full border-gray-300 bg-cream text-gray-700 hover:bg-cream md:min-h-0"
              onClick={() => onDatetimeChange("Next Monday 9:00 AM")}
            >
              Next Monday 9:00 AM
            </Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  );
}
