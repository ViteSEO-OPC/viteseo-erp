"use client";

import { Moon, Sun } from "lucide-react";

import { useTheme } from "@/core/theme-provider";

/**
 * Platform-wide light/dark switch. Lives in the shared launcher header (and can
 * drop into any shell) so the theme is reachable from the site root, not only
 * inside the CRM. Token-based, so it themes itself.
 */
export function ThemeToggle({ className = "" }: { className?: string }) {
  const { theme, toggleTheme } = useTheme();
  const isDark = theme === "dark";

  return (
    <button
      type="button"
      onClick={toggleTheme}
      title={isDark ? "Switch to light mode" : "Switch to dark mode"}
      aria-label={isDark ? "Switch to light mode" : "Switch to dark mode"}
      aria-pressed={isDark}
      className={`flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground ${className}`}
    >
      {isDark ? <Sun className="size-4" /> : <Moon className="size-4" />}
    </button>
  );
}
