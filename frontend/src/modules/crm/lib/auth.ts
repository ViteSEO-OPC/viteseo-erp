// Unified platform token key — the shared auth-context stores the Sanctum token
// under `token`. The CRM module reuses the SAME key (no separate `sanctum_token`)
// so there is one session across HR / Projects / CRM.
const TOKEN_KEY = "token";
const USER_KEY = "crm_auth_user";

export function getStoredToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem(TOKEN_KEY);
}

export function removeToken(): void {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}
