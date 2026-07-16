"use client";

import { type ReactNode } from "react";
import { Toaster } from "sonner";
import { ThemeProvider } from "@/modules/crm/providers/theme-provider";
import { QueryProvider } from "@/modules/crm/providers/query-provider";
import { AuthProvider } from "@/modules/crm/providers/auth-provider";
import { PermissionsProvider } from "@/modules/crm/providers/permissions-provider";

export function Providers({ children }: { children: ReactNode }) {
  return (
    <QueryProvider>
      <ThemeProvider>
        <AuthProvider>
          <PermissionsProvider>{children}</PermissionsProvider>
        </AuthProvider>
      </ThemeProvider>
      {/* App-wide toast host — success/error feedback for Lead CRUD mutations. */}
      <Toaster position="top-right" richColors closeButton />
    </QueryProvider>
  );
}
