import axios from "axios";

declare module "axios" {
  interface AxiosRequestConfig {
    /**
     * Hand a 401/419 back to the caller instead of bouncing to /login. For the
     * sign-in probe on app load, which a signed-out visitor on a public page
     * (forgot / reset password) fails as a matter of course.
     */
    skipAuthRedirect?: boolean;
  }
}

const ENV_BASE_URL =
  process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";

/**
 * The API base URL. In the browser we target the SAME host the app was opened on
 * (localhost OR a LAN IP like 192.168.x.x) — keeping only the backend port/path from
 * the env value — so the app works over the network with no per-device config. On the
 * server (SSR/build) we use the env value as-is.
 */
function resolveBaseUrl(): string {
  if (typeof window === "undefined") return ENV_BASE_URL;
  try {
    const env = new URL(ENV_BASE_URL);
    // Only re-host localhost defaults; respect an env pointed at a real remote host.
    if (env.hostname === "localhost" || env.hostname === "127.0.0.1") {
      return `${window.location.protocol}//${window.location.hostname}:${env.port || "8000"}${env.pathname}`;
    }
    return ENV_BASE_URL;
  } catch {
    return ENV_BASE_URL;
  }
}

export const apiClient = axios.create({
  baseURL: resolveBaseUrl(),
  headers: { Accept: "application/json" },
  // The session is an httpOnly cookie: the browser sends it and script never sees
  // it. withCredentials sends it when the API is on another port (local dev);
  // withXSRFToken copies Laravel's XSRF-TOKEN cookie into the X-XSRF-TOKEN header
  // that every cookie-authenticated write needs.
  withCredentials: true,
  withXSRFToken: true,
});

// No Authorization header: there is no token for script to attach any more.
//
// No X-Socket-Id here either, on purpose. That header only matters to a caller
// whose writes are broadcast with ->toOthers(), which today is CRM alone, and it
// is set by the CRM client (modules/crm/lib/axios.ts). Keeping it out of the
// shared client keeps laravel-echo/pusher-js out of the HR and platform bundles.

// Global handling for a session that has ended — signed out elsewhere, two days
// idle, thirty days old, account closed: bounce to /login. One place covers every
// screen (plan §5.5d). A 419 means the same thing: the session died and took its
// CSRF token with it.
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    if (
      typeof window !== "undefined" &&
      (status === 401 || status === 419) &&
      !error.config?.skipAuthRedirect &&
      window.location.pathname !== "/login"
    ) {
      window.location.href = "/login";
    }
    return Promise.reject(error);
  },
);
