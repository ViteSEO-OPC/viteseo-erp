"use client";

import { createContext, useCallback, useContext, useState } from "react";

import type { ShellVariant } from "@/core/nav";

/**
 * TeamOra view mode — lets an HR manager preview the app as an employee.
 *
 * The HR-vs-employee split is otherwise derived purely from the user's role, so
 * the "Switch to Employee view" control in the shared sidebar had nothing to
 * flip and just bounced back to the HR dashboard. This provider holds an
 * overridable `variant` that the shell, the `/teamora` layout, and the landing
 * page all read, so the toggle actually takes effect.
 *
 * Managers only: non-managers are always `employee` and `canSwitch` is false.
 * The override lives in memory — a full page reload returns a manager to their
 * default HR view, which matches "start in your own portal".
 */
interface ViewModeValue {
  /** Effective portal to render. */
  variant: ShellVariant;
  /** Whether the current user may switch views at all (managers only). */
  canSwitch: boolean;
  /** Flip HR ⇄ employee (no-op for non-managers). */
  toggle: () => void;
}

const ViewModeContext = createContext<ViewModeValue | null>(null);

export function ViewModeProvider({
  isManager,
  children,
}: {
  isManager: boolean;
  children: React.ReactNode;
}) {
  // null = follow the role default; a manager can override to "employee".
  const [override, setOverride] = useState<ShellVariant | null>(null);

  const variant: ShellVariant = isManager ? (override ?? "hr") : "employee";

  const toggle = useCallback(() => {
    if (!isManager) return;
    setOverride((current) => ((current ?? "hr") === "hr" ? "employee" : "hr"));
  }, [isManager]);

  return (
    <ViewModeContext.Provider value={{ variant, canSwitch: isManager, toggle }}>
      {children}
    </ViewModeContext.Provider>
  );
}

export function useViewMode(): ViewModeValue {
  const ctx = useContext(ViewModeContext);
  if (!ctx) {
    throw new Error("useViewMode must be used within a <ViewModeProvider>");
  }
  return ctx;
}
