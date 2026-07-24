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

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  draftVersion: number;
  loading?: boolean;
  onConfirm: (reason: string | undefined) => void;
};

/** Confirm publishing the draft. The optional Reason for Change is recorded on the new version + audit. */
export function PublishDialog({ open, onOpenChange, draftVersion, loading, onConfirm }: Props) {
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
          <DialogTitle>Publish version {draftVersion}?</DialogTitle>
          <DialogDescription>
            This makes the draft the active configuration. Existing Lead Scores are recomputed automatically
            in the background — no score is edited by hand.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-1.5">
          <label htmlFor="publish-reason" className="text-sm font-medium text-foreground">
            Reason for change <span className="text-muted-foreground">(optional)</span>
          </label>
          <Textarea
            id="publish-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="e.g. Increase weight for no-website prospects"
            rows={3}
          />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={loading}>
            Cancel
          </Button>
          <Button onClick={() => onConfirm(reason.trim() || undefined)} disabled={loading}>
            {loading ? "Publishing…" : "Publish"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
