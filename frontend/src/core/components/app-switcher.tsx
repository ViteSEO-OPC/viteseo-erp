"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Check, LayoutGrid } from "lucide-react";

import { cn } from "@/lib/utils";
import { useAuth } from "@/core/auth-context";
import { useFeatures } from "@/core/use-features";
import { getModuleTiles, type ModuleKey } from "@/core/access";
import type { ShellVariant } from "@/core/nav";

/** Which launcher module each shell variant belongs to (marks "you are here"). */
const VARIANT_MODULE: Record<ShellVariant, ModuleKey> = {
  hr: "hris",
  employee: "hris",
  kiosk: "kiosk",
  projects: "projects",
  crm: "crm",
  accounting: "accounting",
};

/**
 * The platform-standard app switcher: a grid icon in every portal header that
 * opens a dropdown of the systems this user may enter — a DIRECT jump (one
 * click), with "All apps" at the bottom for the full launcher.
 *
 * It replaces the "Switch app" row each portal used to hide in a different
 * place (TeamOra's sidebar user menu, Sprintly's sidebar footer, Zerem's
 * account dropdown). Same gate as before — `getModuleTiles` filtered to the
 * accessible ones — so nobody gains or loses a destination; only its position
 * is now the same everywhere. Hidden entirely for single-system users, exactly
 * as the old rows were.
 */
export function AppSwitcher({ current }: { current: ShellVariant | ModuleKey }) {
  const { roles, permissions } = useAuth();
  const { data: features } = useFeatures();
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  const currentKey: ModuleKey =
    current in VARIANT_MODULE
      ? VARIANT_MODULE[current as ShellVariant]
      : (current as ModuleKey);

  const modules = getModuleTiles(
    roles as unknown as string[],
    permissions,
    features?.modules,
  ).filter((t) => t.accessible);

  if (modules.length < 2) return null;

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-label="Switch app"
        aria-haspopup="menu"
        aria-expanded={open}
        title="Switch app"
        className="flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        <LayoutGrid className="size-5" />
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div
            role="menu"
            className="absolute right-0 top-full z-20 mt-2 w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-border bg-popover py-1 shadow-lg"
          >
            <p className="px-4 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              Switch app
            </p>
            {modules.map((m) => {
              const Icon = m.icon;
              const isCurrent = m.key === currentKey;
              return (
                <Link
                  key={m.key}
                  href={m.href}
                  role="menuitem"
                  aria-current={isCurrent ? "true" : undefined}
                  onClick={() => setOpen(false)}
                  className={cn(
                    "flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-muted",
                    isCurrent && "bg-muted/60",
                  )}
                >
                  <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-subtle text-brand">
                    <Icon className="size-4" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-foreground">
                      {m.title}
                    </span>
                    <span className="block truncate text-caption text-muted-foreground">
                      {m.subtitle}
                    </span>
                  </span>
                  {isCurrent && <Check className="size-4 shrink-0 text-brand" />}
                </Link>
              );
            })}
            <Link
              href="/"
              role="menuitem"
              onClick={() => setOpen(false)}
              className="mt-1 flex items-center gap-2 border-t border-border px-4 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
              <LayoutGrid className="size-4" />
              All apps
            </Link>
          </div>
        </>
      )}
    </div>
  );
}
