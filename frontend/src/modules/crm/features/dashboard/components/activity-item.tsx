import { ActivityIconTile } from "@/modules/crm/features/dashboard/components/activity-icon-tile";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import { cn } from "@/modules/crm/lib/utils";
import type { ActivityItem as ActivityItemType } from "@/modules/crm/features/dashboard/types/dashboard-types";

type Props = {
  item: ActivityItemType;
  // "widget" (default) = Dashboard Recent Activity: bold actor/object, dark text, timestamp
  // stacked below. "page" = full-page Activity Logs (per the reference): uniform-weight soft-slate
  // text, timestamp right-aligned on the same row.
  variant?: "widget" | "page";
};

export function ActivityItem({ item, variant = "widget" }: Props) {
  const isPage = variant === "page";
  // Subject-less events (e.g. auth login/logout) have no object — render "{actor} {action}"
  // without a trailing empty clause.
  const hasObject = item.object !== "";

  const description = isPage ? (
    <p className="min-w-0 text-sm text-secondary-foreground">
      {item.actor} {item.action}
      {hasObject ? ` ${item.object}` : ""}
    </p>
  ) : (
    <p className="min-w-0 text-sm text-foreground">
      <strong className="font-semibold">{item.actor}</strong> {item.action}
      {hasObject ? (
        <>
          {" "}
          <strong className="font-semibold">{item.object}</strong>
        </>
      ) : null}
    </p>
  );

  const timestamp = (
    <time className="shrink-0 text-xs text-muted-foreground">
      {formatActivityTimestamp(item.timestamp)}
    </time>
  );

  return (
    <li
      className={cn(
        "flex gap-3 border-b border-border-subtle px-4 py-3 last:border-b-0",
        isPage ? "items-center" : "items-start",
      )}
    >
      {/* The actor's face when the row has a CRM user behind it, the event-type glyph otherwise.
          Falling back rather than replacing keeps every system row, inbound reply and photo-less
          user looking exactly as it does today. */}
      {item.actorAvatarUrl ? (
        <AvatarInitials src={item.actorAvatarUrl} initials={item.actor} size="sm" />
      ) : (
        <ActivityIconTile type={item.type} />
      )}
      {isPage ? (
        <div className="flex min-w-0 flex-1 items-center justify-between gap-3">
          {description}
          {timestamp}
        </div>
      ) : (
        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
          {description}
          {timestamp}
        </div>
      )}
    </li>
  );
}
