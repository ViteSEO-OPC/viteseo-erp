"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { isAxiosError } from "axios";
import { useState } from "react";

/**
 * Don't retry 4xx responses. A 401/403/404/422 is a definitive answer — the
 * request won't succeed by trying again — so retrying only delays the UI. Server/network errors (5xx, no
 * response) still get a couple of retries.
 */
function retry(failureCount: number, error: unknown): boolean {
  if (isAxiosError(error)) {
    const status = error.response?.status;
    if (status && status >= 400 && status < 500) return false;
  }
  return failureCount < 2;
}

export function QueryProvider({ children }: { children: React.ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: { retry },
        },
      }),
  );
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
