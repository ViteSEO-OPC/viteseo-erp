// Unified with the platform's shared api-client + auth-context, which read/write
// the Sanctum token under the "token" key. Keep this in sync — it is the single
// source of the bearer token for every module.
export const TOKEN_KEY = 'token';

export const getToken = (): string | null => {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem(TOKEN_KEY);
};

export const setToken = (token: string): void => {
  localStorage.setItem(TOKEN_KEY, token);
};

export const removeToken = (): void => {
  localStorage.removeItem(TOKEN_KEY);
};
