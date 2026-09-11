"use client";

import { useEffect, useState } from "react";
import {
  Check,
  Copy,
  Loader2,
  Mic,
  MicOff,
  Phone,
  PhoneCall,
  PhoneOff,
  Smartphone,
} from "lucide-react";
import { Button, buttonVariants } from "@/modules/crm/components/ui/button";
import { CallOutcomeModal } from "@/modules/crm/features/leads/components/call-outcome-modal";
import { useCallCapability } from "@/modules/crm/features/leads/hooks/use-call-capability";
import {
  useBrowserCall,
  type BrowserCallState,
} from "@/modules/crm/features/leads/hooks/use-browser-call";
import {
  useActiveCall,
  useDiscardCall,
  useStartCall,
} from "@/modules/crm/features/leads/hooks/use-lead-calls";
import { cn } from "@/modules/crm/lib/utils";

const NOTES_TEXTAREA =
  "min-h-[52px] w-full resize-none rounded-lg border border-border bg-background px-3 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";

/**
 * A one-second tick from a fixed start instant, or 0 when there is nothing to count.
 *
 * WHAT IS BEING COUNTED DEPENDS ENTIRELY ON WHAT IS PASSED IN, and the two callers mean different
 * things. For a manual call the start is when the CRM record opened, which is NOT talk time — the
 * call happens on the BD's own phone and the CRM cannot observe it, so the label beside that number
 * says so. For a browser call the start is the provider's `accept` event: the moment Twilio reported
 * the leg answered, which is real talk time.
 */
function useElapsedSeconds(startedAt: number | null): number {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (startedAt === null) return;
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [startedAt]);

  if (startedAt === null) return 0;
  return Math.max(0, Math.floor((now - startedAt) / 1000));
}

function formatTimer(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, "0");
  const seconds = String(totalSeconds % 60).padStart(2, "0");
  return hours > 0 ? `${hours}:${minutes}:${seconds}` : `${minutes}:${seconds}`;
}

/**
 * The Calling Workspace panel on Lead Details.
 *
 * ── Free by default ──
 * Zerem needs no telephony account for calling to be useful. The default mode is CRM-ASSISTED MANUAL
 * calling: the panel shows who to ring and on what number, hands the number to the device's own
 * dialer via `tel:`, and keeps every part the CRM genuinely owns — the call record against the lead,
 * the Planner event, the timeline, the disposition and the history.
 *
 * What it deliberately does NOT do in that mode is claim to know the call. Tapping "Call on Phone"
 * opens the handset's dialer; whether the BD then presses dial, reaches anyone, or talks for ten
 * minutes is invisible to the CRM. So the timer is labelled as CRM elapsed time, and the outcome
 * stays a human judgement.
 *
 * ── Paid browser calling is additive ──
 * When the workspace has a configured Twilio account, `capability.browserCalling` turns on and
 * "Call from Browser" appears ALONGSIDE the phone one. Only then is any telephony code loaded — the
 * SDK is dynamically imported inside the call hook, so a manual workspace downloads none of it.
 *
 * The panel never renders a control the server has not said is available: every branch below reads
 * the capability endpoint, never a client-side guess about configuration.
 */
export function ActiveCallPanel({
  leadId,
  leadName,
  contactName,
}: {
  leadId: string;
  leadName?: string;
  contactName?: string;
}) {
  const { activeCall } = useActiveCall(leadId);
  const { capability, isLoading } = useCallCapability(leadId);
  const startCall = useStartCall(leadId);
  const browserCall = useBrowserCall(leadId);

  // A live browser call outranks the CRM record view: the record is `in_progress` for both, but only
  // one of them has audio to hang up. When the leg ends, the completion webhook concludes the record
  // and this falls back automatically — and if that webhook never arrives, the BD still gets the
  // manual Complete / Discard controls rather than a stuck panel.
  const showLiveCall = browserCall.isActive;

  return (
    // No self-alignment here: the page decides this card's height, not the card. From xl Lead Detail
    // places it beside Internal Notes at its OWN natural height (`xl:items-start` on that row), so the
    // card ends after its content rather than stretching to match a long note history.
    <div className="flex flex-col gap-5 rounded-2xl border border-border bg-card p-6 shadow-card">
      <div className="flex flex-col gap-1">
        <h3 className="text-lg font-bold text-card-foreground">
          {showLiveCall ? "Live Call" : "Call"}
        </h3>
        {/* `contain-inline-size` is what lets `truncate` truncate. From xl this card is a `29fr`
            track, which cannot shrink below its content, and a truncated line's min-content is the
            whole name — so a long business name widened the Call column and squeezed Internal Notes.
            Only the names are contained: the buttons' own width stays the column's floor. */}
        {leadName ? (
          <p className="truncate text-sm font-medium text-card-foreground contain-inline-size">
            {leadName}
          </p>
        ) : null}
        {contactName && contactName !== leadName ? (
          <p className="truncate text-sm text-muted-foreground contain-inline-size">{contactName}</p>
        ) : null}
        <PhoneLine capability={capability} isLoading={isLoading} />
      </div>

      {showLiveCall ? (
        <LiveBrowserCall call={browserCall} />
      ) : activeCall ? (
        <ActiveCallControls key={activeCall.id} leadId={leadId} callId={activeCall.id} />
      ) : (
        <IdleControls
          capability={capability}
          isStarting={startCall.isPending}
          onStart={() => startCall.mutate()}
          onStartBrowser={() => void browserCall.start()}
        />
      )}
    </div>
  );
}

/** The number itself, or a plain explanation of why this lead cannot be called yet. */
function PhoneLine({
  capability,
  isLoading,
}: {
  capability: ReturnType<typeof useCallCapability>["capability"];
  isLoading: boolean;
}) {
  if (isLoading) {
    return <div className="h-5 w-32 animate-pulse rounded bg-muted" aria-hidden />;
  }
  if (!capability) return null;

  const { raw, e164, dialable, reason } = capability.phone;

  if (!dialable) {
    return (
      <p className="text-sm text-muted-foreground">
        {raw ? <span className="font-medium text-foreground">{raw}</span> : null}
        {raw ? " — " : ""}
        {reason}
      </p>
    );
  }

  return (
    <p className="text-sm tabular-nums text-muted-foreground">
      <span className="font-medium text-foreground">{raw}</span>
      {/* The normalized form is shown only when it differs, so the BD can see exactly what a dial
          action will use rather than having to trust that the two agree. */}
      {e164 && e164 !== raw ? <span className="ml-1.5">({e164})</span> : null}
    </p>
  );
}

/**
 * Idle state.
 *
 * "Call from Browser" is offered FIRST when the workspace has it, because it is the one action that
 * both places the call and records it truthfully. "Call on Phone" is always present — it is the free
 * path and the fallback whenever a headset, a network or a Twilio account is not cooperating — and
 * "Log Call" opens the CRM record without dialing anything.
 */
function IdleControls({
  capability,
  isStarting,
  onStart,
  onStartBrowser,
}: {
  capability: ReturnType<typeof useCallCapability>["capability"];
  isStarting: boolean;
  onStart: () => void;
  onStartBrowser: () => void;
}) {
  const dialable = capability?.phone.dialable ?? false;
  const e164 = capability?.phone.e164 ?? null;
  // Server-reported, never inferred. False in every workspace with no provider configured, which is
  // the default — and a browser call needs a number a carrier will accept, so an undialable lead
  // gets no browser control either.
  const browserCalling = (capability?.browserCalling ?? false) && dialable;

  return (
    // `flex-1`: fills whatever height the card has below its header, so the caption's `mt-auto` below
    // has somewhere to push to. Lead Detail sizes this card to its content, so today there is no spare
    // height and this renders as a plain stack; it only matters if the card is ever stretched.
    <div className="flex flex-1 flex-col gap-2.5">
      {browserCalling ? (
        <Button variant="default" className="h-11 w-full rounded-xl" onClick={onStartBrowser}>
          <PhoneCall className="h-4 w-4" aria-hidden />
          Call from Browser
        </Button>
      ) : null}

      {/* A real anchor, not a button with a click handler: `tel:` is the OPERATING SYSTEM's to
          resolve, and only an anchor lets a phone or tablet hand off to its native dialer (and a
          desktop to whatever app is registered, or to nothing). It borrows the Button's styling via
          `buttonVariants` rather than nesting inside one — this Button is a Base UI primitive with no
          `asChild`, so wrapping would render a <button> around an <a>.

          An undialable lead renders no link at all: a disabled-looking anchor is still clickable, and
          handing the OS "tel:N/A" is worse than offering nothing. */}
      {dialable && e164 ? (
        <a
          href={`tel:${e164}`}
          className={cn(
            buttonVariants({ variant: browserCalling ? "outline" : "default" }),
            "h-11 w-full rounded-xl",
          )}
        >
          <Smartphone className="h-4 w-4" aria-hidden />
          Call on Phone
        </a>
      ) : null}

      {dialable && e164 ? <CopyNumberButton value={e164} /> : null}

      <Button
        variant={dialable ? "outline" : "default"}
        className="h-11 w-full rounded-xl"
        onClick={onStart}
        disabled={isStarting}
      >
        {isStarting ? (
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
        ) : (
          <Phone className="h-4 w-4" aria-hidden />
        )}
        Log Call
      </Button>

      {/* Said once, plainly. A BD should never have to guess whether Zerem placed the call.
          `browserCalling` is reported by the backend and is false wherever no provider is configured,
          so this reads as the manual sentence unless and until an admin configures one in
          Settings → Integrations → Calling. */}
      {/* A caption, not another control: a hairline and extra space set it apart from the buttons,
          centring matches their centred labels, and `text-balance` evens out the line lengths so
          the narrow column never leaves a one-word last line. */}
      {/* A footer (`mt-auto`), the same pattern as Quick Actions' Won/Lose row: if the card is ever
          stretched, the spare height opens up ABOVE the caption rather than as an empty block under
          it. At the card's natural height — Lead Detail's layout — it sits directly under the
          buttons. `pt-1.5` is the caption's original `mt-1.5`, kept on the wrapper so that gap is
          the same either way. */}
      <div className="mt-auto pt-1.5">
        <p className="border-t border-border pt-4 text-center text-xs leading-5 text-balance text-muted-foreground">
          {capability?.browserCalling
            ? "“Call from Browser” dials through Zerem and records the real talk time. “Call on Phone” uses your own handset — log the outcome here afterwards."
            : "The call happens on your own phone. Zerem records it against this lead once you log the outcome."}
        </p>
      </div>
    </div>
  );
}

/** Copy-to-clipboard fallback for desktops where `tel:` resolves to nothing. */
function CopyNumberButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) return;
    const id = setTimeout(() => setCopied(false), 2000);
    return () => clearTimeout(id);
  }, [copied]);

  return (
    <Button
      variant="outline"
      className="h-11 w-full rounded-xl"
      onClick={() => {
        // `clipboard` is unavailable on a non-secure origin and can be permission-denied. Failing
        // silently leaves the number on screen to read, which is the same place the BD started.
        void navigator.clipboard?.writeText(value).then(
          () => setCopied(true),
          () => undefined,
        );
      }}
    >
      {copied ? (
        <Check className="h-4 w-4" aria-hidden />
      ) : (
        <Copy className="h-4 w-4" aria-hidden />
      )}
      {copied ? "Copied" : "Copy Number"}
    </Button>
  );
}

/**
 * A call in progress THROUGH THE BROWSER.
 *
 * Every value here is the provider's. The status line is the SDK's own connection state, and the
 * clock starts at the carrier's `accept` — so before anyone answers there is no timer at all rather
 * than a number counting something that has not happened yet.
 *
 * The outcome is not asked for. A carried call reports its own disposition and duration through the
 * completion webhook, so pressing End Call finishes the record without a form; the manual disposition
 * flow stays for calls the CRM did not carry.
 */
function LiveBrowserCall({ call }: { call: ReturnType<typeof useBrowserCall> }) {
  const elapsed = useElapsedSeconds(call.connectedAt);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-baseline justify-between gap-3 rounded-xl bg-muted/40 px-4 py-3">
        <span className="text-3xl font-bold tabular-nums tracking-tight text-card-foreground">
          {/* Talk time keeps counting through a reconnect: the carrier still has the call. */}
          {call.isConnected || call.state === "reconnecting" ? formatTimer(elapsed) : "—:—"}
        </span>
        <span className="text-right text-[11px] leading-tight text-muted-foreground">
          {call.isConnected ? (
            <>
              Talk time,
              <br />
              measured by the carrier
            </>
          ) : (
            liveStatusLabel(call.state)
          )}
        </span>
      </div>

      <div className="grid grid-cols-2 gap-2.5">
        <Button
          variant="outline"
          className="h-11 rounded-xl"
          onClick={call.toggleMute}
          // Muting before the leg is up has nothing to mute.
          disabled={!call.isConnected}
          aria-pressed={call.isMuted}
        >
          {call.isMuted ? (
            <MicOff className="h-4 w-4" aria-hidden />
          ) : (
            <Mic className="h-4 w-4" aria-hidden />
          )}
          {call.isMuted ? "Unmute" : "Mute"}
        </Button>

        <Button
          variant="destructive"
          className="h-11 rounded-xl"
          onClick={call.hangUp}
          disabled={
            call.state === "ending" || call.state === "requesting" || call.state === "finalizing"
          }
        >
          {call.state === "ending" ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
          ) : (
            <PhoneOff className="h-4 w-4" aria-hidden />
          )}
          End Call
        </Button>
      </div>

      <p className="text-xs leading-relaxed text-muted-foreground">
        Zerem is placing this call. The outcome and duration are recorded automatically when it ends.
      </p>
    </div>
  );
}

/** The SDK's connection state, in words. Never optimistic: nothing here says "connected". */
function liveStatusLabel(state: BrowserCallState): string {
  switch (state) {
    case "requesting":
      return "Starting…";
    case "connecting":
      return "Connecting…";
    case "ringing":
      return "Ringing…";
    case "ending":
      return "Ending…";
    case "reconnecting":
      return "Reconnecting…";
    case "finalizing":
      return "Finishing…";
    default:
      return "Waiting…";
  }
}

/**
 * The in-progress controls for a call the CRM did NOT carry: CRM elapsed time, draft notes, and the
 * human disposition. This is the manual path, and it is also where a browser call lands if its
 * completion webhook never arrives — the BD can always conclude a record by hand.
 */
function ActiveCallControls({ leadId, callId }: { leadId: string; callId: string }) {
  const { activeCall } = useActiveCall(leadId);
  const discardCall = useDiscardCall(leadId);
  const [notes, setNotes] = useState("");
  const [outcomeOpen, setOutcomeOpen] = useState(false);
  const startedAt = activeCall?.startedAt ? new Date(activeCall.startedAt).getTime() : null;
  const elapsed = useElapsedSeconds(startedAt);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-baseline justify-between gap-3 rounded-xl bg-muted/40 px-4 py-3">
        <span className={cn("text-3xl font-bold tabular-nums tracking-tight text-card-foreground")}>
          {formatTimer(elapsed)}
        </span>
        {/* Names the quantity honestly. This is how long the CRM record has been open, not how long
            anyone has been talking — the CRM cannot see a call placed on a handset. */}
        <span className="text-right text-[11px] leading-tight text-muted-foreground">
          Logging since you
          <br />
          started this call
        </span>
      </div>

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

      <div className="grid grid-cols-2 gap-2.5">
        <Button
          variant="outline"
          className="h-11 rounded-xl"
          onClick={() => discardCall.mutate(callId)}
          disabled={discardCall.isPending}
        >
          {discardCall.isPending ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
          ) : (
            <PhoneOff className="h-4 w-4" aria-hidden />
          )}
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
