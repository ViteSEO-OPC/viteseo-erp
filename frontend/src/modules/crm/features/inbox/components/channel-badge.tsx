import { Camera, MessageCircle, MessagesSquare } from "lucide-react";

import { channelLabel } from "@/modules/crm/features/inbox/types/inbox-types";

/**
 * The channel marker.
 *
 * It carries its own icon AND its name in text. That redundancy is deliberate: the Meta App Review
 * screencast has to make it unmistakable which platform a conversation arrived from, and a colour
 * alone would prove nothing to a reviewer watching a recording — nor to anyone who cannot
 * distinguish the two brand colours.
 */
export function ChannelBadge({ channel, className = "" }: { channel: string; className?: string }) {
    // This lucide version ships no brand glyphs, so Camera stands in for Instagram. The badge
    // always renders the channel NAME beside it, so nothing depends on the icon being recognised.
    const Icon = channel === "instagram_dm" ? Camera : channel === "messenger" ? MessageCircle : MessagesSquare;

    const tone =
        channel === "instagram_dm"
            ? "bg-pink-50 text-pink-700 dark:bg-pink-950/40 dark:text-pink-300"
            : channel === "messenger"
              ? "bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"
              : "bg-muted text-muted-foreground";

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${tone} ${className}`}
        >
            <Icon aria-hidden className="size-3.5" />
            {channelLabel(channel)}
        </span>
    );
}
