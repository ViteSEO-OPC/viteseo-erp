"use client";

import { isAxiosError, isCancel } from "axios";
import { notify } from "@/core/lib/notify";

/**
 * Pull the useful sentence out of a Laravel error envelope.
 *
 * The API answers `{ message, errors }`, where `errors` is keyed by field. The
 * first field error is almost always the specific thing that went wrong ("The
 * due date can't be in the past."), so it beats the generic `message` that sits
 * beside it ("The given data was invalid.").
 */
export function getApiErrorMessage(error: unknown, fallback: string): string {
  if (!isAxiosError(error)) return fallback;

  // No response at all — the request never landed.
  if (!error.response) {
    return "Couldn't reach the server. Check your connection and try again.";
  }

  const data = error.response.data as
    | { message?: string; errors?: Record<string, string[]> }
    | undefined;

  const firstFieldError = data?.errors
    ? Object.values(data.errors)[0]?.[0]
    : undefined;

  if (firstFieldError) return firstFieldError;
  if (data?.message) return data.message;

  if (error.response.status === 403) {
    return "You don't have permission to do that.";
  }

  return fallback;
}

/**
 * The module's default `onError` for a mutation.
 *
 * Every mutation in this module used to fail in silence — a rejected request
 * rolled the UI back or simply did nothing, and the person who pressed the
 * button was left to guess. This gives all of them a floor: say what went
 * wrong, in the words the API used.
 *
 * A handful of call sites deliberately opt out because they can say something
 * better — claiming a task turns a 409 into "X took it a moment ago", removing
 * a member turns one into the blocking-work dialog, and the task importer
 * renders failures inside its own preview. Those pass no handler rather than
 * stacking a second, vaguer toast on top of a good one.
 *
 * @param title Short, specific heading — "Couldn't delete that task".
 */
export function notifyMutationError(title: string) {
  return (error: unknown): void => {
    // The user aborted it (navigating away, cancelling an upload).
    if (isCancel(error)) return;

    // 401 is already handled globally: the shared api-client clears the token
    // and sends them to /login. A toast on the way out is noise.
    if (isAxiosError(error) && error.response?.status === 401) return;

    notify.error(title, getApiErrorMessage(error, "Please try again in a moment."));
  };
}
