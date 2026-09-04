"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";

import { useAuth } from "@/core/auth-context";
import { useNotifications } from "@/core/use-notifications";
import type { NotificationItem } from "@/core/types/notifications";

type CenterItem = NotificationItem & { read: boolean };

interface NotificationCenter {
  items: CenterItem[];
  /** Unread count (bell badge). */
  total: number;
  /** Unread count per sidebar href. */
  navBadges: Record<string, number>;
  markRead: (id: string) => void;
  dismiss: (id: string) => void;
  markAllRead: () => void;
  /** Whether more items exist beyond the current page. */
  hasMore: boolean;
  /** Fetch the next page of notifications. */
  loadMore: () => void;
}

const Ctx = createContext<NotificationCenter | null>(null);

// Read/dismissed state lives client-side (no notifications table — Tier 2),
// namespaced per user so shared browsers don't leak state between accounts.
export function NotificationProvider({
  children,
  enabled = true,
}: {
  children: React.ReactNode;
  /** False in portals with no HR feed — the context still exists (the shared
      TopBar/Sidebar always read it) but nothing is fetched. */
  enabled?: boolean;
}) {
  const { user } = useAuth();
  const [limit, setLimit] = useState(20);
  const { data } = useNotifications(limit, 0, enabled);

  const [readIds, setReadIds] = useState<Set<string>>(new Set());
  const [dismissedIds, setDismissedIds] = useState<Set<string>>(new Set());

  const readKey = user ? `notif_read_${user.id}` : null;
  const dismissKey = user ? `notif_dismissed_${user.id}` : null;

  useEffect(() => {
    if (!readKey || !dismissKey) return;
    try {
      // localStorage is an external system read after mount (never during SSR),
      // which is exactly what an effect is for — the keys only become real once
      // the signed-in user resolves.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setReadIds(new Set(JSON.parse(localStorage.getItem(readKey) ?? "[]")));
      setDismissedIds(new Set(JSON.parse(localStorage.getItem(dismissKey) ?? "[]")));
    } catch {
      // ignore corrupt storage
    }
  }, [readKey, dismissKey]);

  const markRead = useCallback(
    (id: string) => {
      setReadIds((prev) => {
        const next = new Set(prev).add(id);
        if (readKey) localStorage.setItem(readKey, JSON.stringify([...next]));
        return next;
      });
    },
    [readKey],
  );

  const dismiss = useCallback(
    (id: string) => {
      setDismissedIds((prev) => {
        const next = new Set(prev).add(id);
        if (dismissKey) localStorage.setItem(dismissKey, JSON.stringify([...next]));
        return next;
      });
    },
    [dismissKey],
  );

  const serverItems = useMemo(() => data?.items ?? [], [data]);

  const markAllRead = useCallback(() => {
    setReadIds((prev) => {
      const next = new Set(prev);
      serverItems.forEach((i) => next.add(i.id));
      if (readKey) localStorage.setItem(readKey, JSON.stringify([...next]));
      return next;
    });
  }, [serverItems, readKey]);

  const items = useMemo(
    () =>
      serverItems
        .filter((i) => !dismissedIds.has(i.id))
        .map((i) => ({ ...i, read: readIds.has(i.id) })),
    [serverItems, dismissedIds, readIds],
  );

  const total = items.filter((i) => !i.read).length;

  const navBadges = useMemo(() => {
    const badges: Record<string, number> = {};
    for (const i of items) {
      if (!i.read) badges[i.href] = (badges[i.href] ?? 0) + 1;
    }
    return badges;
  }, [items]);

  const hasMore = data?.has_more ?? false;

  const loadMore = useCallback(() => {
    setLimit((prev) => prev + 20);
  }, []);

  return (
    <Ctx.Provider value={{ items, total, navBadges, markRead, dismiss, markAllRead, hasMore, loadMore }}>
      {children}
    </Ctx.Provider>
  );
}

export function useNotificationCenter(): NotificationCenter {
  const ctx = useContext(Ctx);
  if (!ctx) {
    throw new Error("useNotificationCenter must be used within a NotificationProvider");
  }
  return ctx;
}
