/**
 * How long a PMS query's data counts as fresh.
 *
 * The shared QueryClient (`core/query-provider.tsx`) sets no `staleTime`, so
 * every query would otherwise be stale the moment it resolves — refetching on
 * every mount (route change, modal open, remount) and every window focus. That
 * default belongs to the whole platform, so it is not this module's to change;
 * these constants apply the policy to the PMS hooks instead.
 *
 * The numbers assume the realtime layer: `useProjectChannel` patches the task
 * and status caches from broadcasts, so a board does not depend on refetching to
 * stay current. Where there is no channel (approvals, archives, workload) the
 * window is shorter, because a poll-on-mount is the only thing keeping it live.
 *
 * A hook that needs different behaviour still overrides it locally — the claim
 * pool (contested rows) and the assignee-picker indicator (must reflect the
 * change you just made) both do.
 */
export const STALE = {
  /** Contested or decision-driving data: fresh briefly, then re-read. */
  short: 15_000,
  /** The default for board/detail data kept live by the realtime channel. */
  standard: 60_000,
  /** Rarely-changing reference data: workspaces, groups, the user directory. */
  long: 5 * 60_000,
} as const;
