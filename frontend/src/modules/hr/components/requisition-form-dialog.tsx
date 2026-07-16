"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Briefcase } from "lucide-react";
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
import { useDepartments } from "@/modules/hr/hooks/use-departments";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import {
  useCreateRequisition,
  useUpdateRequisition,
} from "@/modules/hr/hooks/use-recruitment";
import {
  REQ_STAGES,
  EMPLOYMENT_TYPES,
  LOCATIONS,
  type Requisition,
} from "@/modules/hr/types/recruitment";

const schema = z.object({
  title: z.string().min(1, "Role title is required"),
  employment_type: z.string(),
  salary_range: z.string(),
  department_id: z.string().min(1, "Select a department"),
  hiring_manager_id: z.string().min(1, "Select a hiring manager"),
  openings: z.string().min(1, "Required"),
  stage: z.string().min(1),
  target_start_date: z.string(),
  location: z.string(),
  job_description: z.string(),
});
type FormValues = z.infer<typeof schema>;

const fieldClass =
  "h-8 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring";
const labelClass = "text-label font-semibold uppercase tracking-wide text-muted-foreground";

const EMPTY: FormValues = {
  title: "", employment_type: "full_time", salary_range: "", department_id: "",
  hiring_manager_id: "", openings: "1", stage: "active", target_start_date: "",
  location: "", job_description: "",
};

export function RequisitionFormDialog({
  open,
  onOpenChange,
  requisition,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present = edit mode. */
  requisition?: Requisition | null;
}) {
  const isEdit = !!requisition;
  const { data: departments } = useDepartments();
  const { data: employees } = useEmployees();
  const createMut = useCreateRequisition();
  const updateMut = useUpdateRequisition();

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

  useEffect(() => {
    if (!open) return;
    reset(
      requisition
        ? {
            title: requisition.title,
            employment_type: requisition.employment_type ?? "full_time",
            salary_range: requisition.salary_range ?? "",
            department_id: requisition.department ? String(requisition.department.id) : "",
            hiring_manager_id: requisition.hiring_manager ? String(requisition.hiring_manager.id) : "",
            openings: String(requisition.openings),
            stage: requisition.stage,
            target_start_date: requisition.target_start_date ?? "",
            location: requisition.location ?? "",
            job_description: requisition.job_description ?? "",
          }
        : EMPTY,
    );
  }, [open, requisition, reset]);

  async function onSubmit(v: FormValues) {
    const input = {
      title: v.title,
      employment_type: v.employment_type || null,
      salary_range: v.salary_range || null,
      department_id: Number(v.department_id),
      hiring_manager_id: v.hiring_manager_id ? Number(v.hiring_manager_id) : null,
      openings: Number(v.openings),
      stage: v.stage,
      target_start_date: v.target_start_date || null,
      location: v.location || null,
      job_description: v.job_description || null,
    };
    try {
      if (requisition) {
        await updateMut.mutateAsync({ id: requisition.id, input });
        toast.success("Requisition updated");
      } else {
        await createMut.mutateAsync(input);
        toast.success("Requisition created");
      }
      onOpenChange(false);
    } catch {
      toast.error("Could not save the requisition.");
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <span className="flex size-8 items-center justify-center rounded-lg bg-brand-subtle text-brand-foreground">
              <Briefcase className="size-4" />
            </span>
            {isEdit ? "Edit requisition" : "Open a requisition"}
          </DialogTitle>
          <DialogDescription>
            Fields marked <span className="font-semibold">*</span> are required. Optional
            fields can be filled after approval.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-5" noValidate>
          {/* Essentials */}
          <div className="space-y-3">
            <p className={labelClass}>Essentials</p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="space-y-1.5">
                <Label>Role / Title *</Label>
                <Input placeholder="e.g. UI/UX Designer" {...register("title")} />
                {errors.title && <p className="text-xs text-destructive">{errors.title.message}</p>}
              </div>
              <div className="space-y-1.5">
                <Label>Employment Type</Label>
                <select className={fieldClass} {...register("employment_type")}>
                  {EMPLOYMENT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
              </div>
              <div className="space-y-1.5">
                <Label>Salary Range</Label>
                <Input placeholder="₱35,000 - 55,000" {...register("salary_range")} />
              </div>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="space-y-1.5">
                <Label>Openings *</Label>
                <Input type="number" min="1" {...register("openings")} />
                {errors.openings && <p className="text-xs text-destructive">{errors.openings.message}</p>}
              </div>
              <div className="space-y-1.5">
                <Label>Hiring Manager *</Label>
                <select className={fieldClass} {...register("hiring_manager_id")}>
                  <option value="">Select manager</option>
                  {(employees ?? []).map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                </select>
                {errors.hiring_manager_id && <p className="text-xs text-destructive">{errors.hiring_manager_id.message}</p>}
              </div>
              <div className="space-y-1.5">
                <Label>Department *</Label>
                <select className={fieldClass} {...register("department_id")}>
                  <option value="">Select department</option>
                  {(departments ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
                {errors.department_id && <p className="text-xs text-destructive">{errors.department_id.message}</p>}
              </div>
            </div>
          </div>

          {/* Details */}
          <div className="space-y-3">
            <p className={labelClass}>Details</p>
            <div className={`grid grid-cols-1 gap-3 ${isEdit ? "sm:grid-cols-3" : "sm:grid-cols-2"}`}>
              <div className="space-y-1.5">
                <Label>Target Start Date</Label>
                <Input type="date" {...register("target_start_date")} />
              </div>
              <div className="space-y-1.5">
                <Label>Location</Label>
                <select className={fieldClass} {...register("location")}>
                  <option value="">Select location</option>
                  {LOCATIONS.map((l) => <option key={l} value={l}>{l}</option>)}
                </select>
              </div>
              {isEdit && (
                <div className="space-y-1.5">
                  <Label>Stage</Label>
                  <select className={fieldClass} {...register("stage")}>
                    {REQ_STAGES.map((s) => <option key={s} value={s}>{s}</option>)}
                  </select>
                </div>
              )}
            </div>
            <div className="space-y-1.5">
              <Label>Job Description</Label>
              <textarea
                rows={4}
                placeholder="Describe the role, responsibilities, and requirements…"
                className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
                {...register("job_description")}
              />
            </div>
          </div>

          <DialogFooter>
            <DialogClose asChild><Button type="button" variant="outline">Cancel</Button></DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Saving…" : isEdit ? "Save changes" : "Open requisition"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
