/**
 * WHY the Nearby list is empty — a fact, not a guess.
 *
 * Zero cards has several causes that look identical on screen and mean opposite things to a BD:
 * nothing has been discovered at all, the filters excluded everything, or one specific filter did.
 * The last case is the one that reads as a failure when it is not — selecting High immediately after
 * a discovery run legitimately shows nothing, because a freshly ingested business is `website-unknown`
 * until Website Intelligence audits it, and `website-unknown` derives to Medium.
 *
 * WHAT MAKES THE PRIORITY REASON RELIABLE. It is claimed only when removing the priority filter
 * ALONE would have produced results. If the other filters, or an empty pool, would have emptied the
 * list anyway, priority was not the cause and the existing message stands. That is why this takes a
 * count computed WITHOUT the priority predicate rather than inferring anything from the visible set.
 *
 * Pure so the gates harness can drive the decision directly.
 */

/** The distinguishable reasons a Nearby list has nothing to show. */
export type NearbyEmptyReason =
  /** Nothing discovered yet — the pool itself is empty. */
  | "pool-empty"
  /** The chosen priority is the only filter excluding everything. */
  | "priority-excluded-all"
  /** Some combination of filters excluded everything. */
  | "filters-excluded-all";

export type EmptyReasonInput = {
  /** How many rows the list is actually showing. */
  visibleCount: number;
  /** True when nothing has been discovered at all, regardless of filters. */
  poolIsEmpty: boolean;
  /** The selected priority label, or "" for no priority filter. */
  priorityFilter: string;
  /** How many rows would show if the priority filter alone were removed. */
  matchesIgnoringPriority: number;
};

/**
 * The reason, or `null` when the list has rows and no explanation is needed.
 *
 * Order matters: an empty pool outranks any filter, because telling someone their filter excluded
 * everything when nothing was ever discovered sends them to the wrong control.
 */
export function nearbyEmptyReason({
  visibleCount,
  poolIsEmpty,
  priorityFilter,
  matchesIgnoringPriority,
}: EmptyReasonInput): NearbyEmptyReason | null {
  if (visibleCount > 0) {
    return null;
  }

  if (poolIsEmpty) {
    return "pool-empty";
  }

  // Priority is named ONLY when dropping it would genuinely have shown something.
  if (priorityFilter !== "" && matchesIgnoringPriority > 0) {
    return "priority-excluded-all";
  }

  return "filters-excluded-all";
}

/**
 * The sentence shown for a reason.
 *
 * The priority sentence states the mechanism and deliberately does not say discovery failed — that
 * claim is what the whole helper exists to prevent. No count is quoted, because a count here would
 * be a second source of truth for something the list already shows.
 */
export function nearbyEmptyMessage(reason: NearbyEmptyReason, priorityFilter: string): string {
  switch (reason) {
    case "pool-empty":
      return "No discovered businesses yet. Run discovery to populate the Prospect Pool.";
    case "priority-excluded-all":
      return `No ${priorityFilter} Priority leads yet. Newly discovered leads start at Medium until Website Intelligence completes its audit.`;
    case "filters-excluded-all":
      return "No clients match these filters.";
  }
}
