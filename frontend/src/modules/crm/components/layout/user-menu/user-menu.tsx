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
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { cn } from "@/modules/crm/lib/utils";

type UserMenuProps = {
  className?: string;
};

export function UserMenu({ className }: UserMenuProps) {
  const { user, logout } = useAuth();
  const { canAccess } = usePermissions();
  // The same `me.profile` cache entry Settings and Profile read, so uploading or removing a photo
  // updates this avatar on the same tick — no reload, and no second copy of the avatar state.
  const avatarUrl = useAvatarUrl();
  const router = useRouter();

  if (!user) return null;

  /**
   * ONE self-service entry, resolved per role — the same rules the sidebar already uses.
   *
   * The CRM has no single account page: `/zerem/profile` is the BD's self-service profile
   * (role-exclusive, like Planner) and `/zerem/settings` is the Admin/Super Admin settings module.
   * They are MUTUALLY EXCLUSIVE, so separate "Profile" and "Settings" items could never both be
   * valid — whichever role was looking, one of them had no destination it could reach. That is why
   * both sat disabled. Worse, a forbidden route does not error: the section layout treats
   * `/zerem/dashboard` as the fallback for role-forbidden paths, so a wrong link would silently
   * bounce the user to the dashboard and read as broken.
   *
   * Resolving here mirrors sidebar-nav's "a user sees exactly one Settings entry" verbatim rather
   * than restating the rules, and `canAccess` honours both strategies (`allowedRoles` is an exact
   * allow-list, `minRole` inherits upward). A future role matching neither gets no item at all —
   * hidden, never a dead link.
   */
  const settingsHref = canAccess({ allowedRoles: ["crm_bd"] })
    ? "/zerem/profile"
    : canAccess({ minRole: "crm_admin" })
      ? "/zerem/settings"
      : null;

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
      {/* "Switch app" used to be the first item here. It is now the header's
          AppSwitcher — same gate (the accessible launcher tiles), same
          destinations, but in the position every other portal puts it in. */}
      <DropdownMenuContent align="end" className="w-48">
        {settingsHref && (
          <DropdownMenuItem onClick={() => router.push(settingsHref)}>
            Settings
          </DropdownMenuItem>
        )}
        <DropdownMenuItem onClick={logout}>Sign out</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
