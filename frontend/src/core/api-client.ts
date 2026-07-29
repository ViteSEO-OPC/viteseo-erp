import axios from "axios";

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
});

// Automatically attach the Sanctum token (saved at login) to every request.
//
// No X-Socket-Id here on purpose. That header only matters to a caller whose
// writes are broadcast with ->toOthers(), which today is CRM alone, and it is
// set by the CRM client (modules/crm/lib/axios.ts). Keeping it out of the shared
// client leaves HR and the platform sending byte-identical requests to before
// Reverb existed, and keeps laravel-echo/pusher-js out of their bundles.
apiClient.interceptors.request.use((config) => {
  if (typeof window !== "undefined") {
    const token = localStorage.getItem("token");
    if (token) config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Global 401 handling: an expired/revoked token means the session is dead —
// clear it and bounce to /login. One place covers every screen (plan §5.5d).
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (typeof window !== "undefined" && error.response?.status === 401) {
      localStorage.removeItem("token");
      if (window.location.pathname !== "/login") {
        window.location.href = "/login";
      }
    }
    return Promise.reject(error);
  },
);
