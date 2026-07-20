"use client";

import { notify as toast } from "@/core/lib/notify";
import { Button } from "@/components/ui/button";
import { useMyMeetings, useRespondMeeting } from "@/modules/hr/hooks/use-concerns";
import { concernCategoryLabel, type Meeting } from "@/modules/hr/types/concern";

/** UTC ISO → "Tue, Jul 15 • 10:00 AM" in Manila (day boundaries per §5.5b). */
function formatMeetingWhen(iso: string): string {
  const d = new Date(iso);
  const date = d.toLocaleDateString("en-PH", {
    timeZone: "Asia/Manila",
    weekday: "short",
    month: "short",
    day: "numeric",
  });
  const time = d.toLocaleTimeString("en-PH", {
    timeZone: "Asia/Manila",
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  });
  return `${date} • ${time}`;
}

function meetingTitle(m: Meeting): string {
  if (m.agenda) return m.agenda;
  if (m.concern) return `${concernCategoryLabel(m.concern.category)} discussion`;
  return "Meeting with HR";
}

/**
 * Overview "Upcoming Meeting" card. Surfaces the meetings HR scheduled for the
 * signed-in employee (resolved server-side from the token — never a client id),
 * soonest first. The list scrolls when there is more than one so the card keeps
 * its dashboard footprint. Also the home for accepting/declining invites.
 */
export function UpcomingMeetingsCard() {
  const { data: meetings, isLoading } = useMyMeetings();
  const respond = useRespondMeeting();

  // "Upcoming" = still in the future and not already declined; soonest first.
  const upcoming = (meetings ?? [])
    .filter(
      (m) =>
        m.status !== "declined" &&
        new Date(m.scheduled_at).getTime() >= Date.now(),
    )
    .sort((a, b) => +new Date(a.scheduled_at) - +new Date(b.scheduled_at));

  async function handleRespond(m: Meeting, response: "accepted" | "declined") {
    try {
      await respond.mutateAsync({ id: m.id, response });
      toast.success(
        response === "accepted" ? "Meeting accepted" : "Meeting declined",
      );
    } catch {
      toast.error("Could not update your response. Please try again.");
    }
  }

  return (
    <div className="flex flex-col rounded-card border border-border bg-card p-5">
      <div className="flex items-center justify-between">
        <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
          Upcoming Meeting
        </p>
        {upcoming.length > 1 ? (
          <span className="rounded-full bg-brand-subtle px-2 py-0.5 text-caption font-semibold text-brand">
            {upcoming.length}
          </span>
        ) : null}
      </div>

      {isLoading ? (
        <p className="mt-3 text-sm text-muted-foreground">Loading…</p>
      ) : upcoming.length === 0 ? (
        <div className="mt-2 flex flex-1 flex-col justify-center">
          <p className="text-h3 font-bold text-muted-foreground">None scheduled</p>
          <p className="mt-1 text-caption text-muted-foreground">
            You have no upcoming meetings.
          </p>
        </div>
      ) : (
        <ul className="mt-3 max-h-[168px] divide-y divide-border overflow-y-auto pr-1">
          {upcoming.map((m) => (
            <li key={m.id} className="py-3 first:pt-0 last:pb-0">
              <p className="text-h3 font-bold text-foreground">{meetingTitle(m)}</p>
              <p className="mt-0.5 text-caption text-muted-foreground">
                {formatMeetingWhen(m.scheduled_at)}
                {m.location ? ` • ${m.location}` : ""}
              </p>
              <div className="mt-2 flex items-center justify-between gap-2">
                <p className="text-sm font-medium text-foreground">Scheduled by HR</p>
                {m.status === "accepted" ? (
                  <span className="text-caption font-semibold text-brand">
                    Accepted
                  </span>
                ) : (
                  <div className="flex gap-1.5">
                    <Button
                      size="xs"
                      variant="outline"
                      disabled={respond.isPending}
                      onClick={() => handleRespond(m, "declined")}
                    >
                      Decline
                    </Button>
                    <Button
                      size="xs"
                      disabled={respond.isPending}
                      onClick={() => handleRespond(m, "accepted")}
                    >
                      Accept
                    </Button>
                  </div>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
