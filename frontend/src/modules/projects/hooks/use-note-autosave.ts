"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import type { JSONContent } from "@tiptap/react";
import { useAuth } from "@/core/auth/AuthProvider";
import {
  conflictOf,
  getNote,
  isPermanentFailure,
  saveNote,
  saveWithKeepalive,
} from "@/modules/projects/api/notes";
import {
  publishNote,
  subscribeToNote,
} from "@/modules/projects/lib/note-broadcast";
import {
  clearDraft,
  draftsUnavailable,
  purgeForeignDrafts,
  readDraft,
  writeDraft,
} from "@/modules/projects/lib/note-draft-storage";
import { noteKeys } from "@/modules/projects/lib/query-keys";
import { withEditableRoot } from "@/modules/projects/lib/note-doc";
import type {
  Note,
  NoteConflict,
  NoteDraft,
  NoteTarget,
  SaveStatus,
} from "@/modules/projects/types/note";

/** Local (crash-safe) writes are cheap — keep them close behind the keystroke. */
const LOCAL_THROTTLE_MS = 300;
/** Durable saves wait for a pause in typing. */
const REMOTE_DEBOUNCE_MS = 1500;
const RETRY_BASE_MS = 2000;
const RETRY_MAX_MS = 30_000;

/** What hydration decided: the opening document, plus any draft follow-up. */
interface Hydration {
  initialContent: JSONContent;
  /** A draft built on an older version — offered back, never applied silently. */
  restorable: NoteDraft | null;
  /** A same-version draft to push to the server as soon as the engine is up. */
  resume: JSONContent | null;
}

/** Content pushed into the editor from outside (conflict resolve, other tab). */
export interface ExternalContent {
  content: JSONContent;
  /** Bumped on every push so the editor can react to identical content. */
  token: number;
}

export interface NoteAutosave {
  isLoading: boolean;
  /** The document to mount the editor with; null until hydration decides. */
  initialContent: JSONContent | null;
  /**
   * The newest document the engine knows about, including edits not yet saved.
   * Read at mount time by an editor that replaces another one for the same note
   * (the card's "Expand" modal, the mobile sheet) so no keystroke is dropped in
   * the hand-off.
   */
  getCurrent: () => JSONContent | null;
  status: SaveStatus;
  lastSavedAt: string | null;
  /** Set while a 409 is unresolved — autosaves are paused until it is. */
  conflict: NoteConflict | null;
  /** A local draft built on an older version, offered for restore. */
  restorableDraft: NoteDraft | null;
  /** True once localStorage refused a write (quota / private mode). */
  draftsUnavailable: boolean;
  externalContent: ExternalContent | null;

  onChange: (json: JSONContent) => void;
  /** Save now, bypassing the debounce (blur, tab hide, unmount, Cmd+S). */
  flush: () => void;
  setFocused: (focused: boolean) => void;
  resolveConflict: (choice: "server" | "mine") => void;
  restoreDraft: () => void;
  dismissDraft: () => void;
}

/**
 * The autosave engine (§5 of the spec).
 *
 * Two layers at different cadences: a throttled localStorage write that makes a
 * crash or a dead network survivable, and a debounced API save that is the
 * durable record. The server's `version` is an optimistic-concurrency token —
 * a save built on a stale version comes back 409 and pauses autosave until the
 * user picks a side.
 */
export function useNoteAutosave(target: NoteTarget): NoteAutosave {
  const { user } = useAuth();
  const userId = user?.id ?? 0;
  const queryClient = useQueryClient();

  // `target` is rebuilt by callers on each render; key off its parts so the
  // effects below don't resubscribe every time.
  const scope = target.scope;
  const projectId = target.projectId;
  const stableTarget = useMemo<NoteTarget>(
    () => ({ scope, projectId }),
    [scope, projectId],
  );

  const enabled = userId > 0 && (scope === "user" || Boolean(projectId));
  const queryKey = noteKeys.note(stableTarget);

  const query = useQuery({
    queryKey,
    queryFn: () => getNote(stableTarget),
    enabled,
    staleTime: 30_000,
  });

  const [status, setStatus] = useState<SaveStatus>("saved");
  const [lastSavedAt, setLastSavedAt] = useState<string | null>(null);
  const [conflict, setConflict] = useState<NoteConflict | null>(null);
  const [draftHandled, setDraftHandled] = useState(false);
  const [hydration, setHydration] = useState<Hydration | null>(null);
  const [externalContent, setExternalContent] =
    useState<ExternalContent | null>(null);

  // --- mutable engine state (refs: never drives rendering) ---
  const versionRef = useRef(1);
  const latestRef = useRef<JSONContent | null>(null);
  const dirtyRef = useRef(false);
  const inFlightRef = useRef(false);
  const queuedRef = useRef<JSONContent | null>(null);
  const pausedRef = useRef(false); // set by an unresolved conflict
  const focusedRef = useRef(false);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const retryRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const retryDelayRef = useRef(RETRY_BASE_MS);
  const throttleAtRef = useRef(0);
  const throttleRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const saveRef = useRef<(json: JSONContent) => void>(() => {});
  const hydratedRef = useRef(false);

  // Drafts are private text in a shared browser: drop any that belong to
  // another account as soon as this session is known.
  useEffect(() => {
    if (userId > 0) purgeForeignDrafts(userId);
  }, [userId]);

  const persistLocal = useCallback(
    (json: JSONContent) => {
      if (userId <= 0) return;
      const write = () =>
        writeDraft(userId, stableTarget, {
          content: json,
          baseVersion: versionRef.current,
          savedAt: Date.now(),
          dirty: true,
        });

      const now = Date.now();
      const elapsed = now - throttleAtRef.current;
      if (elapsed >= LOCAL_THROTTLE_MS) {
        throttleAtRef.current = now;
        write();
        return;
      }
      // Trailing edge — the last keystroke of a burst still lands.
      if (throttleRef.current) clearTimeout(throttleRef.current);
      throttleRef.current = setTimeout(() => {
        throttleAtRef.current = Date.now();
        write();
      }, LOCAL_THROTTLE_MS - elapsed);
    },
    [stableTarget, userId],
  );

  const save = useCallback(
    async (json: JSONContent): Promise<void> => {
      if (!enabled || pausedRef.current) return;

      // One save in flight at a time; a change mid-request is coalesced into a
      // single follow-up rather than queueing a burst.
      if (inFlightRef.current) {
        queuedRef.current = json;
        return;
      }

      inFlightRef.current = true;
      setStatus("saving");

      try {
        const result = await saveNote(stableTarget, {
          content: json,
          base_version: versionRef.current,
        });

        versionRef.current = result.version;
        setLastSavedAt(result.last_saved_at);
        retryDelayRef.current = RETRY_BASE_MS;
        if (userId > 0) clearDraft(userId, stableTarget);

        queryClient.setQueryData<Note>(queryKey, (old) =>
          old
            ? {
                ...old,
                content: json,
                version: result.version,
                last_saved_at: result.last_saved_at,
              }
            : old,
        );
        if (userId > 0) {
          publishNote(userId, stableTarget, {
            version: result.version,
            content: json,
          });
        }

        dirtyRef.current = queuedRef.current !== null;
        setStatus(dirtyRef.current ? "dirty" : "saved");
      } catch (error) {
        const versionConflict = conflictOf(error);
        if (versionConflict) {
          pausedRef.current = true;
          setConflict(versionConflict);
          setStatus("conflict");
        } else if (isPermanentFailure(error)) {
          // 403 (removed from the project) / 404 (project deleted) / 422 —
          // retrying the same document can't succeed. The draft stays on disk
          // so the text is never lost, and the badge stops claiming "saving".
          setStatus("offline");
        } else {
          setStatus("offline");
          if (retryRef.current) clearTimeout(retryRef.current);
          retryRef.current = setTimeout(() => {
            retryRef.current = null;
            saveRef.current(latestRef.current ?? json);
          }, retryDelayRef.current);
          retryDelayRef.current = Math.min(
            retryDelayRef.current * 2,
            RETRY_MAX_MS,
          );
        }
      } finally {
        inFlightRef.current = false;
        const queued = queuedRef.current;
        queuedRef.current = null;
        if (queued && !pausedRef.current) saveRef.current(queued);
      }
    },
    [enabled, queryClient, queryKey, stableTarget, userId],
  );

  useEffect(() => {
    saveRef.current = (json) => void save(json);
  }, [save]);

  // --- hydration (§5.3): server note + local draft decide the opening doc ---
  useEffect(() => {
    if (hydratedRef.current || !query.data || userId <= 0) return;
    hydratedRef.current = true;

    const server = query.data;
    versionRef.current = server.version;

    const draft = readDraft(userId, stableTarget);
    let next: Hydration;

    if (!draft || !draft.dirty) {
      next = {
        initialContent: withEditableRoot(server.content),
        restorable: null,
        resume: null,
      };
    } else if (draft.baseVersion === server.version) {
      // The user's own unsent work against the version we just loaded —
      // restore it silently and get it to the server.
      latestRef.current = draft.content;
      dirtyRef.current = true;
      next = {
        initialContent: withEditableRoot(draft.content),
        restorable: null,
        resume: draft.content,
      };
    } else {
      // The draft was built on an older version: don't clobber what the server
      // has. Show the server document and offer the draft back explicitly.
      next = {
        initialContent: withEditableRoot(server.content),
        restorable: draft,
        resume: null,
      };
    }

    // Two external sources — the fetched note and the browser's stored draft —
    // collapsed once into the document the editor mounts on.
    setHydration(next);

    if (next.resume) saveRef.current(next.resume);
  }, [query.data, stableTarget, userId]);

  const initialContent = hydration?.initialContent ?? null;
  const restorableDraft = draftHandled ? null : (hydration?.restorable ?? null);
  // Server timestamp, until a save of our own replaces it.
  const serverSavedAt = query.data?.last_saved_at ?? null;

  // --- other tabs' confirmed saves ---
  useEffect(() => {
    if (!enabled || userId <= 0) return;
    return subscribeToNote(userId, stableTarget, (message) => {
      // Never overwrite work in progress; the focused/dirty tab wins locally
      // and its own save will arbitrate on the server.
      if (dirtyRef.current || focusedRef.current || pausedRef.current) return;
      if (message.version <= versionRef.current) return;

      versionRef.current = message.version;
      latestRef.current = message.content;
      queryClient.setQueryData<Note>(queryKey, (old) =>
        old
          ? { ...old, content: message.content, version: message.version }
          : old,
      );
      setExternalContent({ content: message.content, token: Date.now() });
      setStatus("saved");
    });
  }, [enabled, queryClient, queryKey, stableTarget, userId]);

  const flush = useCallback(() => {
    if (debounceRef.current) {
      clearTimeout(debounceRef.current);
      debounceRef.current = null;
    }
    if (!dirtyRef.current || pausedRef.current) return;
    const json = latestRef.current;
    if (json) saveRef.current(json);
  }, []);

  const onChange = useCallback(
    (json: JSONContent) => {
      latestRef.current = json;
      dirtyRef.current = true;
      if (!pausedRef.current) setStatus("dirty");

      persistLocal(json);

      if (pausedRef.current) return;
      if (debounceRef.current) clearTimeout(debounceRef.current);
      debounceRef.current = setTimeout(() => {
        debounceRef.current = null;
        saveRef.current(json);
      }, REMOTE_DEBOUNCE_MS);
    },
    [persistLocal],
  );

  // --- flush triggers: tab hidden, unload, unmount; retry when back online ---
  useEffect(() => {
    if (!enabled) return;

    const onHide = () => {
      if (document.visibilityState === "hidden") flush();
    };
    const onUnload = () => {
      if (!dirtyRef.current || pausedRef.current || !latestRef.current) return;
      // XHRs are cancelled during unload — keepalive fetch is the only way to
      // get the last edit out. Oversized payloads fall back to the draft.
      saveWithKeepalive(stableTarget, {
        content: latestRef.current,
        base_version: versionRef.current,
      });
    };
    const onOnline = () => {
      if (dirtyRef.current && !pausedRef.current) {
        retryDelayRef.current = RETRY_BASE_MS;
        flush();
      }
    };

    document.addEventListener("visibilitychange", onHide);
    window.addEventListener("beforeunload", onUnload);
    window.addEventListener("online", onOnline);
    return () => {
      document.removeEventListener("visibilitychange", onHide);
      window.removeEventListener("beforeunload", onUnload);
      window.removeEventListener("online", onOnline);
    };
  }, [enabled, flush, stableTarget]);

  // Unmount (including a route change) is a flush trigger too.
  useEffect(
    () => () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
      if (throttleRef.current) clearTimeout(throttleRef.current);
      if (retryRef.current) clearTimeout(retryRef.current);
      if (dirtyRef.current && !pausedRef.current && latestRef.current) {
        void saveNote(stableTarget, {
          content: latestRef.current,
          base_version: versionRef.current,
        }).catch(() => {
          /* the draft on disk is the safety net */
        });
      }
    },
    [stableTarget],
  );

  const setFocused = useCallback((focused: boolean) => {
    focusedRef.current = focused;
  }, []);

  const resolveConflict = useCallback(
    (choice: "server" | "mine") => {
      const current = conflict;
      if (!current) return;

      // Either way the client is now based on the server's version.
      versionRef.current = current.version;
      pausedRef.current = false;
      setConflict(null);

      if (choice === "server") {
        latestRef.current = current.content;
        dirtyRef.current = false;
        if (userId > 0) clearDraft(userId, stableTarget);
        queryClient.setQueryData<Note>(queryKey, (old) =>
          old
            ? { ...old, content: current.content, version: current.version }
            : old,
        );
        setExternalContent({ content: current.content, token: Date.now() });
        setStatus("saved");
        return;
      }

      // "Keep mine": re-send the local document rebased onto the new version.
      const mine = latestRef.current;
      if (mine) {
        dirtyRef.current = true;
        setStatus("dirty");
        saveRef.current(mine);
      } else {
        setStatus("saved");
      }
    },
    [conflict, queryClient, queryKey, stableTarget, userId],
  );

  const restoreDraft = useCallback(() => {
    const draft = restorableDraft;
    if (!draft) return;
    setDraftHandled(true);
    latestRef.current = draft.content;
    dirtyRef.current = true;
    setStatus("dirty");
    setExternalContent({ content: draft.content, token: Date.now() });
    saveRef.current(draft.content);
  }, [restorableDraft]);

  const dismissDraft = useCallback(() => {
    setDraftHandled(true);
    if (userId > 0) clearDraft(userId, stableTarget);
  }, [stableTarget, userId]);

  const getCurrent = useCallback(
    () => latestRef.current ?? initialContent,
    [initialContent],
  );

  return {
    isLoading: enabled && query.isLoading,
    initialContent,
    getCurrent,
    status,
    // Our own saves win; before the first one, the server's stamp stands.
    lastSavedAt: lastSavedAt ?? serverSavedAt,
    conflict,
    restorableDraft,
    draftsUnavailable: draftsUnavailable(),
    externalContent,
    onChange,
    flush,
    setFocused,
    resolveConflict,
    restoreDraft,
    dismissDraft,
  };
}

/** Exposed for the toggle button's "unsaved" dot when the panel is closed. */
export function useNoteHasUnsavedDraft(target: NoteTarget): boolean {
  const { user } = useAuth();
  const userId = user?.id ?? 0;
  const [dirty, setDirty] = useState(false);
  const scope = target.scope;
  const projectId = target.projectId;

  useEffect(() => {
    if (userId <= 0) return;
    const check = () =>
      setDirty(Boolean(readDraft(userId, { scope, projectId })?.dirty));
    check();

    // `storage` covers OTHER tabs; the interval is what catches this tab's own
    // writes, so it only needs to run while this tab is the one being typed in.
    let id: ReturnType<typeof setInterval> | undefined;
    const start = () => {
      id ??= setInterval(check, 2000);
    };
    const stop = () => {
      if (id !== undefined) clearInterval(id);
      id = undefined;
    };
    const onVisibility = () => {
      if (document.hidden) stop();
      else {
        check();
        start();
      }
    };

    if (!document.hidden) start();
    document.addEventListener("visibilitychange", onVisibility);
    window.addEventListener("storage", check);
    return () => {
      stop();
      document.removeEventListener("visibilitychange", onVisibility);
      window.removeEventListener("storage", check);
    };
  }, [projectId, scope, userId]);

  return dirty;
}
