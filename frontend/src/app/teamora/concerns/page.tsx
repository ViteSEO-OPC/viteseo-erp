"use client";

import { useState } from "react";
import { EyeOff, ShieldAlert } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { PageHeader } from "@/core/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { StatusBadge } from "@/core/components/status-badge";
import { formatDate, formatDateTime } from "@/core/lib/format";
import {
  useMyConcerns,
  useCreateConcern,
  useMyMeetings,
  useRespondMeeting,
} from "@/modules/hr/hooks/use-concerns";
import {
  CONCERN_CATEGORIES,
  concernCategoryLabel,
  type Concern,
  type Meeting,
} from "@/modules/hr/types/concern";

const selectClass =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring";

export default function MyConcernsPage() {
  return (
    <div className="p-8">
      <PageHeader
        title="My Concerns"
        subtitle="Raise a concern — named or anonymous — and respond to meeting invites."
      />
      <div className="mt-8 space-y-10">
        <SubmitSection />
        <MeetingsSection />
        <ConcernsSection />
      </div>
    </div>
  );
}

function SubmitSection() {
  const createMut = useCreateConcern();
  const [category, setCategory] = useState("conduct");
  const [description, setDescription] = useState("");
  const [anonymous, setAnonymous] = useState(false);

  async function submit() {
    if (!description.trim()) return;
    try {
      await createMut.mutateAsync({ category, description: description.trim(), is_anonymous: anonymous });
      toast.success("Concern submitted", anonymous ? "Your identity is hidden from HR." : "HR has been notified.");
      setDescription("");
      setAnonymous(false);
    } catch {
      toast.error("Could not submit the concern.");
    }
  }

  return (
    <section className="space-y-3">
      <h2 className="text-sm font-semibold text-foreground">Raise a concern</h2>
      <div className="space-y-3 rounded-card border border-border bg-card p-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="cat">Category</Label>
            <select id="cat" className={selectClass} value={category} onChange={(e) => setCategory(e.target.value)}>
              {CONCERN_CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="desc">Description</Label>
          <textarea
            id="desc"
            rows={4}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Describe what happened…"
            className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
          />
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="size-4 rounded border-input accent-brand"
            checked={anonymous}
            onChange={(e) => setAnonymous(e.target.checked)}
          />
          <span className="inline-flex items-center gap-1.5">
            <EyeOff className="size-3.5 text-muted-foreground" />
            Submit anonymously — HR won&apos;t see your identity
          </span>
        </label>
        <div className="flex justify-end">
          <Button onClick={submit} disabled={createMut.isPending || !description.trim()}>
            {createMut.isPending ? "Submitting…" : "Submit concern"}
          </Button>
        </div>
      </div>
    </section>
  );
}

function MeetingsSection() {
  const { data: meetings } = useMyMeetings();
  const respondMut = useRespondMeeting();
  const invites = (meetings ?? []).filter((m) => m.status !== "cancelled");

  async function respond(m: Meeting, response: "accepted" | "declined") {
    try {
      await respondMut.mutateAsync({ id: m.id, response });
      toast.success(response === "accepted" ? "Invite accepted" : "Invite declined");
    } catch {
      toast.error("Could not respond to the invite.");
    }
  }

  if (invites.length === 0) return null;

  return (
    <section className="space-y-3">
      <h2 className="text-sm font-semibold text-foreground">Meeting invitations</h2>
      <div className="space-y-2">
        {invites.map((m) => (
          <div key={m.id} className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-border bg-card p-4">
            <div>
              <p className="text-sm font-medium text-foreground">
                {formatDateTime(m.scheduled_at)}
                {m.location ? ` · ${m.location}` : ""}
              </p>
              <p className="text-caption text-muted-foreground">
                Re: {m.concern?.reference_no ?? "—"}
                {m.agenda ? ` · ${m.agenda}` : ""}
              </p>
            </div>
            {m.status === "scheduled" ? (
              <div className="flex gap-2">
                <Button size="sm" variant="success" disabled={respondMut.isPending} onClick={() => respond(m, "accepted")}>
                  Accept
                </Button>
                <Button size="sm" variant="outline" disabled={respondMut.isPending} onClick={() => respond(m, "declined")}>
                  Decline
                </Button>
              </div>
            ) : (
              <StatusBadge status={m.status} />
            )}
          </div>
        ))}
      </div>
    </section>
  );
}

function ConcernsSection() {
  const { data: rows } = useMyConcerns();

  const columns: Column<Concern>[] = [
    { header: "Ref", cell: (c) => <span className="font-mono text-xs text-muted-foreground">{c.reference_no}</span> },
    {
      header: "Category",
      cell: (c) => (
        <span className="inline-flex items-center gap-1 text-sm">
          {c.is_safety && <ShieldAlert className="size-3.5 text-danger" />}
          {concernCategoryLabel(c.category)}
        </span>
      ),
    },
    {
      header: "Visibility",
      cell: (c) =>
        c.is_anonymous ? (
          <span className="inline-flex items-center gap-1 text-caption text-muted-foreground">
            <EyeOff className="size-3.5" /> Anonymous
          </span>
        ) : (
          "Named"
        ),
    },
    { header: "Status", cell: (c) => <StatusBadge status={c.status} /> },
    { header: "Submitted", cell: (c) => formatDate(c.submitted_at) },
  ];

  return (
    <section className="space-y-3">
      <h2 className="text-sm font-semibold text-foreground">My concerns</h2>
      <DataTable columns={columns} rows={rows ?? []} rowKey={(c) => c.id} empty="You haven't raised any concerns." />
    </section>
  );
}
