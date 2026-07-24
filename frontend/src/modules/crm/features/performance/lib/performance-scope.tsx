"use client";

import { createContext, useContext } from "react";

/**
 * The reporting scope the Performance widgets render for.
 *
 * - `self` : the logged-in BD's own portfolio — My Performance (the default, no provider needed).
 * - `all`  : the whole CRM aggregate — Reports & Performance "All Reports" (Admin/SA).
 * - `bd`   : one Business Development Manager — Reports & Performance drilled into a BD (Admin/SA).
 *
 * EXTENSION SEAM (future filters — Department / Branch / Date Range / Custom Reports): add fields to
 * this type, serialize them in {@link scopeToParams}, key them in {@link scopeKey}, and (if they change
 * wording) in {@link scopeCopy}. The widgets, hooks, and pages consume a `PerformanceScope` — they do
 * not read raw filter values — so a new filter never requires refactoring them.
 */
export type PerformanceScope =
  | { mode: "self" }
  | { mode: "all" }
  | { mode: "bd"; userId: string; name: string };

export const SELF_SCOPE: PerformanceScope = { mode: "self" };
export const ALL_SCOPE: PerformanceScope = { mode: "all" };

const PerformanceScopeContext = createContext<PerformanceScope>(SELF_SCOPE);

/** Wrap the Performance widgets to drive their data + copy from a scope (Reports & Performance uses this). */
export function PerformanceScopeProvider({
  scope,
  children,
}: {
  scope: PerformanceScope;
  children: React.ReactNode;
}) {
  return (
    <PerformanceScopeContext.Provider value={scope}>{children}</PerformanceScopeContext.Provider>
  );
}

/** The current scope. Defaults to `self` (My Performance renders with no provider → unchanged). */
export function usePerformanceScope(): PerformanceScope {
  return useContext(PerformanceScopeContext);
}

/**
 * The request params for a scope — the ONE place a scope becomes query-string params. Only an elevated
 * (specific-BD) scope sends anything; `self`/`all` send nothing (the backend resolves the requester's
 * own scope). Future filters append their params here.
 */
export function scopeToParams(scope: PerformanceScope): Record<string, string> {
  return scope.mode === "bd" ? { user_id: scope.userId } : {};
}

/** A stable cache-key fragment so "All Reports" and each BD cache separately (never collide). */
export function scopeKey(scope: PerformanceScope): string {
  return scope.mode === "bd" ? `bd:${scope.userId}` : scope.mode;
}

/** Scope-aware widget copy — management wording for `all`, BD-named wording for `bd`, and the original
 *  first-person wording for `self` (so My Performance is byte-identical). */
export type ScopeCopy = {
  conversionSubtitle: string;
  outreachSubtitle: string;
  targetSubtitle: string;
  recentTitle: string;
  recentSubtitle: string;
  assignedCaption: string;
};

export function scopeCopy(scope: PerformanceScope): ScopeCopy {
  if (scope.mode === "bd") {
    const name = scope.name;
    return {
      conversionSubtitle: `${name} · leads converted into won business`,
      outreachSubtitle: `Assigned leads ${name} has contacted by email or call.`,
      targetSubtitle: `Converted leads against ${name}'s monthly goal.`,
      recentTitle: `${name}'s Recent Activity`,
      recentSubtitle: `${name}'s latest actions`,
      assignedCaption: `Leads assigned to ${name}`,
    };
  }

  if (scope.mode === "all") {
    return {
      conversionSubtitle: "Leads converted into won business",
      outreachSubtitle: "Assigned leads contacted by email or call across the team.",
      targetSubtitle: "Converted leads against the team's monthly goal.",
      recentTitle: "Recent Activity",
      recentSubtitle: "Latest activity across the team",
      assignedCaption: "Leads assigned across the team",
    };
  }

  // self — the original My Performance wording, unchanged.
  return {
    conversionSubtitle: "Leads converted into won business",
    outreachSubtitle: "Assigned leads you have contacted by email or call.",
    targetSubtitle: "Converted leads against your monthly goal.",
    recentTitle: "My Recent Activity",
    recentSubtitle: "Your latest actions",
    assignedCaption: "Leads assigned to you",
  };
}
