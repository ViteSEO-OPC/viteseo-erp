"use client";

import { ArrowLeft } from "lucide-react";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import type { FollowUpItem } from "@/modules/crm/features/follow-ups/types/follow-ups-types";

type ComposerHeaderProps = {
  item: FollowUpItem;
  onBack?: () => void;
};

export function ComposerHeader({ item, onBack }: ComposerHeaderProps) {
  return (
    <div className="flex items-center gap-3 border-b border-border-subtle px-4 py-4 md:px-6">
      {onBack && (
        <button
          type="button"
          onClick={onBack}
          className="flex h-11 w-11 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
          aria-label="Back to inbox"
        >
          <ArrowLeft className="h-5 w-5" />
        </button>
      )}
      <span className="relative">
        <span className="absolute -left-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-teal-500 ring-2 ring-card" />
        <AvatarInitials initials={item.initials} size="sm" />
      </span>
      <div>
        <p className="text-base font-bold text-foreground">
          Email — {item.company}
        </p>
        <p className="text-xs text-muted-foreground">
          To: {item.name} · {item.email}
        </p>
      </div>
    </div>
  );
}
