import type { JSONContent } from "@tiptap/react";
import { noteTargetId } from "@/modules/projects/lib/note-doc";
import type { NoteTarget } from "@/modules/projects/types/note";

/**
 * Cross-tab sync for a confirmed save.
 *
 * After the server acknowledges a save, the writing tab announces the new
 * version + document; other tabs holding the same note adopt it when their own
 * editor is idle (not focused, nothing pending). `storage` events cover
 * browsers without BroadcastChannel.
 */

export interface NoteBroadcast {
  version: number;
  content: JSONContent;
  /** Distinguishes our own echo from another tab's message. */
  senderId: string;
}

const STORAGE_PING_PREFIX = "pmnote:sync:";

function channelName(userId: number, target: NoteTarget): string {
  return `pmnote:${userId}:${noteTargetId(target)}`;
}

/** A per-tab id so a tab ignores the echo of its own message. */
export const TAB_ID =
  typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : `tab-${Math.random().toString(36).slice(2)}`;

export function publishNote(
  userId: number,
  target: NoteTarget,
  message: Omit<NoteBroadcast, "senderId">,
): void {
  if (typeof window === "undefined") return;
  const payload: NoteBroadcast = { ...message, senderId: TAB_ID };
  const name = channelName(userId, target);

  try {
    if ("BroadcastChannel" in window) {
      const channel = new BroadcastChannel(name);
      channel.postMessage(payload);
      channel.close();
      return;
    }
  } catch {
    /* fall through to the storage fallback */
  }

  try {
    // Writing a key fires `storage` in other tabs; the value carries the note.
    localStorage.setItem(
      `${STORAGE_PING_PREFIX}${name}`,
      JSON.stringify({ ...payload, at: Date.now() }),
    );
  } catch {
    /* ignore — sync is best-effort */
  }
}

/** Subscribe to other tabs' confirmed saves. Returns an unsubscribe function. */
export function subscribeToNote(
  userId: number,
  target: NoteTarget,
  onMessage: (message: NoteBroadcast) => void,
): () => void {
  if (typeof window === "undefined") return () => {};
  const name = channelName(userId, target);

  let channel: BroadcastChannel | null = null;
  try {
    if ("BroadcastChannel" in window) {
      channel = new BroadcastChannel(name);
      channel.onmessage = (event: MessageEvent<NoteBroadcast>) => {
        if (event.data?.senderId !== TAB_ID) onMessage(event.data);
      };
    }
  } catch {
    channel = null;
  }

  const onStorage = (event: StorageEvent): void => {
    if (event.key !== `${STORAGE_PING_PREFIX}${name}` || !event.newValue) return;
    try {
      const parsed = JSON.parse(event.newValue) as NoteBroadcast;
      if (parsed?.senderId !== TAB_ID) onMessage(parsed);
    } catch {
      /* ignore */
    }
  };
  window.addEventListener("storage", onStorage);

  return () => {
    channel?.close();
    window.removeEventListener("storage", onStorage);
  };
}
