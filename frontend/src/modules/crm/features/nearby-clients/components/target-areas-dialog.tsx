"use client";

import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/modules/crm/components/ui/dialog";
import { TargetAreasPanel } from "@/modules/crm/features/nearby-clients/components/target-areas-panel";

/**
 * Admin-only dialog wrapping the Target Service Areas registry (the frozen data-foundation feature).
 * Kept off the main BD-facing Nearby Clients view but reachable via a header action for admins.
 */
export function TargetAreasDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="w-full sm:max-w-[560px]">
        <DialogHeader>
          <DialogTitle>Target Service Areas</DialogTitle>
          <DialogDescription>
            Configure which administrative areas count as a target location for the Lead Score.
          </DialogDescription>
        </DialogHeader>
        <TargetAreasPanel className="border-0 p-0 shadow-none" />
      </DialogContent>
    </Dialog>
  );
}
