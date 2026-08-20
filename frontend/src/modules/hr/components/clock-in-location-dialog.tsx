"use client";

import { useRef, useState } from "react";
import { Building2, MapPinned, Camera, ArrowLeft } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { useGpsPrewarm, type GpsState } from "@/modules/hr/hooks/use-gps-prewarm";
import type { LocationFix } from "@/modules/hr/types/attendance";

/**
 * Where are you clocking in from? Shown instead of the plain confirm dialog when the
 * server reports it is enforcing a geofence.
 */
export function ClockInLocationDialog({
  open,
  onOpenChange,
  onOffice,
  onOutside,
  pending,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Clock in at the office, with whatever fix the browser managed to produce. */
  onOffice: (fix: LocationFix | null) => void;
  /** Submit an off-site selfie for HR to review. */
  onOutside: (photo: File, fix: LocationFix | null) => void;
  pending: boolean;
}) {
  return (
    <Dialog open={open} onOpenChange={(o) => !pending && onOpenChange(o)}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto lg:max-w-lg">
        {/*
         * The body is its own component so the GPS pre-warm starts when Radix MOUNTS the
         * content — i.e. the instant the dialog opens — and is torn down on close.
         */}
        <ClockInChoice onOffice={onOffice} onOutside={onOutside} pending={pending} />
      </DialogContent>
    </Dialog>
  );
}

function ClockInChoice({
  onOffice,
  onOutside,
  pending,
}: {
  onOffice: (fix: LocationFix | null) => void;
  onOutside: (photo: File, fix: LocationFix | null) => void;
  pending: boolean;
}) {
  const { state, getFix } = useGpsPrewarm();
  const [step, setStep] = useState<"choose" | "selfie">("choose");

  async function chooseOffice() {
    // Never blocks on the fix being good, or on there being one at all — the server
    // accepts the office network as proof by itself, which is the whole desktop story.
    onOffice(await getFix());
  }

  async function sendSelfie(photo: File) {
    onOutside(photo, await getFix());
  }

  if (step === "selfie") {
    return <SelfieStep onBack={() => setStep("choose")} onSubmit={sendSelfie} pending={pending} />;
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Where are you clocking in from?</DialogTitle>
        <DialogDescription>
          Your company checks where the work day starts. Pick the one that matches where you
          are right now.
        </DialogDescription>
      </DialogHeader>

      <div className="space-y-3">
        <ChoiceCard
          icon={<Building2 className="size-5" />}
          title="I'm at the office"
          description="Clocks you in straight away, once we've confirmed you're on the office network."
          onClick={chooseOffice}
          disabled={pending}
          busy={pending}
        />
        <ChoiceCard
          icon={<MapPinned className="size-5" />}
          title="I'm working somewhere else"
          description="Take a photo of yourself and HR will approve it. Your time in is recorded now — not when they get to it."
          onClick={() => setStep("selfie")}
          disabled={pending}
        />
      </div>

      <GpsNote state={state} />
    </>
  );
}

/** Capture and confirm the selfie. */
function SelfieStep({
  onBack,
  onSubmit,
  pending,
}: {
  onBack: () => void;
  onSubmit: (photo: File) => void;
  pending: boolean;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [photo, setPhoto] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);

  function pick(file: File | undefined) {
    if (!file) return;
    setPhoto(file);
    // Swap the object URL and release the previous one — retaking a few times otherwise
    // leaks a blob per attempt for the life of the page.
    setPreview((old) => {
      if (old) URL.revokeObjectURL(old);
      return URL.createObjectURL(file);
    });
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Take a photo to time in</DialogTitle>
        <DialogDescription>
          HR checks the photo before your time in counts. You&apos;re recorded as starting{" "}
          <strong>now</strong>, so their review never makes you late.
        </DialogDescription>
      </DialogHeader>

      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        capture="user"
        className="sr-only"
        onChange={(e) => pick(e.target.files?.[0])}
      />

      {preview ? (
        // A local blob: URL, not a remote asset — next/image can't optimise it and would
        // only add indirection, so a plain img is the honest element here.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={preview}
          alt="The photo you just took"
          className="max-h-64 w-full rounded-lg border border-border object-contain"
        />
      ) : (
        <button
          type="button"
          onClick={() => inputRef.current?.click()}
          className="flex w-full flex-col items-center gap-2 rounded-lg border border-dashed border-border p-8 text-muted-foreground transition-colors hover:border-brand hover:text-foreground"
        >
          <Camera className="size-7" />
          <span className="text-sm font-medium">Take a photo</span>
          <span className="text-xs">Your camera opens on a phone; on a computer, pick a file.</span>
        </button>
      )}

      <div className="flex flex-wrap justify-between gap-2">
        <Button variant="ghost" onClick={onBack} disabled={pending}>
          <ArrowLeft />
          Back
        </Button>
        <div className="flex gap-2">
          {photo && (
            <Button variant="outline" onClick={() => inputRef.current?.click()} disabled={pending}>
              Retake
            </Button>
          )}
          <Button onClick={() => photo && onSubmit(photo)} disabled={!photo || pending}>
            {pending ? "Sending…" : "Send for approval"}
          </Button>
        </div>
      </div>
    </>
  );
}

function ChoiceCard({
  icon,
  title,
  description,
  onClick,
  disabled,
  busy,
}: {
  icon: React.ReactNode;
  title: string;
  description: string;
  onClick?: () => void;
  disabled?: boolean;
  busy?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        "flex w-full items-start gap-3 rounded-lg border border-border p-4 text-left transition-colors",
        "hover:border-brand hover:bg-muted focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
        "disabled:pointer-events-none disabled:opacity-50",
      )}
    >
      <span className="mt-0.5 shrink-0 text-brand">{icon}</span>
      <span className="min-w-0">
        <span className="block text-sm font-semibold text-foreground">
          {busy ? "Clocking in…" : title}
        </span>
        <span className="mt-0.5 block text-xs text-muted-foreground">{description}</span>
      </span>
    </button>
  );
}

/**
 * What the browser managed to find out, in words that don't alarm anyone. Crucially, none
 * of these states stop the employee clocking in — the office network verifies on its own,
 * which is exactly what happens on a desk PC with no way to locate itself.
 */
function GpsNote({ state }: { state: GpsState }) {
  if (state === "ready") return null;

  const text =
    state === "locating"
      ? "Checking your location…"
      : state === "denied"
        ? "Location access is off, so we'll confirm you by the office network instead. Allow location in your browser if you'd rather be checked that way."
        : "This device can't work out where it is, so we'll confirm you by the office network instead.";

  return <p className="text-xs text-muted-foreground">{text}</p>;
}
