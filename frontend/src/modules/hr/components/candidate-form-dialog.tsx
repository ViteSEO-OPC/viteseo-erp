"use client";

import { useEffect, useRef, useState } from "react";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { UserPlus, FileUp, X, FileText } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import {
  useCreateCandidate,
  useRequisitions,
} from "@/modules/hr/hooks/use-recruitment";
import {
  CANDIDATE_SOURCES,
  LOCATIONS,
  sourceLabel,
} from "@/modules/hr/types/recruitment";
import { SimpleSelect } from "@/modules/hr/ui/select";

const schema = z.object({
  name: z.string().min(1, "Name is required"),
  email: z.string().min(1, "Email is required").email("Enter a valid email"),
  phone: z.string(),
  requisition_id: z.string().min(1, "Select a requisition"),
  source: z.string().min(1),
  expected_salary: z.string(),
  location: z.string(),
  notes: z.string(),
});
type FormValues = z.infer<typeof schema>;

const ACCEPTED = ".pdf,.doc,.docx";
const MAX_BYTES = 5 * 1024 * 1024;

export function CandidateFormDialog({
  open,
  onOpenChange,
  requisitionId,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Pre-selected requisition (the one open on the page). */
  requisitionId: number;
}) {
  const createMut = useCreateCandidate();
  // A picker, so it asks the database for the ACTIVE requisitions rather than filtering a page
  // of all of them — under the old version an active requisition past the first hundred simply
  // could not be chosen for a new candidate.
  const { data: requisitions } = useRequisitions({ page: 1, per_page: 100, status: "active" });
  const activeReqs = requisitions?.items ?? [];

  const [resume, setResume] = useState<File | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const {
    register,
    control,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: "", email: "", phone: "", requisition_id: String(requisitionId),
      source: "referral", expected_salary: "", location: "", notes: "",
    },
  });

  useEffect(() => {
    if (!open) {
      setResume(null);
      setDragOver(false);
      return;
    }
    reset({
      name: "", email: "", phone: "", requisition_id: String(requisitionId),
      source: "referral", expected_salary: "", location: "", notes: "",
    });
  }, [open, requisitionId, reset]);

  function pickFile(file: File | undefined | null) {
    if (!file) return;
    if (file.size > MAX_BYTES) {
      toast.error("Resume is over 5MB.");
      return;
    }
    setResume(file);
  }

  function onDrop(e: React.DragEvent) {
    e.preventDefault();
    setDragOver(false);
    pickFile(e.dataTransfer.files?.[0]);
  }

  function onSubmit(v: FormValues) {
    createMut.mutate(
      {
        requisition_id: Number(v.requisition_id),
        name: v.name,
        email: v.email || null,
        phone: v.phone || null,
        source: v.source,
        expected_salary: v.expected_salary || null,
        location: v.location || null,
        notes: v.notes || null,
        resume,
      },
      {
        onSuccess: () => {
          toast.success("Candidate added", "They're in the table — drag them onto a stage to start the pipeline.");
          onOpenChange(false);
        },
        onError: () => toast.error("Could not add candidate."),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <span className="flex size-8 items-center justify-center rounded-lg bg-brand-subtle text-brand-foreground">
              <UserPlus className="size-4" />
            </span>
            Add candidate
          </DialogTitle>
          <DialogDescription>
            For referrals and sourced candidates. Upload a resume to attach it to the application.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          {/* Resume dropzone — drag & drop or click to upload. */}
          <div>
            <input
              ref={fileInputRef}
              type="file"
              accept={ACCEPTED}
              className="sr-only"
              onChange={(e) => pickFile(e.target.files?.[0])}
            />
            {resume ? (
              <div className="flex items-center gap-3 rounded-lg border border-brand/40 bg-brand-subtle px-4 py-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand text-brand-foreground">
                  <FileText className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-foreground">{resume.name}</p>
                  <p className="text-caption text-muted-foreground">
                    {(resume.size / 1024).toFixed(0)} KB · Ready to upload
                  </p>
                </div>
                <Button
                  type="button"
                  size="icon-sm"
                  variant="ghost"
                  onClick={() => setResume(null)}
                  aria-label="Remove resume"
                >
                  <X />
                </Button>
              </div>
            ) : (
              <div
                onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                onDragLeave={() => setDragOver(false)}
                onDrop={onDrop}
                onClick={() => fileInputRef.current?.click()}
                role="button"
                tabIndex={0}
                onKeyDown={(e) => {
                  if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
                    fileInputRef.current?.click();
                  }
                }}
                className={cn(
                  "flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed bg-muted/30 px-4 py-3 text-caption text-muted-foreground transition-colors hover:bg-muted/50",
                  dragOver ? "border-brand bg-brand-subtle text-foreground" : "border-border",
                )}
              >
                <FileUp className="size-4" />
                Drop resume here, or click to upload · PDF, DOC, DOCX up to 5MB
              </div>
            )}
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div className="space-y-1.5">
              <Label>Full name *</Label>
              <Input placeholder="Jordan Lee" {...register("name")} />
              {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label>Email *</Label>
              <Input type="email" placeholder="jordan.lee@email.com" {...register("email")} />
              {errors.email && <p className="text-xs text-destructive">{errors.email.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label>Phone</Label>
              <Input placeholder="+63 917 555 0148" {...register("phone")} />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label>Applying to (requisition) *</Label>
              <Controller
                control={control}
                name="requisition_id"
                render={({ field }) => (
                  <SimpleSelect
                    value={field.value}
                    onValueChange={field.onChange}
                    placeholder="Select requisition"
                    aria-invalid={!!errors.requisition_id}
                    options={activeReqs.map((r) => ({ value: String(r.id), label: r.title }))}
                  />
                )}
              />
              {errors.requisition_id && <p className="text-xs text-destructive">{errors.requisition_id.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label>Source *</Label>
              <Controller
                control={control}
                name="source"
                render={({ field }) => (
                  <SimpleSelect
                    value={field.value}
                    onValueChange={field.onChange}
                    options={CANDIDATE_SOURCES.map((s) => ({ value: s, label: sourceLabel(s) }))}
                  />
                )}
              />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label>Expected Salary</Label>
              <Input placeholder="₱45,000 - 65,000" {...register("expected_salary")} />
            </div>
            <div className="space-y-1.5">
              <Label>Location</Label>
              <Controller
                control={control}
                name="location"
                render={({ field }) => (
                  <SimpleSelect
                    value={field.value}
                    onValueChange={field.onChange}
                    placeholder="Select location"
                    options={LOCATIONS.map((l) => ({ value: l, label: l }))}
                  />
                )}
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label>Note (optional)</Label>
            <textarea
              rows={3}
              placeholder="Referred by Sam on the platform team"
              className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
              {...register("notes")}
            />
          </div>

          <DialogFooter>
            <DialogClose asChild><Button type="button" variant="outline">Cancel</Button></DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Adding…" : "Add to pipeline"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
