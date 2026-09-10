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
import { useCopyToClipboard } from "@/core/use-copy-to-clipboard";
import type { TeamMember } from "@/core/team/use-team-accounts";
import { StatusPill } from "@/modules/crm/features/settings/components/account-chips";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  member: TeamMember;
  /**
   * The role's display name, resolved by the page against BOTH the built-in
   * presets and the org's custom roles. Passed in rather than derived here,
   * because a chip that maps only the built-in three renders every custom-role
   * member as "—".
   */
  roleLabel: string;
};

/**
 * An account's sign-in identity — who it is, what it reaches, whether it is live.
 *
 * NO PASSWORD, and none is recoverable: the server stores only a hash, so the
 * temporary one shown at creation is the last plaintext that ever exists. Re-issuing
 * a fresh one is not built yet, and this card says so rather than offering a control
 * that would not work.
 *
 * There is deliberately no "last sign-in" row any more. It came from `GET /users`,
 * which lists by Spatie role and so returns nothing for a member on one of the org's
 * own roles: on this list it would have been a timestamp for some rows and a dash for
 * others, where the dash meant "the endpoint cannot see them" rather than "never
 * signed in". A field that is wrong for exactly the people this screen was rebuilt
 * for is worse than an absent one.
 */
export function CredentialsModal({ open, onOpenChange, member, roleLabel }: Props) {
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
            <span className="text-sm font-semibold text-card-foreground">{member.name}</span>
          </Row>
          <Row label="Email">
            <div className="flex items-center gap-2">
              <span className="text-sm font-semibold text-card-foreground">{member.email}</span>
              <button
                type="button"
                onClick={async () => { if (await copy(member.email)) toast.success("Email copied"); }}
                className="text-muted-foreground hover:text-foreground"
                aria-label="Copy email"
              >
                <Copy className="h-3.5 w-3.5" />
              </button>
            </div>
          </Row>
          <Row label="Role">
            <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-secondary-foreground">
              {roleLabel}
            </span>
          </Row>
          <Row label="Zerem access">
            <StatusPill status={member.suspended ? "inactive" : "active"} />
          </Row>
          <p className="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
            The temporary password is shown only once, at account creation. Issuing a new
            one (Reset Password) is coming soon.
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
