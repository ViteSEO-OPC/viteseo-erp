"use client";

import { useState } from "react";
import { Clock } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { useDailyOverride, useUpsertDailyOverride } from "@/modules/hr/hooks/use-attendance";

export function GracePeriodButton() {
  const [open, setOpen] = useState(false);
  const [minutes, setMinutes] = useState("");

  const { data: override } = useDailyOverride();
  const upsert = useUpsertDailyOverride();

  const handleOpen = () => {
    setMinutes(override?.grace_minutes != null ? String(override.grace_minutes) : "");
    setOpen(true);
  };

  const handleSave = () => {
    const today = new Date().toISOString().slice(0, 10);
    const val = minutes.trim() === "" ? null : Number(minutes);
    upsert.mutate(
      { date: today, graceMinutes: val },
      { onSuccess: () => setOpen(false) },
    );
  };

  const handleClear = () => {
    const today = new Date().toISOString().slice(0, 10);
    upsert.mutate(
      { date: today, graceMinutes: null },
      { onSuccess: () => setOpen(false) },
    );
  };

  const hasOverride = override?.grace_minutes != null;

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        className="gap-2"
        onClick={handleOpen}
      >
        <Clock className="size-4" />
        {hasOverride ? `Grace: ${override.grace_minutes}m` : "Grace period"}
      </Button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>Today&apos;s Grace Period</DialogTitle>
            <DialogDescription>
              Override the lateness grace period for{" "}
              {new Date().toLocaleDateString("en-PH", {
                weekday: "short",
                month: "short",
                day: "numeric",
              })}
              . Leave empty to use the schedule default.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-2">
            <label className="text-sm font-medium text-foreground">
              Grace minutes
            </label>
            <Input
              type="number"
              min={0}
              max={240}
              placeholder="e.g. 30"
              value={minutes}
              onChange={(e) => setMinutes(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              Employees arriving within this window after their schedule start
              time are marked &quot;On time&quot; today.
            </p>
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            {hasOverride && (
              <Button
                variant="outline"
                size="sm"
                onClick={handleClear}
                disabled={upsert.isPending}
              >
                Clear override
              </Button>
            )}
            <Button size="sm" onClick={handleSave} disabled={upsert.isPending}>
              {upsert.isPending ? "Saving…" : "Save"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
