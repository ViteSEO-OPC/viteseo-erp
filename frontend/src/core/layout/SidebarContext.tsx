"use client";

import { usePathname } from "next/navigation";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";

const STORAGE_KEY = "sidebar-collapsed";

type SidebarContextValue = {
  /** Desktop (≥ lg) icon-only rail state. Persisted to localStorage. */
  collapsed: boolean;
  toggleCollapsed: () => void;
  /** Mobile (< lg) off-canvas drawer open state. */
  mobileOpen: boolean;
  openMobile: () => void;
  closeMobile: () => void;
};

const SidebarContext = createContext<SidebarContextValue | null>(null);

export function SidebarProvider({ children }: { children: React.ReactNode }) {
  // Lazy initializer keeps SSR deterministic (false) and reads localStorage
  // only once we're on the client during the first render pass.
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const pathname = usePathname();

  // Read the persisted collapsed preference on mount (client only).
  useEffect(() => {
    try {
      const stored = window.localStorage.getItem(STORAGE_KEY);
      // Sync from an external system (localStorage) after mount to avoid an
      // SSR/client hydration mismatch on the persisted preference.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      if (stored !== null) setCollapsed(stored === "true");
    } catch {
      /* localStorage may be unavailable (private mode / SSR) — ignore */
    }
  }, []);

  const toggleCollapsed = useCallback(() => {
    setCollapsed((prev) => {
      const next = !prev;
      try {
        window.localStorage.setItem(STORAGE_KEY, String(next));
      } catch {
        /* ignore persistence failures */
      }
      return next;
    });
  }, []);

  const openMobile = useCallback(() => setMobileOpen(true), []);
  const closeMobile = useCallback(() => setMobileOpen(false), []);

  // Close the mobile drawer whenever the route changes.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setMobileOpen(false);
  }, [pathname]);

  // Close the mobile drawer on Escape.
  useEffect(() => {
    if (!mobileOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setMobileOpen(false);
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [mobileOpen]);

  return (
    <SidebarContext.Provider
      value={{
        collapsed,
        toggleCollapsed,
        mobileOpen,
        openMobile,
        closeMobile,
      }}
    >
      {children}
    </SidebarContext.Provider>
  );
}

export function useSidebar() {
  const ctx = useContext(SidebarContext);
  if (!ctx) {
    throw new Error("useSidebar must be used within a SidebarProvider");
  }
  return ctx;
}
