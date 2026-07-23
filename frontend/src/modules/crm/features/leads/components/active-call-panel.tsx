"use client";

import { useEffect, useState } from "react";
import { Loader2, Phone } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { CallOutcomeModal } from "@/modules/crm/features/leads/components/call-outcome-modal";
import {
  useActiveCall,
  useDiscardCall,
  useStartCall,
} from "@/modules/crm/features/leads/hooks/use-lead-calls";

const NOTES_TEXTAREA =
  "min-h-[52px] w-full resize-none rounded-lg border border-border bg-background px-3 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";

/**
 * Live elapsed seconds for an in-progress call. There is ONE authoritative timer: `startedAt → now`
 * from the server-created call, ticking each second. The backend remains the source of truth — nothing
 * here can "pause" a call; only Complete / Discard end it. On a mid-call page refresh the timer resumes
 * from `startedAt`, because the active call is read back from the server, not held in local state.
 */
function useElapsedSeconds(startedAt: string | null): number {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (!startedAt) return;
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [startedAt]);

  if (!startedAt) return 0;
  return Math.max(0, Math.floor((now - new Date(startedAt).getTime()) / 1000));
}

function formatTimer(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, "0");
  const seconds = String(totalSeconds % 60).padStart(2, "0");
  return hours > 0 ? `${hours}:${minutes}:${seconds}` : `${minutes}:${seconds}`;
}

/**
 * Calling Workspace — the Active Call panel on Lead Details. Presentation only: it reads the active
 * call via {@link useActiveCall} and drives the three approved endpoints (start / discard / complete)
 * through hooks. No business logic lives here — the disposition policy, single-active enforcement, and
 * duration are the backend's. The timer is the hero (no competing heading, matching the reference);
 * idle → Start Call; in-progress → live timer + Discard + Complete Call. Content sits in a centered
 * console-width column so the card keeps the reference's proportions at any card width.
 */
export function ActiveCallPanel({ leadId }: { leadId: string }) {
  const { activeCall } = useActiveCall(leadId);
  const startCall = useStartCall(leadId);
  const elapsed = useElapsedSeconds(activeCall?.startedAt ?? null);

  return (
    // h-full + centered content: in the reference's Notes | Call row the two cards share a height, so
    // the timer console centers vertically instead of floating at the top of a stretched card. Content
    // is capped to a comfortable width and centered — the console never widens with the card.
    <div className="flex h-full flex-col rounded-2xl border border-border bg-card p-6 shadow-card">
      <div className="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center gap-6">
        {/* Timer is the hero — no competing heading (matches the reference). Larger on the wide
            tablet/mobile card; steps down on the narrow desktop console so it never overflows. */}
        <div className="flex flex-col items-center gap-1">
          <span className="text-6xl font-bold tabular-nums tracking-tight text-card-foreground xl:text-5xl">
            {formatTimer(elapsed)}
          </span>
          <span className="text-sm font-medium text-muted-foreground">
            {activeCall ? "Call in progress" : "Ready to start"}
          </span>
        </div>

        {activeCall ? (
          // Keyed by the call id: a new call remounts fresh (draft notes reset), and ending the call
          // unmounts these controls — so no effect is needed to reset per-call state.
          <ActiveCallControls key={activeCall.id} leadId={leadId} callId={activeCall.id} />
        ) : (
          <Button
            variant="default"
            className="h-11 w-full rounded-xl"
            onClick={() => startCall.mutate()}
            disabled={startCall.isPending}
          >
            {startCall.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
            ) : (
              <Phone className="h-4 w-4" aria-hidden />
            )}
            Start Call
          </Button>
        )}
      </div>
    </div>
  );
}

/** The in-progress controls: draft notes + Discard / Complete. Mounted only while a call is active. */
function ActiveCallControls({ leadId, callId }: { leadId: string; callId: string }) {
  const discardCall = useDiscardCall(leadId);
  const [notes, setNotes] = useState("");
  const [outcomeOpen, setOutcomeOpen] = useState(false);

  return (
    <div className="flex flex-col gap-4">
      <div>
        <label
          htmlFor="active-call-notes"
          className="mb-2 block text-[11px] font-semibold uppercase tracking-wider text-muted-foreground"
        >
          Notes <span className="font-normal text-muted-foreground/70">optional</span>
        </label>
        <textarea
          id="active-call-notes"
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          placeholder="What was discussed…"
          className={NOTES_TEXTAREA}
        />
      </div>

      {/* Side-by-side Discard | Complete — the short "Complete" label fits the narrow console. */}
      <div className="grid grid-cols-2 gap-2.5">
        <Button
          variant="outline"
          className="h-11 rounded-xl"
          onClick={() => discardCall.mutate(callId)}
          disabled={discardCall.isPending}
        >
          {discardCall.isPending ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> : null}
          Discard
        </Button>
        <Button variant="default" className="h-11 rounded-xl" onClick={() => setOutcomeOpen(true)}>
          Complete
        </Button>
      </div>

      <CallOutcomeModal
        open={outcomeOpen}
        onOpenChange={setOutcomeOpen}
        leadId={leadId}
        callId={callId}
        defaultNotes={notes}
      />
    </div>
  );
}
