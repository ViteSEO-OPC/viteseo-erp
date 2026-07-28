/**
 * Attachment timestamp — "Jul 17, 10:23 AM".
 *
 * Attachments show an absolute date AND time (the mockup's file line), which none of the existing
 * shared formatters produce: formatActivityTimestamp collapses today to a bare time, formatDateLong
 * omits the time, and formatTimestamp is relative. Kept beside the feature rather than added to the
 * shared formatters module, since this exact shape has one consumer.
 */
export function formatAttachmentTimestamp(date: string | null | undefined): string {
  if (!date) return "—";
  const d = new Date(date);
  if (Number.isNaN(d.getTime())) return "—";

  const day = d.toLocaleDateString("en-US", { month: "short", day: "numeric" });
  const time = d.toLocaleTimeString("en-US", {
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  });

  return `${day}, ${time}`;
}
