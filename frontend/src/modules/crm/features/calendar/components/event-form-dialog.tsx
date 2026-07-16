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
import type {
  PlannerEvent,
  EventType,
  PlannerEventStatus,
  PlannerEventPriority,
} from "@/modules/crm/features/calendar/types/calendar-types";

// 'follow-up' is intentionally NOT authored from scratch here — follow-ups are minted only by the
// single FollowUpScheduler (Cold Calling → Scheduled, or the Lead Details "Schedule follow-up"
// Quick Action → POST /leads/{id}/follow-ups). It stays selectable only when EDITING an event that
// already is one (see typeOptions in the component), so no new follow-up gains a second birth path.
const TYPES: EventType[] = ["email", "call", "meeting", "task"];
const STATUSES: PlannerEventStatus[] = ["todo", "scheduled", "in_progress", "completed"];
const PRIORITIES: PlannerEventPriority[] = ["low", "medium", "high"];
const NO_LEAD = "none";

const LABELS: Record<string, string> = {
  "follow-up": "Follow-up",
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
  const [description, setDescription] = useState(event?.description ?? "");

  const busy = create.isPending || update.isPending || remove.isPending;
  const canSave = title.trim().length > 0 && !busy;
  // Offer 'follow-up' only when this event already is one (edit); never for new authoring.
  const typeOptions: EventType[] =
    event?.eventType === "follow-up" ? [...TYPES, "follow-up"] : TYPES;

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

  function handleComplete() {
    if (!event) return;
    update.mutate({ id: event.id, patch: { status: "completed" } }, { onSuccess: onDone });
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
              <Button variant="secondary" size="sm" onClick={handleComplete} disabled={busy}>
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
