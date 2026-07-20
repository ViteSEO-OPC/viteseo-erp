"use client";

import { useEffect } from "react";
import { useFieldArray, useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { Plus, Trash2 } from "lucide-react";
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
import {
  useCreateBenefitProgram,
  useUpdateBenefitProgram,
} from "@/modules/hr/hooks/use-benefit-programs";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import {
  BENEFIT_CATEGORIES,
  type BenefitProgram,
  type BenefitProgramInput,
} from "@/modules/hr/types/benefits";
import { SimpleSelect } from "@/modules/hr/ui/select";

const schema = z
  .object({
    name: z.string().min(1, "Name is required").max(255),
    category: z.string().min(1, "Pick a category"),
    status: z.string().min(1, "Status is required").max(50),
    footer_label: z.string().max(60).optional(),
    details: z
      .array(
        z.object({
          label: z.string().min(1, "Required").max(100),
          value: z.string().min(1, "Required").max(100),
        }),
      )
      .min(1, "Add at least one detail row"),
    has_progress: z.boolean(),
    progress_percent: z.string().optional(),
    progress_caption: z.string().max(120).optional(),
    employee_ids: z.array(z.number()),
  })
  .superRefine((v, ctx) => {
    if (v.has_progress) {
      const n = Number(v.progress_percent);
      if (v.progress_percent === "" || Number.isNaN(n) || n < 0 || n > 100) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          message: "Enter a percent between 0 and 100",
          path: ["progress_percent"],
        });
      }
    }
  });

type FormValues = z.input<typeof schema>;

const EMPTY: FormValues = {
  name: "",
  category: "hmo",
  status: "Enrolled",
  footer_label: "",
  details: [{ label: "", value: "" }],
  has_progress: false,
  progress_percent: "",
  progress_caption: "",
  employee_ids: [],
};

export function BenefitProgramFormDialog({
  open,
  onOpenChange,
  program,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present = edit mode; absent = create mode. */
  program?: BenefitProgram | null;
}) {
  const isEdit = !!program;
  const createMut = useCreateBenefitProgram();
  const updateMut = useUpdateBenefitProgram();
  const { data: employees } = useEmployees();

  const {
    register,
    handleSubmit,
    control,
    reset,
    watch,
    setValue,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY,
  });

  const { fields, append, remove } = useFieldArray({ control, name: "details" });

  useEffect(() => {
    if (!open) return;
    reset(
      program
        ? {
            name: program.name,
            category: program.category,
            status: program.status,
            footer_label: program.footer_label ?? "",
            details:
              program.details.length > 0
                ? program.details
                : [{ label: "", value: "" }],
            has_progress: !!program.progress,
            progress_percent:
              program.progress != null ? String(program.progress.percent) : "",
            progress_caption: program.progress?.caption ?? "",
            employee_ids: program.employee_ids ?? [],
          }
        : EMPTY,
    );
  }, [open, program, reset]);

  const hasProgress = watch("has_progress");
  const selectedIds = watch("employee_ids");
  const allEmployees = employees ?? [];

  function toggleEmployee(id: number, checked: boolean) {
    const next = checked
      ? [...selectedIds, id]
      : selectedIds.filter((x) => x !== id);
    setValue("employee_ids", next, { shouldDirty: true });
  }

  function toggleAll(checked: boolean) {
    setValue("employee_ids", checked ? allEmployees.map((e) => e.id) : [], {
      shouldDirty: true,
    });
  }

  async function onSubmit(values: FormValues) {
    const input: BenefitProgramInput = {
      name: values.name,
      category: values.category,
      status: values.status,
      footer_label: values.footer_label?.trim() || null,
      details: values.details.map((d) => ({ label: d.label, value: d.value })),
      progress: values.has_progress
        ? {
            percent: Number(values.progress_percent),
            caption: values.progress_caption?.trim() || null,
          }
        : null,
      employee_ids: values.employee_ids,
    };

    try {
      if (program) {
        await updateMut.mutateAsync({ id: program.id, input });
        toast.success("Benefit updated");
      } else {
        await createMut.mutateAsync(input);
        toast.success("Benefit added");
      }
      onOpenChange(false);
    } catch (err) {
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors = err.response.data?.errors ?? {};
        for (const field of Object.keys(serverErrors)) {
          const top = field.split(".")[0] as keyof FormValues;
          setError(top, { message: serverErrors[field][0] });
        }
      } else {
        toast.error("Something went wrong. Please try again.");
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit benefit" : "Add benefit"}</DialogTitle>
          <DialogDescription>
            Author a benefit card and choose which employees see it on their My
            Benefits page.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div className="sm:col-span-2 space-y-1.5">
              <Label htmlFor="bp-name">Name</Label>
              <Input
                id="bp-name"
                placeholder="HealthFirst HMO"
                aria-invalid={!!errors.name}
                {...register("name")}
              />
              {errors.name && (
                <p className="text-xs text-destructive">{errors.name.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="bp-category">Category</Label>
              <Controller
                control={control}
                name="category"
                render={({ field }) => (
                  <SimpleSelect
                    id="bp-category"
                    value={field.value}
                    onValueChange={field.onChange}
                    aria-invalid={!!errors.category}
                    options={BENEFIT_CATEGORIES.map((c) => ({ value: c.value, label: c.label }))}
                  />
                )}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="bp-status">Status badge</Label>
              <Input
                id="bp-status"
                placeholder="Enrolled"
                aria-invalid={!!errors.status}
                {...register("status")}
              />
              {errors.status && (
                <p className="text-xs text-destructive">{errors.status.message}</p>
              )}
            </div>
          </div>

          {/* Detail rows */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label>Detail rows</Label>
              <Button
                type="button"
                variant="ghost"
                size="xs"
                onClick={() => append({ label: "", value: "" })}
              >
                <Plus />
                Add row
              </Button>
            </div>
            <div className="space-y-2">
              {fields.map((field, i) => (
                <div key={field.id} className="flex items-start gap-2">
                  <Input
                    placeholder="Label (e.g. Coverage)"
                    aria-invalid={!!errors.details?.[i]?.label}
                    {...register(`details.${i}.label`)}
                  />
                  <Input
                    placeholder="Value (e.g. Self + 1)"
                    aria-invalid={!!errors.details?.[i]?.value}
                    {...register(`details.${i}.value`)}
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    className="mt-0.5"
                    onClick={() => (fields.length > 1 ? remove(i) : undefined)}
                    disabled={fields.length <= 1}
                  >
                    <Trash2 />
                    <span className="sr-only">Remove row</span>
                  </Button>
                </div>
              ))}
            </div>
            {errors.details?.message && (
              <p className="text-xs text-destructive">{errors.details.message}</p>
            )}
          </div>

          {/* Footer link label */}
          <div className="space-y-1.5">
            <Label htmlFor="bp-footer">Footer link label (optional)</Label>
            <Input
              id="bp-footer"
              placeholder="View Network"
              {...register("footer_label")}
            />
          </div>

          {/* Progress bar */}
          <div className="space-y-2 rounded-lg border border-border p-3">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="size-4 rounded border-input accent-brand"
                {...register("has_progress")}
              />
              Show a usage progress bar
            </label>
            {hasProgress && (
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div className="space-y-1.5">
                  <Label htmlFor="bp-percent">Percent</Label>
                  <Input
                    id="bp-percent"
                    type="number"
                    min="0"
                    max="100"
                    aria-invalid={!!errors.progress_percent}
                    {...register("progress_percent")}
                  />
                  {errors.progress_percent && (
                    <p className="text-xs text-destructive">
                      {errors.progress_percent.message}
                    </p>
                  )}
                </div>
                <div className="sm:col-span-2 space-y-1.5">
                  <Label htmlFor="bp-caption">Caption</Label>
                  <Input
                    id="bp-caption"
                    placeholder="60% of limit reached"
                    {...register("progress_caption")}
                  />
                </div>
              </div>
            )}
          </div>

          {/* Employee assignment */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label>Enrol employees</Label>
              <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <input
                  type="checkbox"
                  className="size-4 rounded border-input accent-brand"
                  checked={
                    allEmployees.length > 0 &&
                    selectedIds.length === allEmployees.length
                  }
                  onChange={(e) => toggleAll(e.target.checked)}
                />
                Select all
              </label>
            </div>
            <div className="max-h-44 space-y-1 overflow-y-auto rounded-lg border border-border p-2">
              {allEmployees.length === 0 ? (
                <p className="px-1 py-2 text-xs text-muted-foreground">
                  No employees to enrol.
                </p>
              ) : (
                allEmployees.map((e) => (
                  <label
                    key={e.id}
                    className="flex items-center gap-2 rounded-md px-1.5 py-1 text-sm hover:bg-muted"
                  >
                    <input
                      type="checkbox"
                      className="size-4 rounded border-input accent-brand"
                      checked={selectedIds.includes(e.id)}
                      onChange={(ev) => toggleEmployee(e.id, ev.target.checked)}
                    />
                    <span className="text-foreground">{e.name}</span>
                    <span className="text-xs text-muted-foreground">· {e.job_title}</span>
                  </label>
                ))
              )}
            </div>
            <p className="text-xs text-muted-foreground">
              {selectedIds.length} of {allEmployees.length} enrolled.
            </p>
          </div>

          <DialogFooter>
            <DialogClose asChild>
              <Button type="button" variant="outline">
                Cancel
              </Button>
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Saving…" : isEdit ? "Save changes" : "Add benefit"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
