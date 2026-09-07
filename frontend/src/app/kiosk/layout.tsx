"use client";

import { RequireAuth } from "@/core/auth-context";
import { AppShell } from "@/core/components/app-shell";
import { KioskFontLoader } from "@/modules/kiosk/components/kiosk-font-loader";

/**
 * Kiosk portal shell — the internal website-configurator tool. Same dark sidebar
 * + top bar as the other portals, with the kiosk nav set. No SubscriptionGuard:
 * it's an internal Vite SEO tool, not a per-tenant sold product. KioskFontLoader
 * pulls in whatever Google Fonts the brand kits use so the preview is accurate.
 */
export default function KioskLayout({ children }: { children: React.ReactNode }) {
  return (
    <RequireAuth>
      <KioskFontLoader />
      <AppShell variant="kiosk">
        <div className="mx-auto w-full max-w-6xl px-6 py-8">{children}</div>
      </AppShell>
    </RequireAuth>
  );
}
