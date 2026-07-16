import { isAxiosError } from "axios";

/**
 * Extract a human-readable message from an API error.
 * Prefers the Laravel `{ message }` payload (incl. the 422 validation summary),
 * falling back to the provided default.
 */
export function getApiErrorMessage(err: unknown, fallback: string): string {
  if (isAxiosError(err)) {
    return err.response?.data?.message ?? fallback;
  }
  return fallback;
}
