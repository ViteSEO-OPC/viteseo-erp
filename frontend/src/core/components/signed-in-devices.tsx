"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { endOtherSessions, endSession, fetchSessions } from "@/core/api/auth";
import { notify } from "@/core/lib/notify";
import { Badge, Button, Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/core/ui";

const SESSIONS = ["auth", "sessions"] as const;

/** "Active now", "3 hours ago" -- a recognisable when, not a timestamp to decode. */
function lastActive(iso: string): string {
  const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60_000));
  if (minutes < 2) return "Active now";
  if (minutes < 60) return `${minutes} minutes ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} ${hours === 1 ? "hour" : "hours"} ago`;
  const days = Math.round(hours / 24);
  return `${days} ${days === 1 ? "day" : "days"} ago`;
}

/**
 * Every browser this person is signed in on, and a way to end any of them.
 *
 * Signing in somewhere else does not sign this device out, so this is where someone
 * closes the session they do not recognise -- a lost laptop, a shared PC they forgot.
 * The ended browser is signed out at once through AccessChanged where it holds a
 * socket, and on its next request everywhere else.
 */
export function SignedInDevices() {
  const queryClient = useQueryClient();
  const sessions = useQuery({ queryKey: SESSIONS, queryFn: fetchSessions });
  const settle = () => queryClient.invalidateQueries({ queryKey: SESSIONS });

  const endOne = useMutation({
    mutationFn: endSession,
    onSuccess: () => notify.success("Signed out of that device."),
    onError: () => notify.error("Could not sign that device out. Please try again."),
    onSettled: settle,
  });
  const endOthers = useMutation({
    mutationFn: endOtherSessions,
    onSuccess: () => notify.success("Signed out of every other device."),
    onError: () => notify.error("Could not sign the other devices out. Please try again."),
    onSettled: settle,
  });

  const list = sessions.data ?? [];
  const others = list.filter((s) => !s.current).length;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Signed-in devices</CardTitle>
        <CardDescription>
          Every browser where you are signed in. Sign out of any you don&apos;t recognise. A
          session also ends by itself after two days unused, and after thirty days in any case.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {sessions.isLoading ? (
          <p className="text-sm text-muted-foreground">Loading your devices…</p>
        ) : sessions.isError ? (
          <p className="text-sm text-destructive">Could not load your devices.</p>
        ) : (
          <ul className="divide-y divide-border">
            {list.map((s) => (
              <li key={s.key} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div className="min-w-0">
                  <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                    {s.device}
                    {s.current && <Badge>This device</Badge>}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {s.ip_address ?? "Unknown address"} · {lastActive(s.last_active_at)}
                  </p>
                </div>
                {!s.current && (
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={endOne.isPending}
                    onClick={() => endOne.mutate(s.key)}
                  >
                    Sign out
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
        {others > 1 && (
          <Button
            variant="destructive-outline"
            className="mt-4"
            disabled={endOthers.isPending}
            onClick={() => endOthers.mutate()}
          >
            Sign out of all other devices
          </Button>
        )}
      </CardContent>
    </Card>
  );
}
