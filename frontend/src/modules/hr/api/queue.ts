/**
 * The shared shape of a SERVER-PAGED queue endpoint.
 *
 * Every request queue in the module answers the same way — one page of rows plus how many match
 * across all of them — because they all read through the backend's PagesQueues trait. Kept in
 * one place so a screen cannot quietly assume `data.length` is the total, which is the habit
 * these endpoints exist to break.
 */
export interface QueueParams {
  page: number;
  per_page: number;
  search?: string;
  /** "all" means no filter, and is dropped rather than sent. */
  status?: string;
  /** Inclusive date window, for the queues that have a period picker. */
  from?: string;
  to?: string;
  /** One of the queue's own named orderings. Unrecognised values fall back to its default. */
  sort?: string;
}

/**
 * The inclusive date window a period picker sets. Both bounds are optional and independent, so
 * an open-ended window ("everything from March on") is expressible; `{}` means no window at all.
 */
export interface DateWindow {
  from?: string;
  to?: string;
}

export interface QueuePage<T> {
  items: T[];
  /** Rows matching the current search and filter across EVERY page — not `items.length`. */
  total: number;
}

/** Params as the API wants them: defaults and "all" omitted, so the URL stays readable. */
export function queueQuery(params: QueueParams): Record<string, string | number> {
  const { page, per_page, search, status, from, to, sort } = params;

  return {
    ...(page > 1 ? { page } : {}),
    per_page,
    ...(search ? { search } : {}),
    ...(status && status !== "all" ? { status } : {}),
    ...(from ? { from } : {}),
    ...(to ? { to } : {}),
    ...(sort ? { sort } : {}),
  };
}
