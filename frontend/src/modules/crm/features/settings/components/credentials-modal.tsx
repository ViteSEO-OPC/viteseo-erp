"use client";

import type { ReactNode } from "react";
import { X, Copy } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { useCopyToClipboard } from "@/modules/crm/hooks/use-copy-to-clipboard";
import { RoleChip, StatusPill } from "@/modules/crm/features/settings/components/account-chips";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import type { TeamUser } from "@/modules/crm/features/settings/types/user-types";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: TeamUser;
};

export function CredentialsModal({ open, onOpenChange, user }: Props) {
  const { copy } = useCopyToClipboard();

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onOpenChange(false); }}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col gap-0 p-0 sm:max-w-[420px]">
        <DialogHeader className="border-b border-border-subtle px-6 py-4">
          <div className="flex items-center justify-between">
            <DialogTitle className="text-lg font-bold text-card-foreground">Sign-in details</DialogTitle>
            <button type="button" onClick={() => onOpenChange(false)} aria-label="Close" className={closeButtonClass}>
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        <div className="flex flex-col gap-4 px-6 py-5">
          <Row label="Name">
            <span className="text-sm font-semibold text-card-foreground">{user.name}</span>
          </Row>
          <Row label="Email">
            <div className="flex items-center gap-2">
              <span className="text-sm font-semibold text-card-foreground">{user.email}</span>
              <button
                type="button"
                onClick={async () => { if (await copy(user.email)) toast.success("Email copied"); }}
                className="text-muted-foreground hover:text-foreground"
                aria-label="Copy email"
              >
                <Copy className="h-3.5 w-3.5" />
              </button>
            </div>
          </Row>
          <Row label="Role"><RoleChip role={user.role} /></Row>
          <Row label="Status"><StatusPill status={user.status} /></Row>
          <Row label="Last sign-in">
            <span className="text-sm text-muted-foreground">{formatActivityTimestamp(user.lastLoginAt)}</span>
          </Row>
          <p className="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
            The temporary password is shown only once, at account creation. Issuing a new one
            (Reset Password) is coming soon.
          </p>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <span className="text-sm text-muted-foreground">{label}</span>
      {children}
    </div>
  );
}
