// API functions for auth. These are the ONLY place that knows the auth URLs
// and unwraps the `{ data: ... }` envelope. Components/hooks call these.

import { apiClient } from "@/core/api-client";
import type { LoginResponse, MeResponse } from "@/core/types/auth";

export async function loginRequest(
  email: string,
  password: string,
): Promise<LoginResponse> {
  const res = await apiClient.post("/auth/login", { email, password });
  return res.data.data;
}

export async function fetchMe(): Promise<MeResponse> {
  const res = await apiClient.get("/auth/me");
  return res.data.data;
}

export async function logoutRequest(): Promise<void> {
  await apiClient.post("/auth/logout");
}
