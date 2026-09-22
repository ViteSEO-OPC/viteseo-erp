/**
 * Laravel's CSRF token, read from the XSRF-TOKEN cookie it sets for this purpose.
 *
 * Deliberately NOT httpOnly: it is not a credential -- it proves only that a
 * request came from a page on this site -- and script has to read it to send it
 * back. axios does that by itself (withXSRFToken); this is for the two callers
 * that use fetch: the realtime authorizer and the keepalive note save.
 */
export function xsrfToken(): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
}
