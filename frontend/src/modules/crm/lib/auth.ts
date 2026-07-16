// Unified platform token key — the shared auth-context stores the Sanctum token
// under `token`. The CRM module reuses the SAME key (no separate `sanctum_token`)
// so there is one session across HR / Projects / CRM.
const TOKEN_KEY = "token";
const USER_KEY = "crm_auth_user";

export function getStoredToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem(TOKEN_KEY);
}

export function storeToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token);
}

export function removeToken(): void {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}

export function getStoredUser(): { name: string; email: string; roles?: string[]; permissions?: string[] } | null {
  if (typeof window === "undefined") return null;
  const raw = localStorage.getItem(USER_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function storeUser(user: { name: string; email: string; roles?: string[]; permissions?: string[] }): void {
  localStorage.setItem(USER_KEY, JSON.stringify(user));
}
