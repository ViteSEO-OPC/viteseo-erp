/**
 * How long a CRM query's data counts as fresh.
 *
 * The shared QueryClient (`core/query-provider.tsx`) sets no `staleTime`, so every query is stale
 * the moment it resolves — refetching on every mount (route change, modal open, remount) and every
 * window focus. That default belongs to the whole platform, so it is not this module's to change;
 * this constant applies the policy to the CRM hooks instead, exactly as `modules/projects` does
 * with its own `query-freshness.ts`.
 *
 * NOTE: this is NOT the dead `modules/crm/providers/query-provider.tsx`. That file builds a second
 * QueryClient that nothing mounts; adopting it would split the CRM's cache from the platform's and
 * would also replace the root client's 4xx-aware `retry` with a flat `retry: 1`. The freshness
 * policy is worth having, the rest of that file is not.
 *
 * `refetchOnWindowFocus` stays ON. TanStack gates it — and `refetchOnMount` — behind `isStale()`
 * (see `queryObserver.js`: `shouldFetchOn` → `isStale`), so a `staleTime` alone suppresses the
 * redundant bursts while leaving focus-refetch as the freshness backstop for a tab that has been
 * sitting in the background. Realtime covers lead assignment and notifications; it does not cover
 * KPIs, the planner, the activity feed or the leads list, so that backstop still earns its place.
 */
export const STALE = {
  /**
   * The default for the shell-level queries that are mounted on every CRM screen — sidebar badges,
   * the notification bell, the topbar avatar and the Copilot's context.
   *
   * 30s is chosen against how these surfaces already stay current: every CRM mutation invalidates
   * the keys it touches (and invalidation ignores `staleTime`), and the realtime listeners patch
   * the notification and lead caches directly. This window therefore only ever suppresses a
   * refetch of data that nothing has reported a change to.
   */
  standard: 30_000,
} as const;
