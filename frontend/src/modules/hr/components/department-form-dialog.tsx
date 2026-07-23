"use client";

import { useEffect, useState } from "react";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { Plus, X } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/core/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { MultiCombobox } from "@/modules/hr/ui/multi-combobox";
import { cn } from "@/lib/utils";
import {
  useCreateDepartment,
  useUpdateDepartment,
} from "@/modules/hr/hooks/use-departments";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import type { Department, DepartmentInput } from "@/modules/hr/types/department";

const schema = z.object({
  name: z.string().min(1, "Name is required").max(255),
  code: z.string().min(1, "Code is required").max(50),
  description: z.string().max(1000).optional(),
  head_id: z.string(),
  is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

export function DepartmentFormDialog({
  open,
  onOpenChange,
  department,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present = edit mode; absent = create mode. */
  department?: Department | null;
}) {
  const isEdit = !!department;
  const createMut = useCreateDepartment();
  const updateMut = useUpdateDepartment();
  const { data: employees } = useEmployees();

  // Positions are a dynamic list, so they live in local state rather than RHF.
  const [positions, setPositions] = useState<string[]>([]);
  const [positionDraft, setPositionDraft] = useState("");
  // role name -> assigned employee ids (as strings, for the combobox).
  const [assignments, setAssignments] = useState<Record<string, string[]>>({});

  const employeeOptions = (employees ?? []).map((e) => ({
    value: String(e.id),
    label: e.name,
  }));
  const employeeName = (id: string) =>
    employeeOptions.find((o) => o.value === id)?.label ?? `#${id}`;

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", code: "", description: "", head_id: "none", is_active: true },
  });

  // Refill the form whenever the dialog opens (with the row's values on edit,
  // or blank defaults on create).
  useEffect(() => {
    if (!open) return;
    reset(
      department
        ? {
            name: department.name,
            code: department.code,
            description: department.description ?? "",
            head_id: department.head_id ? String(department.head_id) : "none",
            is_active: department.is_active,
          }
        : { name: "", code: "", description: "", head_id: "none", is_active: true },
    );
    const posNames = department?.positions?.map((p) => p.name) ?? [];
    setPositions(posNames);
    setPositionDraft("");

    // Bucket existing assignments by role (only for roles in the catalog).
    const byRole: Record<string, string[]> = {};
    for (const a of department?.assignments ?? []) {
      if (!posNames.includes(a.role)) continue;
      (byRole[a.role] ??= []).push(String(a.employee_id));
    }
    setAssignments(byRole);
  }, [open, department, reset]);

  function addPosition() {
    const name = positionDraft.trim();
    if (!name) return;
    // De-dupe case-insensitively so "Sales Rep" and "sales rep" don't both land.
    const exists = positions.some((p) => p.toLowerCase() === name.toLowerCase());
    if (!exists) setPositions((prev) => [...prev, name]);
    setPositionDraft("");
  }

  function removePosition(index: number) {
    const role = positions[index];
    setPositions((prev) => prev.filter((_, i) => i !== index));
    setAssignments((prev) => {
      const next = { ...prev };
      delete next[role];
      return next;
    });
  }

  // Set the employees for one role, enforcing one role per employee: anyone
  // picked here is removed from the other roles in this modal.
  function setRoleAssignment(role: string, ids: string[]) {
    setAssignments((prev) => {
      const next: Record<string, string[]> = {};
      for (const [r, list] of Object.entries(prev)) {
        next[r] = r === role ? list : list.filter((id) => !ids.includes(id));
      }
      next[role] = ids;
      return next;
    });
  }

  async function onSubmit(values: FormValues) {
    const input: DepartmentInput = {
      name: values.name,
      code: values.code,
      description: values.description?.trim() ? values.description.trim() : null,
      is_active: values.is_active,
      head_id: values.head_id && values.head_id !== "none" ? Number(values.head_id) : null,
      positions,
      assignments: positions.flatMap((role) =>
        (assignments[role] ?? []).map((id) => ({ employee_id: Number(id), role })),
      ),
    };
    try {
      if (department) {
        await updateMut.mutateAsync({ id: department.id, input });
        toast.success("Department updated");
      } else {
        await createMut.mutateAsync(input);
        toast.success("Department created");
      }
      onOpenChange(false);
    } catch (err) {
      // Surface server-side validation (e.g. duplicate code) on the field.
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors = err.response.data?.errors ?? {};
        for (const field of Object.keys(serverErrors)) {
          setError(field as keyof FormValues, {
            message: serverErrors[field][0],
          });
        }
      } else {
        toast.error("Something went wrong. Please try again.");
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-[480px]">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit Department" : "Add Department"}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? "Update this department's details and roles."
              : "Create a new department for the organization."}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-5" noValidate>
          <div className="space-y-1.5">
            <Label htmlFor="dept-name">Department name</Label>
            <Input
              id="dept-name"
              placeholder="e.g. Engineering"
              aria-invalid={!!errors.name}
              {...register("name")}
            />
            {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="dept-code">Department code</Label>
            <Input
              id="dept-code"
              placeholder="e.g. ENG"
              aria-invalid={!!errors.code}
              {...register("code")}
            />
            {errors.code && <p className="text-xs text-destructive">{errors.code.message}</p>}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="dept-description">
              Description <span className="font-normal text-muted-foreground">(optional)</span>
            </Label>
            <Textarea
              id="dept-description"
              rows={3}
              placeholder="Enter department description..."
              aria-invalid={!!errors.description}
              {...register("description")}
            />
            {errors.description && (
              <p className="text-xs text-destructive">{errors.description.message}</p>
            )}
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label>
                Department head{" "}
                <span className="font-normal text-muted-foreground">(optional)</span>
              </Label>
              <Controller
                control={control}
                name="head_id"
                render={({ field }) => (
                  <SimpleSelect
                    value={field.value || "none"}
                    onValueChange={field.onChange}
                    placeholder="Select head"
                    options={[
                      { value: "none", label: "No head assigned" },
                      ...(employees ?? []).map((e) => ({
                        value: String(e.id),
                        label: e.name,
                      })),
                    ]}
                  />
                )}
              />
            </div>
            <div className="space-y-1.5">
              <Label>Status</Label>
              <Controller
                control={control}
                name="is_active"
                render={({ field }) => (
                  <SimpleSelect
                    value={field.value ? "active" : "inactive"}
                    onValueChange={(v) => field.onChange(v === "active")}
                    options={[
                      { value: "active", label: "Active" },
                      { value: "inactive", label: "Inactive" },
                    ]}
                  />
                )}
              />
            </div>
          </div>

          {/* Roles / positions within this department */}
          <div className="space-y-2">
            <div>
              <Label htmlFor="dept-position">
                Roles / positions{" "}
                <span className="font-normal text-muted-foreground">(optional)</span>
              </Label>
              <p className="text-xs text-muted-foreground">
                The roles in this department (e.g. Business Developer, Account Manager). These
                appear as options when assigning an employee&apos;s role.
              </p>
            </div>

            <div className="flex items-center gap-2">
              <Input
                id="dept-position"
                value={positionDraft}
                onChange={(e) => setPositionDraft(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") {
                    e.preventDefault();
                    addPosition();
                  }
                }}
                placeholder="e.g. Business Developer"
              />
              <Button type="button" variant="outline" size="icon" onClick={addPosition}>
                <Plus className="size-4" />
                <span className="sr-only">Add role</span>
              </Button>
            </div>

            {positions.length > 0 && (
              <div className="space-y-2 pt-1">
                {positions.map((role, i) => {
                  const ids = assignments[role] ?? [];
                  return (
                    <div
                      key={`${role}-${i}`}
                      className="space-y-2 rounded-lg border border-border p-3"
                    >
                      <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-medium text-foreground">{role}</span>
                        <button
                          type="button"
                          onClick={() => removePosition(i)}
                          className="flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        >
                          <X className="size-4" />
                          <span className="sr-only">Remove {role}</span>
                        </button>
                      </div>

                      <MultiCombobox
                        options={employeeOptions}
                        selected={ids}
                        onChange={(v) => setRoleAssignment(role, v)}
                        placeholder="Assign employees…"
                        searchPlaceholder="Search employees…"
                        emptyText="No employees found."
                      />

                      {ids.length > 0 && (
                        <ul className="flex flex-wrap gap-1.5">
                          {ids.map((id) => (
                            <li
                              key={id}
                              className="flex items-center gap-1 rounded-full border border-border bg-muted/50 py-0.5 pl-2.5 pr-1 text-xs text-foreground"
                            >
                              {employeeName(id)}
                              <button
                                type="button"
                                onClick={() =>
                                  setRoleAssignment(role, ids.filter((x) => x !== id))
                                }
                                className="flex size-4 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                              >
                                <X className="size-3" />
                                <span className="sr-only">Unassign {employeeName(id)}</span>
                              </button>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          <DialogFooter>
            <DialogClose asChild>
              <Button type="button" variant="ghost">
                Cancel
              </Button>
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Saving…" : isEdit ? "Save changes" : "Add department"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
