"use client";

import { useState } from "react";
import { Trash2, CheckCircle2 } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/modules/crm/components/ui/dialog";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { Button } from "@/modules/crm/components/ui/button";
import { Input } from "@/modules/crm/components/ui/input";
import { Textarea } from "@/modules/crm/components/ui/textarea";
import { Switch } from "@/modules/crm/components/ui/switch";
import { businessLocalToUtcIso, utcIsoToBusinessLocalInput } from "@/modules/crm/lib/timezone";
import {
  useCreatePlannerEvent,
  useUpdatePlannerEvent,
  useDeletePlannerEvent,
  type PlannerEventDraft,
} from "@/modules/crm/features/calendar/hooks/use-planner-mutations";
import { usePlannerLeads } from "@/modules/crm/features/calendar/hooks/use-planner-leads";
import { CONSULTATION_OUTCOME_META } from "@/modules/crm/features/calendar/lib/event-type-meta";
import type {
  ConsultationOutcome,
  PlannerEvent,
  EventType,
  PlannerEventStatus,
  PlannerEventPriority,
} from "@/modules/crm/features/calendar/types/calendar-types";

// 'follow-up' is intentionally NOT authored from scratch here — follow-ups are minted only by the
// single FollowUpScheduler (Cold Calling → Scheduled, or the Lead Details "Schedule follow-up"
// Quick Action → POST /leads/{id}/follow-ups). It stays selectable only when EDITING an event that
// already is one (see typeOptions in the component), so no new follow-up gains a second birth path.
const TYPES: EventType[] = ["email", "call", "meeting", "task", "consultation"];
const STATUSES: PlannerEventStatus[] = ["todo", "scheduled", "in_progress", "completed"];
const PRIORITIES: PlannerEventPriority[] = ["low", "medium", "high"];
const NO_LEAD = "none";
/** Sentinel for "outcome not recorded yet" — Radix Select cannot hold an empty-string value. */
const NO_OUTCOME = "none";

const LABELS: Record<string, string> = {
  "follow-up": "Follow-up",
  consultation: "Consultation",
  todo: "To Do",
  scheduled: "Scheduled",
  in_progress: "In Progress",
  completed: "Completed",
};
const label = (v: string) => LABELS[v] ?? v.charAt(0).toUpperCase() + v.slice(1);

type EventFormDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present → edit mode; absent → create mode. */
  event?: PlannerEvent | null;
  /** Initial status for a create (e.g. the Board column that triggered "+ Add item"). */
  defaultStatus?: PlannerEventStatus;
};

/**
 * Thin wrapper: the actual form is mounted only while the dialog is open, so its state
 * initializes fresh from `event` on every open (no seeding effect). Create + Edit + Complete +
 * Delete + Reschedule all run through the optimistic mutations, which keep every Planner view in
 * sync via the shared cache.
 */
export function EventFormDialog({ open, onOpenChange, event, defaultStatus }: EventFormDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[480px]">
        {open && (
          <EventForm
            event={event ?? null}
            defaultStatus={defaultStatus}
            onDone={() => onOpenChange(false)}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function EventForm({
  event,
  defaultStatus,
  onDone,
}: {
  event: PlannerEvent | null;
  defaultStatus?: PlannerEventStatus;
  onDone: () => void;
}) {
  const isEdit = Boolean(event);
  const create = useCreatePlannerEvent();
  const update = useUpdatePlannerEvent();
  const remove = useDeletePlannerEvent();
  const { data: leads = [] } = usePlannerLeads();

  const [title, setTitle] = useState(event?.title ?? "");
  const [eventType, setEventType] = useState<EventType>(event?.eventType ?? "task");
  const [status, setStatus] = useState<PlannerEventStatus>(event?.status ?? defaultStatus ?? "todo");
  const [priority, setPriority] = useState<PlannerEventPriority>(event?.priority ?? "medium");
  const [allDay, setAllDay] = useState(event?.allDay ?? false);
  const [startsLocal, setStartsLocal] = useState(
    event?.startsAt ? utcIsoToBusinessLocalInput(event.startsAt) : "",
  );
  const [endsLocal, setEndsLocal] = useState(
    event?.endsAt ? utcIsoToBusinessLocalInput(event.endsAt) : "",
  );
  const [leadId, setLeadId] = useState<string>(event?.leadId ?? NO_LEAD);
  const [outcome, setOutcome] = useState<ConsultationOutcome | null>(event?.outcome ?? null);
  const [description, setDescription] = useState(event?.description ?? "");

  const isConsultation = eventType === "consultation";
  // A consultation must always carry a lead — the KPI counts DISTINCT leads, so an unlinked one could
  // never be counted. Enforced server-side too; mirrored here so the BD sees it before saving.
  const consultationNeedsLead = isConsultation && leadId === NO_LEAD;
  // Completed + no outcome = the "awaiting outcome" state this dialog exists to resolve.
  const outcomeMissing = isConsultation && status === "completed" && !outcome;

  const busy = create.isPending || update.isPending || remove.isPending;
  const canSave = title.trim().length > 0 && !busy && !consultationNeedsLead && !outcomeMissing;
  const canComplete = !busy && (!isConsultation || Boolean(outcome));
  // Authoring offers TYPES only. When EDITING, the event's own type is appended if it is not in that
  // list — which covers 'follow-up' (never authored here, only ever edited) and, equally, any type
  // newer than this build. Without it the select would hold a value absent from its options and a
  // plain Save would silently REWRITE the event's type; preserving it keeps edits non-destructive.
  const typeOptions: EventType[] =
    event && !TYPES.includes(event.eventType) ? [...TYPES, event.eventType] : TYPES;

  function buildDraft(): PlannerEventDraft {
    return {
      title: title.trim(),
      eventType,
      status,
      priority,
      // The datetime-local values are business-timezone wall times → convert to UTC for the API.
      startsAt: startsLocal ? businessLocalToUtcIso(startsLocal) : null,
      endsAt: endsLocal ? businessLocalToUtcIso(endsLocal) : null,
      allDay,
      leadId: leadId === NO_LEAD ? null : leadId,
      description: description.trim() ? description.trim() : null,
      // Consultation-only vocabulary. Sent as null for every other type so switching a consultation
      // to another type clears a stranded outcome instead of tripping the server's 422.
      outcome: isConsultation ? outcome : null,
    };
  }

  function handleSave() {
    const draft = buildDraft();
    const done = { onSuccess: onDone };
    if (isEdit && event) {
      update.mutate({ id: event.id, patch: draft }, done);
    } else {
      create.mutate(draft, done);
    }
  }

  /**
   * Completing a consultation ALWAYS carries its outcome. A consultation with no recorded result is an
   * incomplete business state, not an assumption of attendance — the system never infers one (frozen:
   * a connected call means "we spoke", not "it went well"), so the BD states it explicitly here.
   */
  function handleComplete() {
    if (!event) return;
    if (isConsultation && !outcome) return; // guarded by canComplete; belt and braces
    update.mutate(
      {
        id: event.id,
        patch: isConsultation ? { status: "completed", outcome } : { status: "completed" },
      },
      { onSuccess: onDone },
    );
  }

  function handleDelete() {
    if (!event) return;
    remove.mutate(event.id, { onSuccess: onDone });
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{isEdit ? "Edit event" : "New task"}</DialogTitle>
        <DialogDescription>
          {isEdit
            ? "Update, complete, reschedule, or delete this event."
            : "Schedule a new Planner event."}
        </DialogDescription>
      </DialogHeader>

      <div className="space-y-4 py-2">
        <Field label="Title">
          <Input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="e.g. Discovery call — Bluewave"
            autoFocus
          />
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Type">
            <EnumSelect value={eventType} onChange={(v) => setEventType(v as EventType)} options={typeOptions} />
          </Field>
          <Field label="Status">
            <EnumSelect value={status} onChange={(v) => setStatus(v as PlannerEventStatus)} options={STATUSES} />
          </Field>
          <Field label="Priority">
            <EnumSelect value={priority} onChange={(v) => setPriority(v as PlannerEventPriority)} options={PRIORITIES} />
          </Field>
          <Field label="Related lead">
            <Select value={leadId} onValueChange={(v) => setLeadId(v ?? NO_LEAD)}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NO_LEAD}>None</SelectItem>
                {leads.map((l) => (
                  <SelectItem key={l.id} value={l.id}>
                    {l.business}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
        </div>

        {isConsultation && (
          <div className="space-y-2 rounded-lg border border-border-subtle bg-secondary/40 p-3">
            <Field label="Consultation outcome">
              <Select
                value={outcome ?? NO_OUTCOME}
                onValueChange={(v) =>
                  setOutcome(v === NO_OUTCOME ? null : (v as ConsultationOutcome))
                }
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_OUTCOME}>Not recorded yet</SelectItem>
                  {CONSULTATION_OUTCOME_META.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                      {o.label} — {o.hint}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
            {outcomeMissing ? (
              <p className="text-xs font-medium text-amber-600">
                This consultation is completed but has no recorded outcome, so it is not counted in
                reporting yet. Record what actually happened to close it.
              </p>
            ) : (
              <p className="text-xs text-muted-foreground">
                Only “Successful” and “Not Interested” count as a consultation the lead attended.
              </p>
            )}
          </div>
        )}

        {consultationNeedsLead && (
          <p className="text-xs font-medium text-amber-600">
            A consultation must be linked to a lead — reporting counts consultations per lead.
          </p>
        )}

        <div className="grid grid-cols-2 gap-3">
          <Field label="Starts">
            <Input type="datetime-local" value={startsLocal} onChange={(e) => setStartsLocal(e.target.value)} />
          </Field>
          <Field label="Ends">
            <Input type="datetime-local" value={endsLocal} onChange={(e) => setEndsLocal(e.target.value)} />
          </Field>
        </div>

        <div className="flex items-center justify-between rounded-lg border border-border-subtle px-3 py-2">
          <span className="text-sm font-medium text-foreground">All day</span>
          <Switch checked={allDay} onCheckedChange={setAllDay} />
        </div>

        <Field label="Notes">
          <Textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Optional details…"
            rows={3}
          />
        </Field>
      </div>

      <DialogFooter className="flex-col-reverse gap-2 sm:flex-row sm:justify-between">
        {isEdit ? (
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={handleDelete} disabled={busy}>
              <Trash2 className="h-4 w-4" />
              Delete
            </Button>
            {status !== "completed" && (
              <Button variant="secondary" size="sm" onClick={handleComplete} disabled={!canComplete}>
                <CheckCircle2 className="h-4 w-4" />
                Complete
              </Button>
            )}
          </div>
        ) : (
          <span />
        )}
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={onDone} disabled={busy}>
            Cancel
          </Button>
          <Button variant="default" size="sm" onClick={handleSave} disabled={!canSave}>
            {isEdit ? "Save changes" : "Create"}
          </Button>
        </div>
      </DialogFooter>
    </>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block space-y-1.5">
      <span className="text-sm font-medium text-foreground">{label}</span>
      {children}
    </label>
  );
}

function EnumSelect({
  value,
  onChange,
  options,
}: {
  value: string;
  onChange: (v: string) => void;
  options: string[];
}) {
  return (
    <Select value={value} onValueChange={(v) => v !== null && onChange(v)}>
      <SelectTrigger>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {options.map((o) => (
          <SelectItem key={o} value={o}>
            {label(o)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
