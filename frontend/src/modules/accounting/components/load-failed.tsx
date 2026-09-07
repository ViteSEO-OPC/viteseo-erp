/**
 * Shown when a query FAILED, in place of the list or figures it would have
 * rendered.
 *
 * It exists because the alternative is the defect that hid three whole screens
 * for two days: a failed request falling through to the same empty state as a
 * successful one, so "this is broken" and "you have not started yet" look
 * identical. A 403 from a feature gate, a 429 from the reports throttle, a 500
 * from a missing table — all of them read as an empty, healthy screen.
 */
export function LoadFailed({ what }: { what: string }) {
  return (
    <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
      Couldn’t load {what}. Please try again.
    </p>
  );
}
