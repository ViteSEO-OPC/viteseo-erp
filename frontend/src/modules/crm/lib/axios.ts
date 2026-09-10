import axios from "axios";
import { clearCachedUser } from "@/modules/crm/lib/auth";
import { getSocketId } from "@/core/realtime/echo";

/**
 * CRM module API client.
 *
 * In the unified platform there is ONE backend and ONE session (an httpOnly
 * cookie the shared auth signs in with). This instance targets the CRM endpoint
 * namespace `/api/v1/crm/*`, so module code keeps calling relative paths like
 * `/leads`, `/dashboard/kpis`, `/planner-events` unchanged — they resolve to
 * `/api/v1/crm/...`. Auth (login/logout/me) is NOT called here; the platform's
 * shared auth-context owns it.
 */
const ENV_BASE_URL =
  process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";

function resolveBaseUrl(): string {
  // Same host-rewrite behaviour as the shared core api-client, with the `/crm`
  // module namespace appended.
  const withCrm = (base: string) => `${base.replace(/\/$/, "")}/crm`;
  if (typeof window === "undefined") return withCrm(ENV_BASE_URL);
  try {
    const env = new URL(ENV_BASE_URL);
    if (env.hostname === "localhost" || env.hostname === "127.0.0.1") {
      return withCrm(
        `${window.location.protocol}//${window.location.hostname}:${env.port || "8000"}${env.pathname}`,
      );
    }
    return withCrm(ENV_BASE_URL);
  } catch {
    return withCrm(ENV_BASE_URL);
  }
}

export const apiClient = axios.create({
  baseURL: resolveBaseUrl(),
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
  timeout: 30000,
  // Same contract as the shared core client: the browser sends the httpOnly
  // session cookie, and axios copies XSRF-TOKEN into the header writes need.
  withCredentials: true,
  withXSRFToken: true,
});

apiClient.interceptors.request.use((config) => {
  // Same contract as the shared core client: identifies the originating socket
  // so a ->toOthers() broadcast skips the actor's own connection.
  const socketId = getSocketId();
  if (socketId) {
    config.headers["X-Socket-Id"] = socketId;
  }
  return config;
});

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    // 419: the session died and took its CSRF token with it -- the same as 401.
    if (status === 401 || status === 419) {
      clearCachedUser();
      if (typeof window !== "undefined") {
        window.location.href = "/login";
      }
    }
    return Promise.reject(error);
  },
);
