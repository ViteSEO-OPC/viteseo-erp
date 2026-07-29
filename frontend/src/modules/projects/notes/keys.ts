import { noteTargetId, type NoteTarget } from "./types";

/**
 * Query keys for notes.
 *
 * The user note deliberately has ONE key regardless of where it's rendered, so
 * the dashboard card and the task-view panel share a single React Query cache
 * entry — editing on either surface updates the other with no refetch.
 */
export const noteKeys = {
  all: ["projects", "notes"] as const,
  note: (target: NoteTarget) =>
    ["projects", "notes", noteTargetId(target)] as const,
};
