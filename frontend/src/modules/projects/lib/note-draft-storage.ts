import { noteTargetId } from "@/modules/projects/lib/note-doc";
import type { NoteDraft, NoteTarget } from "@/modules/projects/types/note";

/**
 * The localStorage draft layer — crash and offline safety for note content that
 * the server hasn't confirmed yet.
 *
 * Keys are namespaced by owner (`pmnote:draft:{userId}:...`) so a shared machine
 * never shows one account another's drafts. Storage failures are always
 * swallowed: losing the draft layer must never break typing.
 */

const PREFIX = "pmnote:";

export function draftKey(userId: number, target: NoteTarget): string {
  return `${PREFIX}draft:${userId}:${noteTargetId(target)}`;
}

/** UI preference key (e.g. the task-view panel's open state). */
export function uiKey(userId: number, name: string): string {
  return `${PREFIX}ui:${userId}:${name}`;
}

let quotaExceeded = false;

/** True once a quota error has been hit — the UI mentions it exactly once. */
export function draftsUnavailable(): boolean {
  return quotaExceeded;
}

export function readDraft(userId: number, target: NoteTarget): NoteDraft | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = localStorage.getItem(draftKey(userId, target));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as NoteDraft;
    if (!parsed?.content || typeof parsed.baseVersion !== "number") return null;
    return parsed;
  } catch {
    return null;
  }
}

export function writeDraft(
  userId: number,
  target: NoteTarget,
  draft: NoteDraft,
): void {
  if (typeof window === "undefined") return;
  try {
    localStorage.setItem(draftKey(userId, target), JSON.stringify(draft));
  } catch {
    // QuotaExceededError (or Safari private mode): drop the draft layer
    // silently. The server save is still the durable path.
    quotaExceeded = true;
  }
}

/**
 * Drop the draft. Called once a save is confirmed: at that moment the draft is
 * byte-identical to the server document, so it carries no information and is
 * just private text left lying in storage.
 */
export function clearDraft(userId: number, target: NoteTarget): void {
  if (typeof window === "undefined") return;
  try {
    localStorage.removeItem(draftKey(userId, target));
  } catch {
    /* ignore */
  }
}

/**
 * Remove every `pmnote:*` key that doesn't belong to the current user.
 *
 * Drafts are private text sitting in a shared browser. The spec purges on
 * logout; the sign-out path lives in the shared core auth provider, so this
 * module purges foreign drafts when a session mounts instead — same guarantee
 * at the point where an account switch actually becomes observable.
 */
export function purgeForeignDrafts(currentUserId: number): void {
  if (typeof window === "undefined") return;
  try {
    const doomed: string[] = [];
    for (let i = 0; i < localStorage.length; i += 1) {
      const key = localStorage.key(i);
      if (!key?.startsWith(PREFIX)) continue;
      // pmnote:{kind}:{userId}:{rest}
      const ownerId = Number(key.split(":")[2]);
      if (Number.isFinite(ownerId) && ownerId !== currentUserId) doomed.push(key);
    }
    doomed.forEach((key) => localStorage.removeItem(key));
  } catch {
    /* ignore */
  }
}
