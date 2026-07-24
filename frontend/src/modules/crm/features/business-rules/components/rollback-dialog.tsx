"use client";

import { useState } from "react";
import { Button } from "@/modules/crm/components/ui/button";
import { Textarea } from "@/modules/crm/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import type { BusinessRuleVersion } from "@/modules/crm/features/business-rules/types/business-rules-types";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  target: BusinessRuleVersion | null;
  loading?: boolean;
  onConfirm: (targetVersion: number, reason: string | undefined) => void;
};

/**
 * Confirm a forward-only rollback to a previously published version. This creates a NEW active version that
 * copies the target's rules — it never rewrites history or reactivates an archived version.
 */
export function RollbackDialog({ open, onOpenChange, target, loading, onConfirm }: Props) {
  const [reason, setReason] = useState("");

  return (
    <Dialog
      open={open}
      onOpenChange={(v) => {
        if (!v) {
          onOpenChange(false);
          setReason("");
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Roll back to version {target?.versionNumber}?</DialogTitle>
          <DialogDescription>
            This creates a new active version that copies version {target?.versionNumber}&rsquo;s rules.
            History is preserved — nothing is deleted or reactivated. Scores recompute automatically.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-1.5">
          <label htmlFor="rollback-reason" className="text-sm font-medium text-foreground">
            Reason for change <span className="text-muted-foreground">(optional)</span>
          </label>
          <Textarea
            id="rollback-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="e.g. Reverting an over-aggressive weighting"
            rows={3}
          />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={loading}>
            Cancel
          </Button>
          <Button
            onClick={() => target && onConfirm(target.versionNumber, reason.trim() || undefined)}
            disabled={loading || !target}
          >
            {loading ? "Rolling back…" : "Roll back"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
