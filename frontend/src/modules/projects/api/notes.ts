import type { JSONContent } from "@tiptap/react";
import apiClient from "@/core/api/client";
import { xsrfToken } from "@/core/csrf";
import type { ApiResource } from "@/core/types";
import type {
  Note,
  NoteConflict,
  NoteSaveResult,
  NoteTarget,
} from "@/modules/projects/types/note";

/** The read/write path for a target — always "mine"; no note id is ever sent. */
function pathFor(target: NoteTarget): string {
  return target.scope === "user"
    ? "/projects/notes/me"
    : `/projects/projects/${target.projectId}/notes/me`;
}

/** Fetch (the server lazily creates on first access, so this never 404s). */
export async function getNote(target: NoteTarget): Promise<Note> {
  const { data } = await apiClient.get<ApiResource<Note>>(pathFor(target));
  return data.data;
}

export async function saveNote(
  target: NoteTarget,
  payload: { content: JSONContent; base_version: number },
): Promise<NoteSaveResult> {
  const { data } = await apiClient.put<ApiResource<NoteSaveResult>>(
    pathFor(target),
    payload,
  );
  return data.data;
}

/** The 409 body, when the failure is a version conflict; null otherwise. */
export function conflictOf(error: unknown): NoteConflict | null {
  const response = (error as { response?: { status?: number; data?: unknown } })
    ?.response;
  if (response?.status !== 409) return null;

  const body = response.data as
    | { error?: string; data?: NoteConflict }
    | undefined;
  return body?.error === "version_conflict" && body.data ? body.data : null;
}

/** True for a permanent rejection — retrying the same payload can't help. */
export function isPermanentFailure(error: unknown): boolean {
  const status = (error as { response?: { status?: number } })?.response?.status;
  return status !== undefined && status >= 400 && status < 500 && status !== 409;
}

/**
 * A last-gasp save on page hide / unload.
 *
 * Uses `fetch(..., { keepalive: true })` because the browser cancels in-flight
 * XHRs once a document starts unloading. Keepalive bodies are capped at 64 KB —
 * a larger note falls back to the localStorage draft, which is recovered on the
 * next load.
 */
export function saveWithKeepalive(
  target: NoteTarget,
  payload: { content: JSONContent; base_version: number },
): boolean {
  if (typeof window === "undefined") return false;

  const body = JSON.stringify(payload);
  if (new Blob([body]).size > 64 * 1024) return false;

  // No XSRF cookie means no session to save into; the draft layer keeps it.
  const xsrf = xsrfToken();
  if (!xsrf) return false;

  const base = (apiClient.defaults.baseURL ?? "").replace(/\/$/, "");

  try {
    void fetch(`${base}${pathFor(target)}`, {
      method: "PUT",
      keepalive: true,
      // fetch sends no cookie across ports (local dev) and no XSRF header unless told.
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-XSRF-TOKEN": xsrf,
      },
      body,
    });
    return true;
  } catch {
    return false;
  }
}
