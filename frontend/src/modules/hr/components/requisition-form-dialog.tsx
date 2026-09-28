"use client";

import { useEffect } from "react";
import { useForm, useWatch, Controller } from "react-hook-form";
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
  ALLOWANCE_PERIODS,
  LOCATIONS,
  isOjt,
  type Requisition,
} from "@/modules/hr/types/recruitment";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";

const schema = z
  .object({
    title: z.string().min(1, "Role title is required"),
    employment_type: z.string(),
    salary_range: z.string(),
    // OJT quotes an allowance instead of a salary range (see the field swap below).
    allowance_amount: z.string(),
    allowance_period: z.string(),
    department_id: z.string().min(1, "Select a department"),
    hiring_manager_id: z.string().min(1, "Select a hiring manager"),
    openings: z.string().min(1, "Required"),
    stage: z.string().min(1),
    target_start_date: z.string(),
    location: z.string(),
    job_description: z.string(),
  })
  .superRefine((v, ctx) => {
    // The allowance is optional, like the salary range it replaces — but a typed one
    // has to be a real figure, since it is stored as a number rather than free text.
    if (!isOjt(v.employment_type) || v.allowance_amount === "") return;

    const amount = Number(v.allowance_amount);
    if (!Number.isFinite(amount) || amount < 0) {
      ctx.addIssue({
        code: "custom",
        path: ["allowance_amount"],
        message: "Enter a valid amount",
      });
    }
  });
type FormValues = z.infer<typeof schema>;

const labelClass = "text-label font-semibold uppercase tracking-wide text-muted-foreground";

const EMPTY: FormValues = {
  title: "", employment_type: "full_time", salary_range: "",
  allowance_amount: "", allowance_period: "per_day", department_id: "",
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
    control,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

  // An OJT pays an allowance, not a salary — the compensation field swaps with the type.
  const isOjtRole = isOjt(useWatch({ control, name: "employment_type" }));

  useEffect(() => {
    if (!open) return;
    reset(
      requisition
        ? {
            title: requisition.title,
            employment_type: requisition.employment_type ?? "full_time",
            salary_range: requisition.salary_range ?? "",
            allowance_amount:
              requisition.allowance_amount != null ? String(requisition.allowance_amount) : "",
            allowance_period: requisition.allowance_period ?? "per_day",
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
    const ojt = isOjt(v.employment_type);
    const input = {
      title: v.title,
      employment_type: v.employment_type || null,
      // Only the side the employment type actually quotes is sent; the server clears
      // the other, so a role switched between the two never keeps a stale figure.
      salary_range: ojt ? null : v.salary_range || null,
      allowance_amount: ojt && v.allowance_amount !== "" ? Number(v.allowance_amount) : null,
      allowance_period: ojt && v.allowance_amount !== "" ? v.allowance_period : null,
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
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
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
                <Controller
                  control={control}
                  name="employment_type"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      options={EMPLOYMENT_TYPES.map((t) => ({ value: t.value, label: t.label }))}
                    />
                  )}
                />
              </div>
              {isOjtRole ? (
                <div className="space-y-1.5">
                  <Label>Allowance</Label>
                  {/*
                    * Stacked rather than side by side: this cell is a third of a 32rem
                    * dialog, and an amount sharing that width with the period select had
                    * room for about two digits.
                    */}
                  <Input
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="650.00"
                    aria-invalid={!!errors.allowance_amount}
                    {...register("allowance_amount")}
                  />
                  <Controller
                    control={control}
                    name="allowance_period"
                    render={({ field }) => (
                      <SimpleSelect
                        value={field.value}
                        onValueChange={field.onChange}
                        aria-label="Allowance period"
                        options={ALLOWANCE_PERIODS.map((p) => ({ value: p.value, label: p.label }))}
                      />
                    )}
                  />
                  {errors.allowance_amount && (
                    <p className="text-xs text-destructive">{errors.allowance_amount.message}</p>
                  )}
                </div>
              ) : (
                <div className="space-y-1.5">
                  <Label>Salary Range</Label>
                  <Input placeholder="₱35,000 - 55,000" {...register("salary_range")} />
                </div>
              )}
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="space-y-1.5">
                <Label>Openings *</Label>
                <Input type="number" min="1" {...register("openings")} />
                {errors.openings && <p className="text-xs text-destructive">{errors.openings.message}</p>}
              </div>
              <div className="space-y-1.5">
                <Label>Hiring Manager *</Label>
                <Controller
                  control={control}
                  name="hiring_manager_id"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      placeholder="Select manager"
                      aria-invalid={!!errors.hiring_manager_id}
                      options={(employees ?? []).map((e) => ({ value: String(e.id), label: e.name }))}
                    />
                  )}
                />
                {errors.hiring_manager_id && <p className="text-xs text-destructive">{errors.hiring_manager_id.message}</p>}
              </div>
              <div className="space-y-1.5">
                <Label>Department *</Label>
                <Controller
                  control={control}
                  name="department_id"
                  render={({ field }) => (
                    <SimpleSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      placeholder="Select department"
                      aria-invalid={!!errors.department_id}
                      options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
                    />
                  )}
                />
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
                <Controller
                  control={control}
                  name="target_start_date"
                  render={({ field }) => (
                    <DatePicker value={field.value} onChange={field.onChange} />
                  )}
                />
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
              {isEdit && (
                <div className="space-y-1.5">
                  <Label>Stage</Label>
                  <Controller
                    control={control}
                    name="stage"
                    render={({ field }) => (
                      <SimpleSelect
                        value={field.value}
                        onValueChange={field.onChange}
                        options={REQ_STAGES.map((s) => ({ value: s, label: s }))}
                      />
                    )}
                  />
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
