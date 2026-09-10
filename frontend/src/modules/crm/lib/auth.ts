// The CRM caches a copy of the signed-in user for its own header. The session
// itself is an httpOnly cookie owned by the shared auth, which script never sees.
const LEGACY_TOKEN_KEY = "token";
const USER_KEY = "crm_auth_user";

/** Drop what the CRM cached about the signed-in user, once the session has ended. */
export function clearCachedUser(): void {
  localStorage.removeItem(LEGACY_TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}
