"use client";

import { useState } from "react";
import { MapPin, MapPinOff } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { formatTimeManila, formatDate } from "@/core/lib/format";
import { SimpleSelect } from "@/modules/hr/ui/select";
import {
  useRemoteCheckins,
  useApproveRemoteCheckin,
  useRejectRemoteCheckin,
} from "@/modules/hr/hooks/use-remote-checkins";
import type { RemoteCheckin } from "@/modules/hr/types/attendance";

/** Off-site time-ins waiting on HR, above the day's roster. */
export function FieldTimeInQueue() {
  const [status, setStatus] = useState("pending");
  const { data: rows, isLoading } = useRemoteCheckins(status);
  const [reviewing, setReviewing] = useState<RemoteCheckin | null>(null);

  const items = rows ?? [];

  // Nothing pending and no filter applied = nothing worth taking up space for.
  if (status === "pending" && !isLoading && items.length === 0) return null;

  return (
    <div className="rounded-card border border-border bg-card p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-h3 font-bold text-foreground">Field time-ins</h2>
          <p className="text-sm text-muted-foreground">
            Staff who timed in away from the office. Open one to see the photo before you
            decide — they are already working, and are not on the clock until you approve.
          </p>
        </div>
        <SimpleSelect
          className="h-9 w-40"
          value={status}
          onValueChange={setStatus}
          options={[
            { value: "pending", label: "Awaiting review" },
            { value: "approved", label: "Approved" },
            { value: "rejected", label: "Rejected" },
            { value: "all", label: "All" },
          ]}
        />
      </div>

      <div className="mt-4 space-y-2">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : items.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            Nothing here.
          </p>
        ) : (
          items.map((row) => (
            <button
              key={row.id}
              type="button"
              onClick={() => setReviewing(row)}
              className="flex w-full flex-wrap items-center justify-between gap-3 rounded-lg border border-border px-3.5 py-3 text-left transition-colors hover:bg-muted/40"
            >
              <div className="flex min-w-0 items-center gap-3">
                <span className="shrink-0 rounded-md bg-info-subtle px-2 py-1 text-caption font-bold text-info">
                  Time-in
                </span>
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-foreground">
                    {row.employee.name ?? "—"}
                  </p>
                  <p className="truncate text-caption text-muted-foreground">
                    {formatDate(row.submitted_at)} · {formatTimeManila(row.submitted_at)}
                    {row.employee.department ? ` · ${row.employee.department}` : ""}
                  </p>
                </div>
              </div>
              <span className="flex shrink-0 items-center gap-1.5 text-caption text-muted-foreground">
                {row.has_location ? (
                  <>
                    <MapPin className="size-3.5" /> Location attached
                  </>
                ) : (
                  <>
                    <MapPinOff className="size-3.5" /> No location
                  </>
                )}
              </span>
            </button>
          ))
        )}
      </div>

      <ReviewCheckinDialog
        checkin={reviewing}
        onClose={() => setReviewing(null)}
      />
    </div>
  );
}

function ReviewCheckinDialog({
  checkin,
  onClose,
}: {
  checkin: RemoteCheckin | null;
  onClose: () => void;
}) {
  const approve = useApproveRemoteCheckin();
  const reject = useRejectRemoteCheckin();
  const [note, setNote] = useState("");
  const [rejecting, setRejecting] = useState(false);

  const pending = approve.isPending || reject.isPending;
  const decided = checkin != null && checkin.status !== "pending";

  function close() {
    setNote("");
    setRejecting(false);
    onClose();
  }

  async function onApprove() {
    if (!checkin) return;
    try {
      await approve.mutateAsync(checkin.id);
      toast.success("Approved", "Their time in was recorded at the moment they submitted it.");
      close();
    } catch {
      toast.error("Could not approve this time in.");
    }
  }

  async function onReject() {
    if (!checkin) return;
    if (!note.trim()) {
      toast.error("Add a reason so they know what to fix.");
      return;
    }
    try {
      await reject.mutateAsync({ id: checkin.id, note: note.trim() });
      toast.error("Declined", "They've been told why, and can send another photo.");
      close();
    } catch {
      toast.error("Could not decline this time in.");
    }
  }

  return (
    <Dialog open={checkin !== null} onOpenChange={(o) => !o && !pending && close()}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto lg:max-w-xl">
        {checkin && (
          <>
            <DialogHeader>
              <DialogTitle>{checkin.employee.name ?? "Field time-in"}</DialogTitle>
              <DialogDescription>
                Submitted {formatDate(checkin.submitted_at)} at{" "}
                {formatTimeManila(checkin.submitted_at)} — approving records their time in at
                that moment, not now.
              </DialogDescription>
            </DialogHeader>

            {/* eslint-disable-next-line @next/next/no-img-element -- served from the API's
                /storage disk, which next/image isn't configured for. */}
            <img
              src={checkin.photo_url}
              alt={`Time-in photo from ${checkin.employee.name ?? "this employee"}`}
              className="max-h-[45svh] w-full rounded-lg border border-border object-contain"
            />

            <dl className="grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt className="text-caption text-muted-foreground">Location</dt>
                <dd className="text-foreground">
                  {checkin.has_location ? (
                    <a
                      className="text-brand-foreground underline underline-offset-2"
                      href={`https://www.openstreetmap.org/?mlat=${checkin.lat}&mlon=${checkin.lng}#map=17/${checkin.lat}/${checkin.lng}`}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {checkin.lat?.toFixed(5)}, {checkin.lng?.toFixed(5)}
                    </a>
                  ) : (
                    "Not shared — the photo is the evidence."
                  )}
                </dd>
              </div>
              <div>
                <dt className="text-caption text-muted-foreground">Status</dt>
                <dd className="text-foreground capitalize">
                  {checkin.status}
                  {checkin.reviewed_by ? ` by ${checkin.reviewed_by}` : ""}
                </dd>
              </div>
            </dl>

            {checkin.rejection_note && (
              <p className="rounded-lg border border-border bg-muted/40 p-3 text-sm text-foreground">
                <span className="text-caption text-muted-foreground">Reason given: </span>
                {checkin.rejection_note}
              </p>
            )}

            {decided ? (
              <div className="flex justify-end">
                <Button variant="outline" onClick={close}>
                  Close
                </Button>
              </div>
            ) : rejecting ? (
              <div className="space-y-3">
                <div className="space-y-1.5">
                  <Label htmlFor="rc-note">Why are you declining?</Label>
                  <textarea
                    id="rc-note"
                    rows={3}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder="e.g. The photo is too dark to identify you."
                    className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                  />
                  <p className="text-xs text-muted-foreground">
                    They&apos;ll see this, and can send another photo straight away.
                  </p>
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                  <Button variant="ghost" onClick={() => setRejecting(false)} disabled={pending}>
                    Back
                  </Button>
                  <Button
                    variant="outline"
                    className="border-danger text-danger hover:bg-danger/10 hover:text-danger"
                    onClick={onReject}
                    disabled={pending}
                  >
                    {pending ? "Declining…" : "Decline"}
                  </Button>
                </div>
              </div>
            ) : (
              <div className="flex flex-wrap justify-end gap-2">
                <Button
                  variant="outline"
                  className="border-danger text-danger hover:bg-danger/10 hover:text-danger"
                  onClick={() => setRejecting(true)}
                  disabled={pending}
                >
                  Decline
                </Button>
                <Button variant="success" onClick={onApprove} disabled={pending}>
                  {pending ? "Approving…" : "Approve time in"}
                </Button>
              </div>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
