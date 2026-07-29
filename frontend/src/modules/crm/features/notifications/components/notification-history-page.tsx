"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Check, RotateCcw } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/modules/crm/components/ui/select";
import { SearchInput } from "@/modules/crm/components/shared/search-input/search-input";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { cn } from "@/modules/crm/lib/utils";
import { NotificationItem } from "@/modules/crm/features/notifications/components/notification-item";
import { useNotificationHistory } from "@/modules/crm/features/notifications/hooks/use-notification-history";
import {
  useMarkAllRead,
  useMarkRead,
  useMarkUnread,
  useUnreadCount,
} from "@/modules/crm/features/notifications/hooks/use-notifications";
import {
  NOTIFICATION_TYPE_OPTIONS,
  type NotificationDto,
} from "@/modules/crm/features/notifications/types/notification-types";

/** Sentinel for "no type filter" — Select has no concept of an unset value. */
const ANY_TYPE = "__any";

/**
 * Notification History — browse, search, filter and manage the whole feed.
 *
 * The destination the dropdown's "View all" points at, and the answer to a dropdown
 * that only ever shows the most recent page. It adds no notification source: same
 * endpoint, same rows, same read/unread mutations as the bell. What it adds is reach —
 * cursor paging over the full history, plus the Slice 2 filters the dropdown does not
 * expose. The page title comes from the topbar (PAGE_TITLES), so no in-page heading.
 */
export function NotificationHistoryPage() {
  const router = useRouter();

  const [readState, setReadState] = useState<"all" | "unread">("all");
  const [type, setType] = useState<string>(ANY_TYPE);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");

  // Debounced so typing does not start a cursor walk per keystroke — each distinct
  // term is its own cache entry, and an undebounced input would create one per letter.
  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchInput), 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  const {
    notifications,
    isLoading,
    isError,
    hasNextPage,
    isFetchingNextPage,
    fetchNextPage,
    retry,
  } = useNotificationHistory({
    filter: readState,
    type: type === ANY_TYPE ? null : type,
    search,
  });

  const { data: unreadCount = 0 } = useUnreadCount();
  const markRead = useMarkRead();
  const markUnread = useMarkUnread();
  const markAll = useMarkAllRead();

  // Same behaviour as the dropdown: opening a notification reads it and follows its
  // deep-link. Kept identical so a row means the same thing in both places.
  const handleSelect = (notification: NotificationDto) => {
    if (!notification.read) markRead.mutate(notification.id);
    if (notification.link) router.push(notification.link);
  };

  const isFiltered = readState !== "all" || type !== ANY_TYPE || search.trim() !== "";

  return (
    <div className="flex flex-col gap-4">
      {/* Filter bar */}
      <div className="flex flex-col gap-3 rounded-lg border border-border bg-card p-3 shadow-card sm:flex-row sm:items-center">
        <div className="flex shrink-0 gap-1 rounded-lg bg-muted p-1" role="group" aria-label="Read state">
          {(["all", "unread"] as const).map((value) => (
            <button
              key={value}
              type="button"
              onClick={() => setReadState(value)}
              aria-pressed={readState === value}
              className={cn(
                "rounded-md px-3 py-1.5 text-sm font-medium transition-colors",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                readState === value
                  ? "bg-card text-foreground shadow-sm"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              {value === "all" ? "All" : "Unread"}
              {value === "unread" && unreadCount > 0 && (
                <span className="ml-1.5 text-xs text-muted-foreground">{unreadCount}</span>
              )}
            </button>
          ))}
        </div>

        {/* Base UI hands back `null` when a selection is cleared; the sentinel keeps
            "no type filter" as a real value rather than an absent one. */}
        <Select value={type} onValueChange={(value) => setType(value ?? ANY_TYPE)}>
          <SelectTrigger className="!h-10 w-full sm:w-[200px]" aria-label="Notification type">
            {/* Base UI renders the raw VALUE unless given a formatter, which would put
                the `__any` sentinel on screen. Map back to the option's label. */}
            <SelectValue>
              {(value: string | null) =>
                NOTIFICATION_TYPE_OPTIONS.find((option) => option.value === value)?.label ??
                "All types"
              }
            </SelectValue>
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY_TYPE}>All types</SelectItem>
            {NOTIFICATION_TYPE_OPTIONS.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <SearchInput
          className="min-w-0 flex-1"
          value={searchInput}
          onChange={setSearchInput}
          placeholder="Search notifications…"
          aria-label="Search notifications"
        />

        {unreadCount > 0 && (
          <Button
            variant="outline"
            className="shrink-0"
            onClick={() => markAll.mutate()}
            disabled={markAll.isPending}
          >
            Mark all as read
          </Button>
        )}
      </div>

      {isLoading && (
        <div className="flex flex-col gap-2 rounded-lg border border-border bg-card p-3 shadow-card">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-16 w-full" />
          ))}
        </div>
      )}

      {isError && <ErrorState message="Failed to load notifications" onRetry={retry} />}

      {!isLoading && !isError && notifications.length > 0 && (
        <>
          <div className="overflow-hidden rounded-lg border border-border bg-card shadow-card">
            <ul className="divide-y divide-border-subtle">
              {notifications.map((notification) => (
                <li key={notification.id} className="flex items-start gap-1 p-1">
                  {/* NotificationItem is itself a <button>, so the toggle is a SIBLING
                      rather than nested inside it — a button within a button is invalid
                      and swallows the inner click in some browsers. */}
                  <div className="min-w-0 flex-1">
                    <NotificationItem notification={notification} onSelect={handleSelect} />
                  </div>
                  <button
                    type="button"
                    onClick={() =>
                      notification.read
                        ? markUnread.mutate(notification.id)
                        : markRead.mutate(notification.id)
                    }
                    aria-label={
                      notification.read
                        ? `Mark "${notification.title}" as unread`
                        : `Mark "${notification.title}" as read`
                    }
                    title={notification.read ? "Mark as unread" : "Mark as read"}
                    className={cn(
                      "mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors",
                      "hover:bg-muted hover:text-foreground",
                      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                    )}
                  >
                    {notification.read ? (
                      <RotateCcw className="h-4 w-4" />
                    ) : (
                      <Check className="h-4 w-4" />
                    )}
                  </button>
                </li>
              ))}
            </ul>
          </div>

          {hasNextPage && (
            <div className="flex justify-center">
              <Button
                variant="outline"
                onClick={() => fetchNextPage()}
                disabled={isFetchingNextPage}
              >
                {isFetchingNextPage ? "Loading…" : "Load more"}
              </Button>
            </div>
          )}
        </>
      )}

      {!isLoading && !isError && notifications.length === 0 && (
        <EmptyState
          message={isFiltered ? "No notifications match these filters" : "No notifications yet"}
        />
      )}
    </div>
  );
}
