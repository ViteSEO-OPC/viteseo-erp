"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { LogOut, Repeat, User } from "lucide-react";

import { cn } from "@/lib/utils";
import { useAuth } from "@/core/auth-context";
import { initials, profileHref, roleLabel, type ShellVariant } from "@/core/nav";

/**
 * The platform-standard profile menu: an avatar button at the far right of
 * every portal header. One order everywhere — identity block, My Profile
 * (only in portals that HAVE a profile page), the portal's extra actions
 * (e.g. TeamOra's HR ⇄ Employee view switch), then Log out, always last and
 * always behind a separator so it can't be fat-fingered.
 *
 * This used to be a card pinned to the bottom of the sidebar, which meant
 * "where is my account?" had a different answer in every portal and no answer
 * at all once the sidebar collapsed to a rail.
 */
export function UserMenu({
  variant,
  canSwitchView = false,
  onSwitchView,
  avatar,
  profileHrefOverride,
}: {
  variant: ShellVariant;
  /** Offer the HR ⇄ employee view toggle (TeamOra decides via useViewMode). */
  canSwitchView?: boolean;
  onSwitchView?: () => void;
  /** The signed-in user's face when the portal has one; falls back to initials. */
  avatar?: React.ReactNode;
  /** Role-dependent profile destination — overrides profileHref[variant].
      Pass null to hide My Profile explicitly. */
  profileHrefOverride?: string | null;
}) {
  const { user, roles, employee, logout } = useAuth();
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  // `undefined` means "no override given" — null is a deliberate "this portal
  // has no profile destination for this user", so the two cannot be conflated.
  const myProfile =
    profileHrefOverride !== undefined ? profileHrefOverride : profileHref[variant];

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-label="Account menu"
        aria-haspopup="menu"
        aria-expanded={open}
        className={cn(
          "flex size-9 items-center justify-center rounded-full transition-opacity hover:opacity-85",
          open && "ring-2 ring-ring ring-offset-2 ring-offset-card",
        )}
      >
        {avatar ?? (
          <span className="flex size-9 items-center justify-center rounded-full bg-brand text-sm font-semibold text-brand-foreground">
            {user ? initials(user.name) : "?"}
          </span>
        )}
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div
            role="menu"
            className="absolute right-0 top-full z-20 mt-2 w-60 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-border bg-popover py-1 shadow-lg"
          >
            <div className="border-b border-border px-4 py-3">
              <p className="truncate text-sm font-semibold text-foreground">
                {user?.name ?? "—"}
              </p>
              <p className="truncate text-caption text-muted-foreground">
                {employee?.job_title ?? roleLabel(roles as unknown as string[])}
              </p>
            </div>

            {myProfile && (
              <Link
                href={myProfile}
                role="menuitem"
                onClick={() => setOpen(false)}
                className="flex items-center gap-2 px-4 py-2 text-sm text-foreground hover:bg-muted"
              >
                <User className="size-4" />
                My Profile
              </Link>
            )}

            {canSwitchView && onSwitchView && (
              <button
                type="button"
                role="menuitem"
                onClick={() => {
                  setOpen(false);
                  onSwitchView();
                }}
                className="flex w-full items-center gap-2 px-4 py-2 text-sm text-foreground hover:bg-muted"
              >
                <Repeat className="size-4" />
                Switch to {variant === "hr" ? "Employee" : "HR"} view
              </button>
            )}

            <button
              type="button"
              role="menuitem"
              onClick={() => {
                setOpen(false);
                logout();
              }}
              className="mt-1 flex w-full items-center gap-2 border-t border-border px-4 py-2 text-sm text-danger hover:bg-muted"
            >
              <LogOut className="size-4" />
              Log out
            </button>
          </div>
        </>
      )}
    </div>
  );
}
