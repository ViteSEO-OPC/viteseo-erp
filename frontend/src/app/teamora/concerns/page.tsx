"use client";

import { useRef, useState } from "react";
import { AxiosError } from "axios";
import {
  ArrowRight,
  EyeOff,
  FileText,
  ShieldCheck,
  ShieldAlert,
  UploadCloud,
  User,
  X,
} from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PageHeader } from "@/modules/hr/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { StatusBadge } from "@/core/components/status-badge";
import { formatDate } from "@/core/lib/format";
import { useMyConcerns, useCreateConcern } from "@/modules/hr/hooks/use-concerns";
import {
  CONCERN_CATEGORIES,
  concernCategoryLabel,
  type Concern,
} from "@/modules/hr/types/concern";

const selectClass =
  "h-11 w-full rounded-lg border border-input bg-transparent px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const MAX_FILES = 5;
const MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB — matches the backend rule.
const ACCEPT = ".pdf,.png,.jpg,.jpeg";

export default function MyConcernsPage() {
  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Concerns"
        subtitle="Raise a concern, named or anonymous. Your psychological safety is our top priority."
      />
      <div className="mt-8 grid items-start gap-6 lg:grid-cols-2">
        <RaiseConcernCard />
        <MyConcernsCard />
      </div>
    </div>
  );
}

function RaiseConcernCard() {
  const createMut = useCreateConcern();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [category, setCategory] = useState<string>(CONCERN_CATEGORIES[0].value);
  const [reportedPerson, setReportedPerson] = useState("");
  const [description, setDescription] = useState("");
  const [anonymous, setAnonymous] = useState(false);
  const [files, setFiles] = useState<File[]>([]);
  const [dragOver, setDragOver] = useState(false);

  function addFiles(incoming: FileList | null) {
    if (!incoming?.length) return;
    const next: File[] = [...files];
    for (const f of Array.from(incoming)) {
      if (next.length >= MAX_FILES) {
        toast.error(`You can attach up to ${MAX_FILES} files.`);
        break;
      }
      if (f.size > MAX_FILE_BYTES) {
        toast.error(`"${f.name}" is larger than 10MB.`);
        continue;
      }
      next.push(f);
    }
    setFiles(next);
  }

  function removeFile(index: number) {
    setFiles((prev) => prev.filter((_, i) => i !== index));
  }

  async function submit() {
    if (!description.trim()) {
      toast.error("Please describe the concern before submitting.");
      return;
    }

    const form = new FormData();
    form.append("category", category);
    form.append("description", description.trim());
    if (reportedPerson.trim()) form.append("reported_person", reportedPerson.trim());
    form.append("is_anonymous", anonymous ? "1" : "0");
    files.forEach((f) => form.append("attachments[]", f));

    try {
      await createMut.mutateAsync(form);
      toast.success(
        "Concern submitted",
        anonymous
          ? "Your name is hidden from colleagues. HR can see it only to deter abuse, and keeps it private."
          : "HR has been notified.",
      );
      setDescription("");
      setReportedPerson("");
      setAnonymous(false);
      setFiles([]);
      setCategory(CONCERN_CATEGORIES[0].value);
    } catch (err) {
      const msg =
        err instanceof AxiosError && err.response?.status === 422
          ? (Object.values(err.response.data?.errors ?? {})[0] as string[])?.[0]
          : undefined;
      toast.error(msg ?? "Could not submit the concern.");
    }
  }

  return (
    <section className="flex flex-col gap-6 rounded-card border border-border bg-card p-6 shadow-sm sm:p-8">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-h3 font-bold text-foreground">Raise a New Concern</h2>
        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand">
          <ShieldCheck className="size-4" />
          Encrypted &amp; Secure
        </span>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="cat">Category</Label>
          <select
            id="cat"
            className={selectClass}
            value={category}
            onChange={(e) => setCategory(e.target.value)}
          >
            {CONCERN_CATEGORIES.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="reported">Person Being Reported (Optional)</Label>
          <Input
            id="reported"
            value={reportedPerson}
            onChange={(e) => setReportedPerson(e.target.value)}
            placeholder="Name of individual involved (not required)"
          />
          <p className="text-caption text-muted-foreground">
            You are never required to name anyone.
          </p>
        </div>
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="desc">Description</Label>
        <textarea
          id="desc"
          rows={4}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder="Describe the concern in detail…"
          className="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
      </div>

      <div className="space-y-2">
        <label
          onDragOver={(e) => {
            e.preventDefault();
            setDragOver(true);
          }}
          onDragLeave={() => setDragOver(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragOver(false);
            addFiles(e.dataTransfer.files);
          }}
          className={cn(
            "flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-brand bg-brand-subtle px-4 py-6 text-center transition-colors hover:bg-brand-subtle/70",
            dragOver && "bg-brand-subtle/70 ring-2 ring-brand/40",
          )}
        >
          <UploadCloud className="size-6 text-brand" />
          <span className="text-sm font-semibold text-dark-teal">
            Drag &amp; drop supporting documents or click to upload
          </span>
          <span className="text-caption text-muted-foreground">
            PDF, PNG, JPG up to 10MB (metadata will be stripped if anonymous)
          </span>
          <input
            ref={fileInputRef}
            type="file"
            accept={ACCEPT}
            multiple
            className="hidden"
            onChange={(e) => {
              addFiles(e.target.files);
              e.target.value = "";
            }}
          />
        </label>

        {files.length > 0 && (
          <ul className="space-y-1.5">
            {files.map((f, i) => (
              <li
                key={`${f.name}-${i}`}
                className="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
              >
                <FileText className="size-4 shrink-0 text-muted-foreground" />
                <span className="truncate text-foreground">{f.name}</span>
                <span className="ml-auto shrink-0 text-caption text-muted-foreground">
                  {(f.size / 1024 / 1024).toFixed(1)} MB
                </span>
                <button
                  type="button"
                  onClick={() => removeFile(i)}
                  className="shrink-0 rounded p-0.5 text-muted-foreground transition-colors hover:text-danger"
                  aria-label={`Remove ${f.name}`}
                >
                  <X className="size-4" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      <label className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-4">
        <input
          type="checkbox"
          className="mt-0.5 size-5 shrink-0 rounded border-input accent-brand"
          checked={anonymous}
          onChange={(e) => setAnonymous(e.target.checked)}
        />
        <span className="space-y-0.5">
          <span className="flex items-center gap-1.5 text-sm font-bold text-foreground">
            <EyeOff className="size-3.5 text-muted-foreground" />
            Anonymous
          </span>
          <span className="block text-caption text-muted-foreground">
            Your name is hidden from colleagues, and you can track this concern by its
            reference number. HR can still see who you are to deter abuse and is
            responsible for keeping your identity private.
          </span>
        </span>
      </label>

      <div className="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-caption text-muted-foreground">
          All submissions are protected under our Strict Non-Retaliation Policy.
        </p>
        <Button
          onClick={submit}
          disabled={createMut.isPending || !description.trim()}
          className="h-11 shrink-0 gap-2 px-7"
        >
          {createMut.isPending ? "Submitting…" : "Submit Concern"}
          {!createMut.isPending && <ArrowRight className="size-4" />}
        </Button>
      </div>
    </section>
  );
}

/** Backend status, upgraded to "Meeting scheduled" when an invite is live. */
function concernBadge(c: Concern): { status: string; label?: string } {
  if (c.status === "resolved") return { status: "resolved" };
  const hasMeeting = (c.meetings ?? []).some((m) =>
    ["scheduled", "accepted"].includes(m.status),
  );
  if (hasMeeting) return { status: "scheduled", label: "Meeting scheduled" };
  return { status: c.status };
}

function MyConcernsCard() {
  const { data: rows } = useMyConcerns();
  const all = rows ?? [];

  const columns: Column<Concern>[] = [
    {
      header: "Ref",
      cell: (c) => <span className="font-semibold text-foreground">{c.reference_no}</span>,
    },
    {
      header: "Category",
      cell: (c) => (
        <span className="inline-flex items-center gap-1.5">
          {c.is_safety && <ShieldAlert className="size-3.5 text-danger" />}
          {concernCategoryLabel(c.category)}
        </span>
      ),
    },
    {
      header: "Visibility",
      cell: (c) => (
        <span className="inline-flex items-center gap-1.5 text-muted-foreground">
          {c.is_anonymous ? <EyeOff className="size-3.5" /> : <User className="size-3.5" />}
          {c.is_anonymous ? "Anonymous" : "Named"}
        </span>
      ),
    },
    {
      header: "Status",
      cell: (c) => {
        const b = concernBadge(c);
        return <StatusBadge status={b.status} label={b.label} />;
      },
    },
    {
      header: "Submitted",
      align: "right",
      cell: (c) => formatDate(c.submitted_at),
    },
  ];

  return (
    <DataTable
      title="My Concerns"
      columns={columns}
      rows={all}
      rowKey={(c) => c.id}
      // Privacy: search on reference/category/visibility/status only — never identity.
      getSearchText={(c) =>
        [
          c.reference_no,
          concernCategoryLabel(c.category),
          c.is_anonymous ? "anonymous" : "named",
          concernBadge(c).label ?? c.status,
        ].join(" ")
      }
      searchPlaceholder="Search concerns…"
      filterable={false}
      empty="You haven't raised any concerns."
    />
  );
}
