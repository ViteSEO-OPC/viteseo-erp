"use client";

import { useRouter } from "next/navigation";

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/modules/crm/components/ui/dropdown-menu";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { useAvatarUrl } from "@/modules/crm/features/profile/hooks/use-profile";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import { cn } from "@/modules/crm/lib/utils";
import { getAccessibleModules } from "@/core/access";

type UserMenuProps = {
  className?: string;
};

export function UserMenu({ className }: UserMenuProps) {
  const { user, logout } = useAuth();
  // The same `me.profile` cache entry Settings and Profile read, so uploading or removing a photo
  // updates this avatar on the same tick — no reload, and no second copy of the avatar state.
  const avatarUrl = useAvatarUrl();
  const router = useRouter();

  if (!user) return null;

  // Multi-system users get a "Switch app" jump back to the launcher.
  const canSwitchApp =
    getAccessibleModules(user.roles, user.permissions).length > 1;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger className={cn(
        "flex items-center gap-2 rounded-lg px-2 py-1 transition-colors",
        "hover:bg-muted",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        className,
      )}>
        <AvatarInitials src={avatarUrl} initials={user.name} size="sm" />
        <span className="hidden text-sm font-medium text-foreground sm:inline">
          {user.name}
        </span>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        {canSwitchApp && (
          <DropdownMenuItem onClick={() => router.push("/")}>
            Switch app
          </DropdownMenuItem>
        )}
        <DropdownMenuItem disabled>Profile</DropdownMenuItem>
        <DropdownMenuItem disabled>Settings</DropdownMenuItem>
        <DropdownMenuItem onClick={logout}>Sign out</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
