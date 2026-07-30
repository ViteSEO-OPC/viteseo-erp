"use client";

import { usePathname } from "next/navigation";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";
import { useAuth } from "@/core/auth-context";
import { usePersistentState } from "@/core/use-persistent-state";

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
  const { user } = useAuth();
  // Desktop rail state persists per user; the key stays null until the user
  // resolves (SSR-safe default = expanded), then loads that user's preference.
  const [collapsed, setCollapsed] = usePersistentState<boolean>(
    user ? `sidebar-collapsed:${user.id}` : null,
    false,
  );
  const [mobileOpen, setMobileOpen] = useState(false);
  const pathname = usePathname();

  const toggleCollapsed = useCallback(
    () => setCollapsed((prev) => !prev),
    [setCollapsed],
  );

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
