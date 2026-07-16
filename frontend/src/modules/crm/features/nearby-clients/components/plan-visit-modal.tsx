"use client";

import { useId, useMemo, useState } from "react";
import { X, CalendarCheck, CalendarPlus, Loader2 } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { businessLocalToUtcIso, formatBusiness } from "@/modules/crm/lib/timezone";
import { cn } from "@/modules/crm/lib/utils";
import { useCreatePlannerEvent, type PlannerEventDraft } from "@/modules/crm/features/calendar/hooks/use-planner-mutations";
import type { NearbyClient } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

const FIELD =
  "h-10 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";
const FIELD_ERROR = "border-red-400 focus:border-red-400 focus:ring-red-400/30";
const TEXTAREA =
  "min-h-[88px] w-full resize-none rounded-lg border border-border bg-background px-3 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";

// Value-equals-label: base-ui SelectValue renders the raw value, so the readable label IS the value
// (matches the Nearby filters + Planner Select convention). The purpose becomes the created event's
// title prefix; the reminder maps to the frozen `reminder_minutes` column.
const PURPOSES = ["Follow-up visit", "Intro meeting", "Proposal presentation", "Site assessment"];
const REMINDERS = ["30 minutes before", "1 hour before", "1 day before", "No reminder"];
const REMINDER_MINUTES: Record<string, number | null> = {
  "30 minutes before": 30,
  "1 hour before": 60,
  "1 day before": 1440,
  "No reminder": null,
};

type VisitErrors = { date?: string; time?: string; schedule?: string };

/**
 * Validate the schedule fields. Called from event handlers only (never during render), so reading
 * the current instant via `Date.now()` is safe. A visit needs both fields set to a future instant.
 */
function computeErrors(date: string, time: string): VisitErrors {
  const e: VisitErrors = {};
  if (!date) e.date = "Please choose a date.";
  if (!time) e.time = "Please choose a time.";
  if (date && time && new Date(businessLocalToUtcIso(`${date}T${time}`)).getTime() < Date.now()) {
    e.schedule = "Choose a date and time in the future.";
  }
  return e;
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  client: NearbyClient | null;
};

/**
 * Plan a Client Visit — the production scheduling flow. Nearby Clients is the first integrated
 * consumer of the Planner platform: a visit is created through the SAME `POST /planner-events`
 * path (`source: "nearby"`, `event_type: "meeting"`) as any Planner event, so it immediately
 * appears in the BD's Calendar, Agenda, Board, and Upcoming rail and is logged to the Activity Log.
 * There is no Nearby-specific scheduling store or logic — the shared `useCreatePlannerEvent`
 * mutation owns the write and the shared-cache refresh.
 */
export function PlanVisitModal({ open, onOpenChange, client }: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent showCloseButton={false} className="flex max-h-[90vh] w-full flex-col gap-0 p-0 sm:max-w-[520px]">
        {open && client && <PlanVisitForm client={client} onClose={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function PlanVisitForm({ client, onClose }: { client: NearbyClient; onClose: () => void }) {
  const create = useCreatePlannerEvent({ successMessage: "Visit scheduled — added to your Planner." });

  const [date, setDate] = useState("");
  const [time, setTime] = useState("");
  const [purpose, setPurpose] = useState(PURPOSES[0]);
  const [reminder, setReminder] = useState("1 hour before");
  const [notes, setNotes] = useState("");
  const [submitAttempted, setSubmitAttempted] = useState(false);
  const [errors, setErrors] = useState<VisitErrors>({});

  const dateErrId = useId();
  const timeErrId = useId();

  // Today in the business timezone — floors the native date picker so past days can't be chosen.
  const todayKey = useMemo(() => formatBusiness(new Date(), "yyyy-MM-dd"), []);

  const show = (key: keyof VisitErrors) => errors[key] ?? "";

  // Live-revalidate once the user has attempted a submit, so errors clear as they fix them.
  // Runs in event handlers only — never during render.
  function setDateField(v: string) {
    setDate(v);
    if (submitAttempted) setErrors(computeErrors(v, time));
  }
  function setTimeField(v: string) {
    setTime(v);
    if (submitAttempted) setErrors(computeErrors(date, v));
  }

  function handleSubmit() {
    setSubmitAttempted(true);
    const found = computeErrors(date, time);
    setErrors(found);
    if (found.date || found.time || found.schedule) return;

    const draft: PlannerEventDraft = {
      title: `${purpose} — ${client.business}`,
      eventType: "meeting",
      status: "scheduled",
      priority: "medium",
      startsAt: businessLocalToUtcIso(`${date}T${time}`),
      endsAt: null,
      allDay: false,
      reminderMinutes: REMINDER_MINUTES[reminder] ?? null,
      source: "nearby",
      leadId: client.id,
      description: notes.trim() ? notes.trim() : null,
    };
    create.mutate(draft, { onSuccess: onClose });
  }

  return (
    <>
      <DialogHeader className="px-6 pb-0 pt-6">
        <div className="flex items-start justify-between gap-4">
          <div className="flex flex-col gap-1">
            <DialogTitle className="text-xl font-bold text-card-foreground">Plan a Client Visit</DialogTitle>
            <DialogDescription>
              Schedules an on-site meeting in your Planner and adds a reminder.
            </DialogDescription>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label="Close"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      </DialogHeader>

      <div className="flex-1 overflow-y-auto px-6 py-4">
        {/* Client header */}
        <div className="flex items-center justify-between gap-3 rounded-xl border border-border bg-muted/40 p-3">
          <div className="flex min-w-0 items-center gap-3">
            <AvatarInitials initials={client.initials} size="sm" />
            <div className="min-w-0">
              <p className="truncate text-sm font-bold text-card-foreground">{client.contact}</p>
              <p className="truncate text-xs text-muted-foreground">
                {[client.role, client.niche, client.area].filter(Boolean).join(" · ")}
              </p>
            </div>
          </div>
          {client.distanceKm !== undefined && (
            <span className="shrink-0 text-xs font-semibold text-teal-700">{client.distanceKm.toFixed(1)} km away</span>
          )}
        </div>

        <div className="mt-4 flex flex-col gap-4">
          <div className="grid grid-cols-2 gap-4">
            <FormField label="Date" required>
              <input
                type="date"
                value={date}
                min={todayKey}
                onChange={(e) => setDateField(e.target.value)}
                className={cn(FIELD, show("date") && FIELD_ERROR)}
                aria-label="Visit date"
                aria-invalid={show("date") ? true : undefined}
                aria-describedby={show("date") ? dateErrId : undefined}
              />
              {show("date") && (
                <p id={dateErrId} role="alert" className="mt-1 text-xs font-medium text-red-600">
                  {errors.date}
                </p>
              )}
            </FormField>
            <FormField label="Time" required>
              <input
                type="time"
                value={time}
                onChange={(e) => setTimeField(e.target.value)}
                className={cn(FIELD, (show("time") || show("schedule")) && FIELD_ERROR)}
                aria-label="Visit time"
                aria-invalid={show("time") || show("schedule") ? true : undefined}
                aria-describedby={show("time") || show("schedule") ? timeErrId : undefined}
              />
              {(show("time") || show("schedule")) && (
                <p id={timeErrId} role="alert" className="mt-1 text-xs font-medium text-red-600">
                  {errors.time || errors.schedule}
                </p>
              )}
            </FormField>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <FormField label="Visit Purpose">
              <Select value={purpose} onValueChange={(v) => setPurpose(v ?? purpose)}>
                <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {PURPOSES.map((p) => <SelectItem key={p} value={p}>{p}</SelectItem>)}
                </SelectContent>
              </Select>
            </FormField>
            <FormField label="Reminder">
              <Select value={reminder} onValueChange={(v) => setReminder(v ?? reminder)}>
                <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {REMINDERS.map((r) => <SelectItem key={r} value={r}>{r}</SelectItem>)}
                </SelectContent>
              </Select>
            </FormField>
          </div>

          <FormField label="Notes / Description" optional>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="What to bring, talking points, parking info…"
              className={TEXTAREA}
              aria-label="Notes"
            />
          </FormField>

          <div className="flex items-start gap-2 rounded-lg bg-muted/50 px-3 py-2.5 text-xs text-muted-foreground">
            <CalendarCheck className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <p>
              Scheduled as a <strong>Meeting</strong> in your Planner — it appears on your Calendar,
              Agenda, and Board, and is recorded in your activity log.
            </p>
          </div>
        </div>
      </div>

      <DialogFooter className="mx-0 mb-0 flex-row items-center justify-end gap-2.5 border-t-0 bg-transparent px-6 pb-6 pt-2">
        <Button variant="ghost" size="sm" onClick={onClose} disabled={create.isPending}>Cancel</Button>
        <Button variant="default" size="sm" onClick={handleSubmit} disabled={create.isPending}>
          {create.isPending ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
          ) : (
            <CalendarPlus className="h-4 w-4" aria-hidden />
          )}
          Schedule Visit
        </Button>
      </DialogFooter>
    </>
  );
}
