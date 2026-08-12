"use client";

import { useId, useMemo, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { X, CalendarPlus, Loader2 } from "lucide-react";
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
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { businessLocalToUtcIso, formatBusiness } from "@/modules/crm/lib/timezone";
import { cn } from "@/modules/crm/lib/utils";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { useCreatePlannerEvent, type PlannerEventDraft } from "@/modules/crm/features/calendar/hooks/use-planner-mutations";
import type { EventType, PlannerEventPriority } from "@/modules/crm/features/calendar/types/calendar-types";

const FIELD =
  "h-10 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";
const FIELD_ERROR = "border-red-400 focus:border-red-400 focus:ring-red-400/30";
const TEXTAREA =
  "min-h-[88px] w-full resize-none rounded-lg border border-border bg-background px-3 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";

// Value-equals-label selects (base-ui SelectValue renders the raw value, matching the Planner/Nearby
// convention). The follow-up is created through the SAME `POST /planner-events` path as every
// Planner event — no second scheduling system, no second creator.
//
// Phase 2 of the PO-approved Model B: "Follow-up Type" is the touchpoint CHANNEL and it is now
// persisted STRUCTURALLY in `event_type`, not flattened to the literal 'follow-up' with the choice
// surviving only as a title prefix. The title keeps its prefix for readability, but no backend logic
// may parse it — `title` is user-editable, so it was never safe evidence.
//
// The category that used to be spelled `event_type = 'follow-up'` is now PlannerEvent::
// FOLLOW_UP_WORK_TYPES ('follow-up' | 'email' | 'call' | 'meeting'), migrated across every consumer
// in Phase 1. That is what makes this routing non-destructive: the rows keep counting.
const TYPES = ["Call", "Email", "Meeting", "Task"] as const;

// The channel → canonical `event_type` map. 'follow-up' remains legal for legacy/generic follow-up
// work (and is still minted by the Cold Calling auto-trigger via FollowUpScheduler); it is simply no
// longer the destination for a BD who explicitly picked a channel here.
// Keyed by the select's literal values (the file's Record<string, …> + `??` idiom, as PRIORITY_VALUE
// below). The fallback is unreachable — the Select only emits a TYPES member — and is deliberately
// the legacy generic bucket, which is still inside FOLLOW_UP_WORK_TYPES, so an impossible value can
// never drop the event out of follow-up reporting.
const EVENT_TYPE: Record<string, EventType> = {
  Call: "call",
  Email: "email",
  Meeting: "meeting",
  Task: "task",
};
const PRIORITIES = ["Low", "Medium", "High"] as const;
const PRIORITY_VALUE: Record<string, PlannerEventPriority> = { Low: "low", Medium: "medium", High: "high" };
const REMINDERS = ["15 minutes before", "30 minutes before", "1 hour before", "1 day before", "No reminder"] as const;
const REMINDER_MINUTES: Record<string, number | null> = {
  "15 minutes before": 15,
  "30 minutes before": 30,
  "1 hour before": 60,
  "1 day before": 1440,
  "No reminder": null,
};

type FollowUpErrors = { date?: string; time?: string; schedule?: string };

/** Validate the schedule fields — called from event handlers only, so reading Date.now() is safe. */
function computeErrors(date: string, time: string): FollowUpErrors {
  const e: FollowUpErrors = {};
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
  leadId: string;
  leadName: string;
};

/**
 * Schedule Follow-up — creates a Planner event for the lead through the SAME shared
 * `POST /planner-events` path (`useCreatePlannerEvent`) as every other Planner consumer, so it
 * appears immediately in the Planner (Calendar/Agenda/Board). There is no Lead-Detail-specific
 * scheduling store or logic. On success it additionally refreshes the lead's Activity Timeline.
 */
export function ScheduleFollowUpModal({ open, onOpenChange, leadId, leadName }: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent showCloseButton={false} className="flex max-h-[90vh] w-full flex-col gap-0 p-0 sm:max-w-[520px]">
        {open && <FollowUpForm leadId={leadId} leadName={leadName} onClose={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function FollowUpForm({ leadId, leadName, onClose }: { leadId: string; leadName: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const create = useCreatePlannerEvent({ successMessage: "Follow-up scheduled — added to your Planner." });

  const [type, setType] = useState<string>(TYPES[0]);
  const [priority, setPriority] = useState<string>("Medium");
  const [date, setDate] = useState("");
  const [time, setTime] = useState("");
  const [reminder, setReminder] = useState<string>("15 minutes before");
  const [notes, setNotes] = useState("");
  const [submitAttempted, setSubmitAttempted] = useState(false);
  const [errors, setErrors] = useState<FollowUpErrors>({});

  const dateErrId = useId();
  const timeErrId = useId();

  // Today in the business timezone — floors the native date picker so past days can't be chosen.
  const todayKey = useMemo(() => formatBusiness(new Date(), "yyyy-MM-dd"), []);

  const show = (key: keyof FollowUpErrors) => errors[key] ?? "";

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
      // Display only — readable in the Planner list, and never parsed by backend logic.
      title: `${type} — ${leadName}`,
      // The BD's channel choice, stored structurally (Model B). Consequences, all intended:
      // Email lands in the Outreach inbox AND becomes auto-completable when the email actually sends
      // (follow-ups-workspace gates that on eventType === 'email'), which is what closes the open
      // "Planner task stays open after delivery" gap for the immediate-send path. Call and Meeting
      // leave the *email* inbox — where a Call follow-up never belonged — and are counted by their
      // own missed-KPI buckets. Task leaves follow-up-work reporting entirely (PO decision 3).
      eventType: EVENT_TYPE[type] ?? "follow-up",
      status: "scheduled",
      priority: PRIORITY_VALUE[priority] ?? "medium",
      startsAt: businessLocalToUtcIso(`${date}T${time}`),
      endsAt: null,
      allDay: false,
      reminderMinutes: REMINDER_MINUTES[reminder] ?? null,
      leadId,
      // BD-initiated from the lead — matches LeadFollowUpController's provenance (distinct from the
      // 'outreach' auto-trigger fired by Cold Calling → Scheduled).
      source: "manual",
      description: notes.trim() ? notes.trim() : null,
    };
    create.mutate(draft, {
      onSuccess: () => {
        // useCreatePlannerEvent invalidates only the shared planner cache; also refresh this lead's
        // detail + timeline (the planner-event.created Activity is logged on the lead) and the logs.
        queryClient.invalidateQueries({ queryKey: queryKeys.leads.detail(leadId) });
        queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
        onClose();
      },
    });
  }

  return (
    <>
      <DialogHeader className="px-6 pb-0 pt-6">
        <div className="flex items-start justify-between gap-4">
          <div className="flex flex-col gap-1">
            <DialogTitle className="text-xl font-bold text-card-foreground">Schedule Follow-up</DialogTitle>
            <DialogDescription>Set the next touchpoint with {leadName}</DialogDescription>
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
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-2 gap-4">
            <FormField label="Follow-up Type">
              <Select value={type} onValueChange={(v) => setType(v ?? type)}>
                <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {TYPES.map((t) => <SelectItem key={t} value={t}>{t}</SelectItem>)}
                </SelectContent>
              </Select>
            </FormField>
            <FormField label="Priority">
              <Select value={priority} onValueChange={(v) => setPriority(v ?? priority)}>
                <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {PRIORITIES.map((p) => <SelectItem key={p} value={p}>{p}</SelectItem>)}
                </SelectContent>
              </Select>
            </FormField>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <FormField label="Date" required>
              <input
                type="date"
                value={date}
                min={todayKey}
                onChange={(e) => setDateField(e.target.value)}
                className={cn(FIELD, show("date") && FIELD_ERROR)}
                aria-label="Follow-up date"
                aria-invalid={show("date") ? true : undefined}
                aria-describedby={show("date") ? dateErrId : undefined}
              />
              {show("date") && (
                <p id={dateErrId} role="alert" className="mt-1 text-xs font-medium text-red-600">{errors.date}</p>
              )}
            </FormField>
            <FormField label="Time" required>
              <input
                type="time"
                value={time}
                onChange={(e) => setTimeField(e.target.value)}
                className={cn(FIELD, (show("time") || show("schedule")) && FIELD_ERROR)}
                aria-label="Follow-up time"
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

          <FormField label="Reminder">
            <Select value={reminder} onValueChange={(v) => setReminder(v ?? reminder)}>
              <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
              <SelectContent>
                {REMINDERS.map((r) => <SelectItem key={r} value={r}>{r}</SelectItem>)}
              </SelectContent>
            </Select>
          </FormField>

          <FormField label="Notes" optional>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Add context for this follow-up…"
              className={TEXTAREA}
              aria-label="Notes"
            />
          </FormField>
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
          Schedule Follow-up
        </Button>
      </DialogFooter>
    </>
  );
}
