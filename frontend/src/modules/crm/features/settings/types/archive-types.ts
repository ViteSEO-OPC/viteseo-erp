/** Archive Management types — the shape of GET /leads/archived (soft-deleted leads). */

/** A short {id, name} reference (owning BD, or the user who archived a lead). */
export type UserRef = { id: string; name: string };

/** One archived (soft-deleted) lead row, as returned by ArchivedLeadResource. */
export type ArchivedLead = {
  id: string;
  business: string;
  contact: string | null;
  /** Frozen lead status enum value (new | interested | awaiting-reply | call-back-req | declined). */
  status: string;
  assignedToId: string | null;
  /** Owning BD, or null when the lead was never assigned. */
  assignedTo: UserRef | null;
  /** Soft-delete instant (`deleted_at`) — the single archive state. */
  archivedAt: string | null;
  /** Derived from the immutable `deleted` Activity causer; null if unknown. */
  archivedBy: UserRef | null;
};

/** The archive top-bar filter set (all optional). Sentinel "all"/"" values are dropped by the hook. */
export type ArchiveFilters = {
  search?: string;
  /** Calendar year of the archive date (deleted_at). */
  year?: string;
  /** Single frozen status value. */
  status?: string;
  /** Owning BD id, or the literal "unassigned". */
  assignedBd?: string;
  /** Id of the user who archived the lead. */
  archivedBy?: string;
};
