"use client";

import { Moon, Sun } from "lucide-react";

import { useTheme } from "@/core/theme-provider";

/**
 * Platform-wide light/dark switch. Lives in the shared launcher header (and can
 * drop into any shell) so the theme is reachable from the site root, not only
 * inside the CRM. Token-based, so it themes itself.
 */
export function ThemeToggle({ className = "" }: { className?: string }) {
  const { toggleTheme } = useTheme();

  return (
    <button
      type="button"
      onClick={toggleTheme}
      // Stable on both sides for the same reason as the icons below.
      title="Switch between light and dark mode"
      aria-label="Switch between light and dark mode"
      className={`flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground ${className}`}
    >
      {/*
        Which icon shows is decided by CSS, not by React state.

        The theme lives in localStorage, which the server cannot read, so rendering
        the icon from state made the server and the client disagree — a hydration
        mismatch React answers by throwing this whole tree away and rebuilding it,
        on the LOGIN PAGE, the first screen anyone sees.

        The blocking script has already put `.dark` on <html> before hydration, so
        CSS knows the answer while React still does not. Same markup both sides,
        right icon immediately, nothing to reconcile.
      */}
      <Moon className="size-4 dark:hidden" aria-hidden="true" />
      <Sun className="hidden size-4 dark:block" aria-hidden="true" />
    </button>
  );
}
