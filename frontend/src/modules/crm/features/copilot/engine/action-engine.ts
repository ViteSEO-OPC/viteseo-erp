import type { ActionConfig } from "@/modules/crm/features/copilot/engine/types";

/**
 * Routes the SPA actually serves. Navigation to anything outside this allowlist is treated as
 * "not available yet" and skipped rather than pushed — so a pre-wired-but-unbuilt destination
 * never produces a hard 404. Extend this list as new routes ship; no engine change is required.
 * (Kept in sync with app/(dashboard)/* and the app-shell PAGE_TITLES map.)
 */
export const KNOWN_ROUTES = [
  "/dashboard",
  "/leads",
  "/follow-ups",
  "/my-performance",
  "/planner",
  "/reports",
  "/activity-logs",
  "/settings",
  "/email-drafts",
] as const;

/** Injected navigator — keeps the engine decoupled from Next's router (the hook wires it up). */
export type Navigate = (href: string) => void;

export type ActionOutcome = {
  performed: boolean;
  unavailableTarget?: string; // set when a navigate was skipped because the route is unknown
};

/** True when `target` (path portion, query ignored) is a route the app currently serves. */
export function isKnownRoute(target: string): boolean {
  const path = target.split("?")[0] ?? target;
  return KNOWN_ROUTES.some((route) => path === route || path.startsWith(`${route}/`));
}

/**
 * Execute a response's optional action. The only side-effect the engine performs is client-side
 * navigation (guarded by the known-route allowlist); everything else is a no-op. Presentation is
 * never touched here — the UI decides what to do with the outcome.
 */
export function runAction(action: ActionConfig | undefined, navigate: Navigate): ActionOutcome {
  if (!action || action.type === "none" || !action.target) {
    return { performed: false };
  }

  if (action.type === "navigate") {
    if (!isKnownRoute(action.target)) {
      return { performed: false, unavailableTarget: action.target };
    }
    navigate(action.target);
    return { performed: true };
  }

  return { performed: false };
}
